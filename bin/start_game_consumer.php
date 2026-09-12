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
    fwrite(STDERR, "⚠️ No .env file found – relying on environment variables.\n");
}

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Exception\AMQPIOException;
use App\Repositories\GameRepository;
use App\Utilities\RabbitMQPublisher;
use App\Services\GameService;

$host = getenv('RABBITMQ_HOST') ?: 'localhost';
$port = (int)(getenv('RABBITMQ_PORT') ?: 5672);
$user = getenv('RABBITMQ_USER') ?: 'guest';
$pass = getenv('RABBITMQ_PASS') ?: 'guest';
$startQueue = 'start_game_queue';

$maxReconnectAttempts = 30;
$reconnectSleep       = 3;

function connectWithRetry(string $host, int $port, string $user, string $pass, int $maxAttempts, int $sleep): AMQPStreamConnection
{
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
    throw new \RuntimeException('Unable to connect to RabbitMQ after maximum attempts.');
}

$repo        = new GameRepository();
$publisher   = new RabbitMQPublisher();
$gameService = new GameService($repo, $publisher);

while (true) {
    try {
        $connection = connectWithRetry($host, $port, $user, $pass, $maxReconnectAttempts, $reconnectSleep);
        $channel    = $connection->channel();
        $channel->queue_declare($startQueue, false, true, false, false);

        echo " [*] Game Service consumer ready. Waiting for start game messages...\n";

        $callback = function (AMQPMessage $msg) use ($gameService) {
            $data = json_decode($msg->body, true);

            if (!isset($data['roomid'], $data['players']) || !is_array($data['players'])) {
                echo " [x] Invalid message: missing roomid or players, dropping\n";
                $msg->nack(false, false);
                return;
            }

            $roomId  = (string)$data['roomid'];
            $players = $data['players'];

            echo " [x] Received start game request for room $roomId\n";

            try {
                $gameService->startGame($players, $roomId);
                $msg->ack();
                echo " [✓] Game started for room $roomId (message acked)\n";
            } catch (\Throwable $e) {
                echo " [✗] Failed to start game: " . $e->getMessage() . "\n";
                $msg->nack(false, true);
            }
        };

        $channel->basic_qos(null, 1, null);
        $channel->basic_consume($startQueue, '', false, false, false, false, $callback);

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
    } catch (\Exception $e) {
        echo " [!] Fatal error: " . $e->getMessage() . "\n";
        sleep($reconnectSleep);
    }
}