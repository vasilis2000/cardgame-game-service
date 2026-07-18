<?php
require_once __DIR__ . '/../repos/game.php';

class GameService
{
    private GameStateRepository $repo;

    const DECK = [
        0  => "U+1F0A1",
        1  => "U+1F0B1",
        2  => "U+1F0C1",
        3  => "U+1F0D1",
        4  => "U+1F0A2",
        5  => "U+1F0B2",
        6  => "U+1F0C2",
        7  => "U+1F0D2",
        8  => "U+1F0A3",
        9  => "U+1F0B3",
        10 => "U+1F0C3",
        11 => "U+1F0D3",
        12 => "U+1F0A4",
        13 => "U+1F0B4",
        14 => "U+1F0C4",
        15 => "U+1F0D4",
        16 => "U+1F0A5",
        17 => "U+1F0B5",
        18 => "U+1F0C5",
        19 => "U+1F0D5",
        20 => "U+1F0A6",
        21 => "U+1F0B6",
        22 => "U+1F0C6",
        23 => "U+1F0D6",
        24 => "U+1F0A7",
        25 => "U+1F0B7",
        26 => "U+1F0C7",
        27 => "U+1F0D7",
        28 => "U+1F0A8",
        29 => "U+1F0B8",
        30 => "U+1F0C8",
        31 => "U+1F0D8",
        32 => "U+1F0A9",
        33 => "U+1F0B9",
        34 => "U+1F0C9",
        35 => "U+1F0D9",
        36 => "U+1F0AA",
        37 => "U+1F0BA",
        38 => "U+1F0CA",
        39 => "U+1F0DA",
        40 => "U+1F0AB",
        41 => "U+1F0BB",
        42 => "U+1F0CB",
        43 => "U+1F0DB",
        44 => "U+1F0AD",
        45 => "U+1F0BD",
        46 => "U+1F0CD",
        47 => "U+1F0DD",
        48 => "U+1F0AE",
        49 => "U+1F0BE",
        50 => "U+1F0CE",
        51 => "U+1F0DE"
    ];

    public function __construct(GameStateRepository $repo)
    {
        $this->repo = $repo;
    }

    public function startGame(array $players, string $roomid): void
    {
        $deck = self::DECK;
        shuffle($deck);

        $board = array_splice($deck, 0, 4);
        foreach ($players as $k => $p) {
            $players[$k]['hand'] = array_splice($deck, 0, 6);
            $players[$k]['score'] = 0;
            $players[$k]['cardcount'] = 0;
        }
        $this->repo->create($players, $board, $deck, $roomid);
    }

