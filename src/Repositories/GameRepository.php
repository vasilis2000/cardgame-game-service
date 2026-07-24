<?php
declare(strict_types=1);

namespace App\Repositories;
use App\Helpers\MongoDBConnection;
use App\Helpers\RedisConnection;
use Predis\Client;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Exception;
class GameRepository
{
    private \MongoDB\Database $db;
    private \MongoDB\Collection $games;
    private Client $redis;

    public function __construct()
    {
        $this->db = MongoDBConnection::getDatabase();
        $this->games = $this->db->selectCollection('games');
        $this->redis = RedisConnection::getClient();
    }

    private function deletegameCache(object $id): void
    {
        try {
            $id = (string) $id;
            $this->redis->del("game:{$id}");
        } catch (\Exception $e) {
        }
    }

    private function deleteUsergameCache(int $userId): void
    {
        try {
            $this->redis->del("user_game:{$userId}");
        } catch (\Exception $e) {
        }
    }

    private function deleteAvailablegamesCache(): void
    {
        try {
            $this->redis->del('available_games');
        } catch (\Exception $e) {
        }
    }

    public function create(array $players, array $board, array $deck,string $roomid): object
    {
        $gameId = new ObjectId();
        $document = [
            '_id'        => $gameId,
            'board'      => $board,
            'deck'       => $deck,
            'roomid'       => $roomid,
            'winner'     => '',
            'status'     => 'playing',
            'players'    => $players,
            'lastcut'    => '',
            'player_turn' => $players[0]["user_id"],
            'player_turnnname' => $players[0]["username"],
            'created_at' => new UTCDateTime(),
        ];

        try {
            $this->games->insertOne($document);
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to create game: ' . $e->getMessage());
        }

        return  $gameId;
    }

    public function getgameWithPlayerid(int $userId): ?array
    {
        $game = (array) $this->games->findOne(['players.user_id' => $userId]);
        if (!$game) {
            return null;
        }

        try {
            $this->redis->setex("user_game:{$userId}", 60, json_encode($game));
        } catch (\Exception $e) {
        }

        return $game;
    }

    public function updateHand(object $gameid, int $userId, array $hand): void
    {
        $result = $this->games->updateOne(
            ['_id' => $gameid, 'players.user_id' => $userId],
            ['$set' => ['players.$.hand' => $hand]]
        );
        if ($result->getMatchedCount() === 0) {
            throw new Exception('User not in this game.');
        }
        $this->deletegameCache($gameid);
        $this->deleteUsergameCache($userId);
    }

    public function updateTurn(object $gameid, int $userId, string $username): void
    {
        $this->games->updateOne(['_id' => $gameid], ['$set' => ['player_turn' => $userId, 'player_turnnname' => $username]]);
        $this->deletegameCache($gameid);
        $this->deleteUsergameCache($userId);
    }

    public function updateScore(object $gameid, int $userId, int $score, int $cardCount): void
    {
        $result = $this->games->updateOne(
            ['_id' => $gameid, 'players.user_id' => $userId],
            [
                '$inc' => [
                    'players.$.score' => $score,
                    'players.$.cardcount' => $cardCount
                ]
            ]
        );
        if ($result->getMatchedCount() === 0) {
            throw new Exception('User not in this game.');
        }
        $this->deletegameCache($gameid);
        $this->deleteUsergameCache($userId);
    }

    public function updateWinner(object $gameid, int $userId, int $loserId): void
    {
        $this->games->updateOne(['_id' => $gameid], ['$set' => ['winner' => $userId]]);
        $this->deletegameCache($gameid);
        $this->deleteUsergameCache($userId);
        $this->deleteUsergameCache($loserId);
        $this->deleteAvailablegamesCache();
    }

  public function removeBoard(object $gameid, int $lastcut): void
{
    $this->games->updateOne(
        ['_id' => $gameid],
        ['$set' => ['board' => [], 'lastcut' => $lastcut]]
    );
    $this->deletegameCache($gameid);
}

    public function updateBoard(object $gameid, array $board): void
    {
        $game = $this->games->findOne(['_id' => $gameid]);
        if (!$game) throw new Exception('Invalid game ID.');
        $currentBoard = (array)$game['board'] ?? [];
        $newBoard = array_merge($currentBoard, $board);
        $this->games->updateOne(['_id' => $gameid], ['$set' => ['board' => $newBoard]]);
        $this->deletegameCache($gameid);
    }

    public function updateDeck(object $gameid, array $deck): void
    {
        $this->games->updateOne(['_id' => $gameid], ['$set' => ['deck' => $deck]]);
        $this->deletegameCache($gameid);
    }
    public function setgameStatus(object $gameid, string $status): bool
    {
        $result =$this->games->updateOne(['_id' => $gameid], ['$set' => ['status' => $status]]);
        $success = $result->getModifiedCount() > 0;

        if ($success) {
           $this->deletegameCache($gameid);
             $this->deleteAvailablegamesCache();
        }

        return $success;
       
    }
}
