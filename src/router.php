<?php

declare(strict_types=1);

namespace App;

use App\Controllers\GameController;
use App\Services\GameService;
use App\Repositories\GameRepository;
use App\Utilities\RabbitMQPublisher;
use App\Http\Request;
use App\Http\Response;
use App\Exceptions\{
    ValidationException,
    AuthenticationException,
    HttpException,
    NotFoundException,
    ConflictException,
    BadRequestException,
    InternalServerException
};

class Router
{
    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function dispatch(): void
    {

     
        $exceptionMap = [
            ValidationException::class      => 422,
            AuthenticationException::class  => 401,
            NotFoundException::class        => 404,
            ConflictException::class        => 409,
            BadRequestException::class      => 400,
            InternalServerException::class  => 500,
        ];

        try {
            $segments = $this->request->getSegments();
            $resource = $segments[0] ?? '';
            $action   = $segments[1] ?? null;
            $method   = $this->request->getMethod();

            switch ($resource) {
                case 'game':
                    $this->handleGameRoutes($action, $method);
                    break;

                default:
                    Response::error(404, 'Not Found');
            }
        } catch (\Throwable $e) {
            $this->handleException($e, $exceptionMap);
        }
    }

    

    private function handleGameRoutes(?string $action, string $method): void
    {
        $gameRepo = new GameRepository();
        $rabbitMQ = new RabbitMQPublisher();
        $gameService = new GameService($gameRepo, $rabbitMQ);
        $gameController = new GameController($gameService);

        switch ($action) {
            case 'view':
                if ($method !== 'GET') {
                    Response::error(405, 'Method Not Allowed.');
                    return;
                }
                $gameController->view();
                break;

            case 'getturn':
                if ($method !== 'GET') {
                    Response::error(405, 'Method Not Allowed.');
                    return;
                }
                $gameController->getTurn();
                break;

            case 'playcard':
                if ($method !== 'POST') {
                    Response::error(405, 'Method Not Allowed.');
                    return;
                }
                $data = $this->request->getJsonBody();
                $gameController->playCard($data);
                break;

            case 'start':
                if ($method !== 'POST') {
                    Response::error(405, 'Method Not Allowed.');
                    return;
                }
                $data = $this->request->getJsonBody();
                $gameController->startGame($data);
                break;

            default:
                Response::error(404, 'Not Found');
        }
    }

    private function handleException(\Throwable $e, array $exceptionMap): void
    {
        if ($e instanceof HttpException) {
            $status = $e->getStatusCode();
            $message = $e->getMessage();
        } else {
            $status = $exceptionMap[get_class($e)] ?? 500;
            $message = ($status === 500) ? 'Internal server error.' : $e->getMessage();
        }
        error_log('Request error: ' . (string) $e);
        Response::error($status, $message);
    }
}