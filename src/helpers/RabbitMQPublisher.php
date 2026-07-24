<?php

declare(strict_types=1);

namespace App\Helpers;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Exception;

class RabbitMQPublisher
{
    private $connection;
    private $channel;

    public function __construct()
    {
        $host = Config::getString('RABBITMQ_HOST');
        $port = Config::getInt('RABBITMQ_PORT');
        $user = Config::getString('RABBITMQ_USER');
        $pass = Config::getString('RABBITMQ_PASS');

        try {
            $this->connection = new AMQPStreamConnection($host, $port, $user, $pass);
            $this->channel = $this->connection->channel();
            $this->channel->queue_declare('game_finish_queue', false, true, false, false);
        } catch (Exception $e) {
            error_log('RabbitMQ connection failed: ' . $e->getMessage());
            throw $e;
        }
    }

    public function publishFinishGame( string $roomid ,string $winner): void
    {
        $message = new AMQPMessage(
           json_encode([
                'room_id'  => $roomid,
                'winner' => $winner
            ]),
            ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]
        );

        try {
            $this->channel->basic_publish($message, '', 'game_finish_queue');
        } catch (Exception $e) {
            error_log('RabbitMQ publish failed: ' . $e->getMessage());
            throw $e;
        }
    }

    public function __destruct()
    {
        try {
            $this->channel->close();
            $this->connection->close();
        } catch (Exception $e) {
        }
    }
}