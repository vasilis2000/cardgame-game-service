#!/usr/bin/env php
<?php
declare(strict_types=1);


$projectRoot = realpath(__DIR__ . '/..');
if ($projectRoot === false) {
    fwrite(STDERR, "❌ Unable to determine project root.\n");
    exit(1);
}

$autoloadPath = $projectRoot . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    fwrite(STDERR, "❌ Composer autoloader not found at $autoloadPath\n");
    exit(1);
}
require_once $autoloadPath;


$envFile = $projectRoot . '/.env';
if (file_exists($envFile)) {
    try {
        $dotenv = \Dotenv\Dotenv::createImmutable($projectRoot);
        $dotenv->load();
        echo "✓ .env loaded from $projectRoot\n";
    } catch (\Exception $e) {
        fwrite(STDERR, "⚠️ Failed to load .env: " . $e->getMessage() . "\n");
    }
} else {
    fwrite(STDERR, "⚠️ No .env file found – relying on existing environment variables.\n");
}
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Exception\AMQPIOException;
$host = getenv('RABBITMQ_HOST') ?: 'localhost';
$port = (int)(getenv('RABBITMQ_PORT') ?: 5673);
$user = getenv('RABBITMQ_USER') ?: 'guest';
$pass = getenv('RABBITMQ_PASS') ?: 'guest';
$FinishGameUrl = 'http://host.docker.internal:8082/room/finish';

function connectWithRetry($host, $port, $user, $pass, $maxAttempts = 30, $sleep = 3) {
    for ($i = 1; $i <= $maxAttempts; $i++) {
        try {
            echo " [*] Attempt $i: Connecting to RabbitMQ at $host:$port...\n";
            $connection = new AMQPStreamConnection($host, $port, $user, $pass);
            echo " [✓] Connected to RabbitMQ\n";
            return $connection;
        } catch (AMQPIOException $e) {
            echo " [!] Connection failed: " . $e->getMessage() . "\n";
            if ($i === $maxAttempts) {
                throw $e;
            }
            sleep($sleep);
        }
    }
}

function callFinishGameEndpoint($room_id, $winner) {
    global $FinishGameUrl;
    $payload = json_encode(['room_id' => $room_id, 'winner' => $winner]);

    $ch = curl_init($FinishGameUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        echo " [✗] cURL error: $error\n";
        return false;
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        echo " [✓] Game Finish triggered successfully (HTTP $httpCode)\n";
        return true;
    } else {
        echo " [✗] Game Finish failed with HTTP $httpCode, response: $response\n";
        return false;
    }
}

function consume() {
    global $host, $port, $user, $pass;

    try {
        $connection = connectWithRetry($host, $port, $user, $pass);
        $channel = $connection->channel();
        $channel->queue_declare('finish_game_queue', false, true, false, false);

        echo " [*] Waiting for Finish game messages. To exit press CTRL+C\n";

        $callback = function (AMQPMessage $msg) {
            $data = json_decode($msg->body, true);
            $room_id = $data['room_id'] ?? null;  
            $winner = $data['winner'] ?? null;

            if (!$room_id) {
                echo " [x] Invalid message: missing room_id, rejecting\n";
                $msg->nack(false, false); 
                return;
            }

            echo " [x] Received Finish game request for room $room_id\n";

            $success = callFinishGameEndpoint($room_id, $winner);

            if ($success) {
                $msg->ack();
                echo " [✓] Message acknowledged\n";
            } else {
                $msg->nack(false, true);
                echo " [⚠] Message nacked and requeued\n";
            }
        };

        $channel->basic_qos(null, 1, null);
        $channel->basic_consume('game_finish_queue', '', false, false, false, false, $callback);

        while ($channel->is_consuming()) {
            try {
                $channel->wait();
            } catch (AMQPIOException $e) {
                echo " [!] Connection lost, reconnecting...\n";
                break;
            }
        }

        $channel->close();
        $connection->close();

    } catch (Exception $e) {
        echo " [!] Fatal error: " . $e->getMessage() . "\n";
        sleep(5);
        consume(); 
    }
}

consume();