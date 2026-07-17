<?php
require_once __DIR__ . '/../repos/game.php';
require_once __DIR__ . '/../services/GameService.php';

class GameController
{
    private GameStateRepository $gameRepo;
    private GameService $gameService;

    public function __construct()
    {
        $this->gameRepo = new GameStateRepository();
        $this->gameService = new GameService($this->gameRepo);
    }

    public function view(): void
    {
        try {
            $user = AuthHelper::getAuthenticatedUser();
            $gameData = $this->gameRepo->getgameWithPlayerid($user['user_id']);
            if (!$gameData) {
                ResponseHelper::sendResponse(400, ['message' => 'Game not found.']);
            }
            $result = [];
            $result['game'] = $gameData;
            $result['game']['deckcount'] = count($gameData["deck"]);

            $players = [];
            foreach ($gameData['players'] as $p) {
                if ($user['user_id'] == $p['user_id']) {
                    $players[] = [
                        'id' => $p['user_id'],
                        'username' => $p['username'],
                        'score' => $p['score'],
                        'hand' => $p['hand']
                    ];
                } else {
                    $players[] = [
                        'id' => $p['user_id'],
                        'username' => $p['username'],
                        'score' => $p['score'],
                        'hand' => count($p['hand'])
                    ];
                }
            }
            unset($result['game']['players']);
            unset($result['game']['deck']);

            $result['players'] = $players;
            if (!$gameData) {
                ResponseHelper::sendResponse(400, ['message' => 'game not found.']);
            }
            ResponseHelper::sendResponse(200, $result);
        } catch (Exception $e) {
            ResponseHelper::sendResponse(500, ['message' => $e->getMessage()]);
        }
    }

    public function getTurn(): void
    {
        try {
            $user = AuthHelper::getAuthenticatedUser();
            $gameData = $this->gameRepo->getgameWithPlayerid($user['user_id']);
            if (!$gameData) {
                ResponseHelper::sendResponse(404, ['message' => 'You are not in a game.']);
            }
            $isTurn = ((int)$gameData['player_turn'] === $user['user_id']);
            ResponseHelper::sendResponse(200, ['turn' => $isTurn]);
        } catch (Exception $e) {
            ResponseHelper::sendResponse(500, ['message' => $e->getMessage()]);
        }
    }
    public function playCard(array $data): void
    {
        try {
            if (empty($data['getselected'])) {
                ResponseHelper::sendResponse(422, ['message' => 'Card ID is required.']);
            }
            $user = AuthHelper::getAuthenticatedUser();
            $gameData = $this->gameRepo->getgameWithPlayerid($user['user_id']);
            if (!$gameData) {
                ResponseHelper::sendResponse(404, ['message' => 'You are not in a game.']);
            }
            $result = $this->gameService->playCard($gameData['_id'], $user['user_id'], $data['getselected'], $user['username']);
            ResponseHelper::sendResponse(200, $result);
        } catch (Exception $e) {
            ResponseHelper::sendResponse(500, ['message' => $e->getMessage()]);
        }
    }
       public function startGame(array $data): void
    {
        try {
            if (empty($data['players'])) {
                ResponseHelper::sendResponse(422, ['message' => 'players is required.']);
            }
             if (empty($data['roomid'])) {
                ResponseHelper::sendResponse(422, ['message' => 'roomid is required.']);
            }
            $this->gameService->startGame($data['players'], $data['roomid']);
            ResponseHelper::sendResponse(200, ['message' =>'game stared']);
        } catch (Exception $e) {
            ResponseHelper::sendResponse(500, ['message' => $e->getMessage()]);
        }
    }
}
