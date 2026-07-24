<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\ResponseHelper;
use App\Helpers\AuthHelper;
use App\Services\GameService;
use App\Exceptions\HttpException;
use Exception;

class GameController
{
    private GameService $gameService;

    public function __construct(GameService $gameService)
    {
        $this->gameService = $gameService;
    }

    public function view(): void
    {
        try {
            $user = AuthHelper::getAuthenticatedUser();
            $result = $this->gameService->getGameViewData($user['user_id']);
            ResponseHelper::sendResponse(200, $result);
        } catch (HttpException $e) {
            ResponseHelper::sendResponse($e->getStatusCode(), ['message' => $e->getMessage()]);
        } catch (Exception $e) {
            error_log('Unexpected error in view: ' . $e->getMessage());
            ResponseHelper::sendResponse(500, ['message' => 'An internal error occurred.']);
        }
    }

    public function getTurn(): void
    {
        try {
            $user = AuthHelper::getAuthenticatedUser();
            $isTurn = $this->gameService->isUserTurn($user['user_id']);
            ResponseHelper::sendResponse(200, ['turn' => $isTurn]);
        } catch (HttpException $e) {
            ResponseHelper::sendResponse($e->getStatusCode(), ['message' => $e->getMessage()]);
        } catch (Exception $e) {
            error_log('Unexpected error in getTurn: ' . $e->getMessage());
            ResponseHelper::sendResponse(500, ['message' => 'An internal error occurred.']);
        }
    }

    public function playCard(array $data): void
    {
        try {
            if (empty($data['getselected'])) {
                ResponseHelper::sendResponse(422, ['message' => 'Card ID is required.']);
                return;
            }
            $user = AuthHelper::getAuthenticatedUser();
            $result = $this->gameService->playCard($user['user_id'], $data['getselected']);
            ResponseHelper::sendResponse(200, $result);
        } catch (HttpException $e) {
            ResponseHelper::sendResponse($e->getStatusCode(), ['message' => $e->getMessage()]);
        } catch (Exception $e) {
            error_log('Unexpected error in playCard: ' . $e->getMessage());
            ResponseHelper::sendResponse(500, ['message' => 'An internal error occurred.']);
        }
    }

    public function startGame(array $data): void
    {
        try {
            if (empty($data['players'])) {
                ResponseHelper::sendResponse(422, ['message' => 'players is required.']);
                return;
            }
            if (empty($data['roomid'])) {
                ResponseHelper::sendResponse(422, ['message' => 'roomid is required.']);
                return;
            }
            $this->gameService->startGame($data['players'], $data['roomid']);
            ResponseHelper::sendResponse(200, ['message' => 'game started']);
        } catch (HttpException $e) {
            ResponseHelper::sendResponse($e->getStatusCode(), ['message' => $e->getMessage()]);
        } catch (Exception $e) {
            error_log('Unexpected error in startGame: ' . $e->getMessage());
            ResponseHelper::sendResponse(500, ['message' => 'An internal error occurred.']);
        }
    }
}