    public function playCard(object $gameId, int $userId, string $card, string $username): array
    {
        $game = $this->repo->getgameWithPlayerid($userId);
        if (!$game) {
            throw new Exception('game not found.');
        }
        $gameData = $game;
        if ($gameData['status'] !== 'playing') {
            throw new Exception('Game is not in playing state.');
        }

        if ((int)$gameData['player_turn'] !== $userId) {
            throw new Exception('Not your turn.');
        }

        $players = $game["players"];
        $emptyhand = true;
        $nextUserId = 0;
        $nextUsername = "";
        foreach ($players as $p) {
            if ((int)$p['user_id'] === $userId) {
                $currentHand = (array)$p['hand'];
                if (!in_array($card, $currentHand)) {
                    throw new Exception('You do not have that card.');
                }

                $index = array_search($card, $currentHand);
                unset($currentHand[$index]);
                if (count($currentHand) != 0) {
                    $emptyhand = false;
                }
                $currentHand = array_values($currentHand);
                $this->repo->updateHand($gameData['_id'], $userId, $currentHand);

                $board = (array)$gameData['board'];
                $scoreEarned = 0;
                $cardsTaken = 0;

                $lastCard = end($board);
                $lastRank = $this->extractRank($lastCard);
                $playedRank = $this->extractRank($card);
                $allCards = $board;
                $allCards[] = $card;
                if (count($board) > 0 && ($playedRank === 'J' || $playedRank === $lastRank)) {
                    if (count($board) === 1 && $playedRank === 'J' && $lastRank === 'J') {
                        $scoreEarned = 20;
                    } else if (count($board) === 1) {
                        $scoreEarned = 10;
                    } else {
                        $scoreEarned = $this->calculateScore($allCards);
                    }

                    $cardsTaken = count($allCards);

                    $this->repo->removeBoard($gameData['_id'], $userId);
                    $this->repo->updateScore($gameData['_id'], $userId, $scoreEarned, $cardsTaken);
                } else {
                    $this->repo->updateBoard($gameData['_id'], [$card]);
                }
            } else {
                $nextUsername = (string)$p['username'];
                $nextUserId = (int)$p['user_id'];
                if (count((array)$p['hand']) != 0) {
                    $emptyhand = false;
                }
            }
        }
        $deck = (array)$gameData["deck"];
        $this->repo->updateTurn($gameData['_id'], $nextUserId, $nextUsername);

        if (empty($deck) && $emptyhand) {
            $game = $this->repo->getgameWithPlayerid($userId);
            $winner = 0;
            $loserid = 0;
            $maxscore = 0;
            $cardcount = 0;
            foreach ($game["players"] as $player) {
                if ($gameData['lastcut'] == $player["user_id"]) {
                    $player['score'] += $this->calculateScore((array)$game["board"]);
                    $player["cardcount"] += count($game["board"]);
                }

                $flag = false;
                if ($player["cardcount"] > $cardcount) {
                    $cardcount = $player["cardcount"];
                    $flag = true;
                }

                if ($player['score'] > ($flag ? $maxscore - 3 : $maxscore)) {
                    $maxscore = $flag ? (int)$player['score'] + 3 : (int)$player['score'];
                    $winner = $player['user_id'];
                } else {
                    $loserid = $player['user_id'];
                }
            }
            $this->repo->updateWinner($gameData['_id'], $winner, $loserid);
            $finish =  $this->repo->setgameStatus($gameData['_id'], 'finished');
            if ($finish) {
                require_once __DIR__ . '/../helpers/RabbitMQPublisher.php';
                $publisher = new RabbitMQPublisher();
                try {
                    $publisher->publishFinshGame($gameData['roomid'], $winner);
                    ResponseHelper::sendResponse(200, ['message' => 'Room started successfully.']);
                } catch (Exception $e) {
                    $this->repo->setgameStatus($gameData['_id'], 'waiting');
                    error_log(sprintf(
                        'Failed to publish start game for room %s: %s',
                        $roomId,
                        $e->getMessage()
                    ));
                    ResponseHelper::sendResponse(500, ['message' => 'Could not start game, please retry.']);
                }
            }
            return [
                'game_over' => true,
                'winner_id' => $winnerId,
                'message'   => 'Game finished.'
            ];
        }
        if ($emptyhand) {
            if (!empty($deck)) {
                foreach ($players as $p) {
                    $hand = array_splice($deck, 0, 6);
                    $this->repo->updateHand($gameId, $p['user_id'], $hand);
                }
                $this->repo->updateDeck($gameId, $deck);
                return [
                    'game_over' => false,
                    'next_turn' => $nextUserId,
                    'message'   => 'New cards dealt.'
                ];
            }
        }
        return [
            'game_over' => false,
            'next_turn' => $nextUserId,
        ];
    }

    private function extractRank(string $card): string
    {
        static $rankMap = [
            'U+1F0A1' => 'A',
            'U+1F0B1' => 'A',
            'U+1F0C1' => 'A',
            'U+1F0D1' => 'A',
            'U+1F0A2' => '2',
            'U+1F0B2' => '2',
            'U+1F0C2' => '2',
            'U+1F0D2' => '2',
            'U+1F0A3' => '3',
            'U+1F0B3' => '3',
            'U+1F0C3' => '3',
            'U+1F0D3' => '3',
            'U+1F0A4' => '4',
            'U+1F0B4' => '4',
            'U+1F0C4' => '4',
            'U+1F0D4' => '4',
            'U+1F0A5' => '5',
            'U+1F0B5' => '5',
            'U+1F0C5' => '5',
            'U+1F0D5' => '5',
            'U+1F0A6' => '6',
            'U+1F0B6' => '6',
            'U+1F0C6' => '6',
            'U+1F0D6' => '6',
            'U+1F0A7' => '7',
            'U+1F0B7' => '7',
            'U+1F0C7' => '7',
            'U+1F0D7' => '7',
            'U+1F0A8' => '8',
            'U+1F0B8' => '8',
            'U+1F0C8' => '8',
            'U+1F0D8' => '8',
            'U+1F0A9' => '9',
            'U+1F0B9' => '9',
            'U+1F0C9' => '9',
            'U+1F0D9' => '9',
            'U+1F0AA' => '10',
            'U+1F0BA' => '10',
            'U+1F0CA' => '10',
            'U+1F0DA' => '10',
            'U+1F0AB' => 'J',
            'U+1F0BB' => 'J',
            'U+1F0CB' => 'J',
            'U+1F0DB' => 'J',
            'U+1F0AD' => 'Q',
            'U+1F0BD' => 'Q',
            'U+1F0CD' => 'Q',
            'U+1F0DD' => 'Q',
            'U+1F0AE' => 'K',
            'U+1F0BE' => 'K',
            'U+1F0CE' => 'K',
            'U+1F0DE' => 'K'
        ];
        return $rankMap[$card] ?? '?';
    }

    private function calculateScore(array $cards): int
    {
        $score = 0;
        foreach ($cards as $card) {
            $rank = $this->extractRank($card);
            if (in_array($rank, ['K', 'Q', 'J', 'A'])) {
                $score += 2;
            } elseif ($card === 'U+1F0CA' || $card === 'U+1F0A2') {
                $score += 1;
            }
        }
        return $score;
    }
}
