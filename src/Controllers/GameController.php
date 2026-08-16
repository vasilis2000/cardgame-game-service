<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Utilities\AuthHelper;
use App\Services\GameService;
use App\Exceptions\HttpException;
use Exception;
use App\Http\Response;

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
            Response::json(200, $result);
        } catch (HttpException $e) {
            Response::error($e->getStatusCode(), $e->getMessage());
        } catch (Exception $e) {
            error_log('Unexpected error in view: ' . $e->getMessage());
            Response::error(500, 'An internal error occurred.');
        }
    }

    public function getTurn(): void
    {
        try {
            $user = AuthHelper::getAuthenticatedUser();
            $isTurn = $this->gameService->isUserTurn($user['user_id']);
            Response::json(200, ['turn' => $isTurn]);
        } catch (HttpException $e) {
            Response::error($e->getStatusCode(), $e->getMessage());
        } catch (Exception $e) {
            error_log('Unexpected error in getTurn: ' . $e->getMessage());
            Response::error(500, 'An internal error occurred.');
        }
    }

    public function playCard(array $data): void
    {
        try {
            if (empty($data['getselected'])) {
                Response::error(422, 'Card ID is required.');
                return;
            }
            $user = AuthHelper::getAuthenticatedUser();
            $result = $this->gameService->playCard($user['user_id'], $data['getselected']);
            Response::json(200, $result);
        } catch (HttpException $e) {
            Response::error($e->getStatusCode(), $e->getMessage());
        } catch (Exception $e) {
            error_log('Unexpected error in playCard: ' . $e->getMessage());
            Response::error(500, 'An internal error occurred.');
        }
    }

    public function startGame(array $data): void
    {
        try {
            if (empty($data['players'])) {
                Response::error(422, 'players is required.');
                return;
            }
            if (empty($data['roomid'])) {
                Response::error(422, 'roomid is required.');
                return;
            }
            $this->gameService->startGame($data['players'], $data['roomid']);
            Response::json(200, ['game started']);
        } catch (HttpException $e) {
            Response::error($e->getStatusCode(), $e->getMessage());
        } catch (Exception $e) {
            error_log('Unexpected error in startGame: ' . $e->getMessage());
            Response::error(500, 'An internal error occurred.');
        }
    }
}
