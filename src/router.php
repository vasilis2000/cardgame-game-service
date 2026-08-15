<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Controllers\GameController;
use App\Utilities\ResponseHelper;
use App\Repositories\GameRepository;
use App\Services\GameService;
use App\Utilities\RabbitMQPublisher;
use App\Utilities\Config;

try {

    Config::load();

    header('Content-Type: application/json');

    $allowedOrigins = Config::getArray('ALLOWED_ORIGINS', ',', ['http://localhost']);
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    if ($origin !== '' && !in_array($origin, $allowedOrigins, true)) {
        http_response_code(403);
        echo json_encode(['message' => 'Origin not allowed.']);
        exit;
    }

    if ($origin !== '') {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
    } else {
        header('Access-Control-Allow-Origin: *');
    }

    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        $requestMethod = $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] ?? null;
        $requestHeaders = $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? null;

        if ($requestMethod) {
            header('Access-Control-Allow-Methods: ' . $requestMethod);
        }
        if ($requestHeaders) {
            header('Access-Control-Allow-Headers: ' . $requestHeaders);
        }

        header('Access-Control-Max-Age: 86400');
        http_response_code(204);
        exit;
    }

    $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $requestUri = trim($requestUri, '/');
    $segments = $requestUri ? explode('/', $requestUri) : [];

    $resource = $segments[0] ?? '';
    $action   = $segments[1] ?? null;
    $method   = $_SERVER['REQUEST_METHOD'];

    $gameRepo = new GameRepository();
    $rabbitMQ = new RabbitMQPublisher();
    $gameService = new GameService($gameRepo, $rabbitMQ);
    $gameController = new GameController($gameService);

    switch ($resource) {
        case 'game':
            switch ($action) {
                case 'view':
                    if ($method === 'GET') {
                        $gameController->view();
                    } else {
                       ResponseHelper::sendResponse(405, ['message' => 'Method Not Allowed.']);
                    }
                    break;
                case 'getturn':
                    if ($method === 'GET') {
                        $gameController->getTurn();
                    } else {
                       ResponseHelper::sendResponse(405, ['message' => 'Method Not Allowed.']);
                    }
                    break;
                case 'playcard':
                    if ($method === 'POST') {
                        $data     = json_decode(file_get_contents('php://input'), true) ?? [];
                        $gameController->playCard($data);
                    } else {
                       ResponseHelper::sendResponse(405, ['message' => 'Method Not Allowed.']);
                    }
                    break;
                case 'start':
                    if ($method === 'POST') {
                        $data     = json_decode(file_get_contents('php://input'), true) ?? [];
                        $gameController->startGame($data);
                    } else {
                       ResponseHelper::sendResponse(405, ['message' => 'Method Not Allowed.']);
                    }
                    break;
                default:
                    ResponseHelper::sendResponse(404, ['message' => 'Not Found']);
                    break;
            }
            break;
        default:
           ResponseHelper::sendResponse(404, ['message' => 'Not Found']);
            break;
    }
} catch (\Throwable $e) {
    error_log('Unhandled router error: ' . $e->getMessage());
    ResponseHelper::sendResponse(500, ['error' => 'Internal server error.']);
}
