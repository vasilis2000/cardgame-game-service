<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\GameRepository;
use App\Exceptions\NotFoundException;
use App\Exceptions\BadRequestException;
use App\Exceptions\ValidationException;
use App\Utilities\RabbitMQPublisher;
use App\Exceptions\InternalServerException;
use Exception;

class GameService
{
    private GameRepository $repo;
    private RabbitMQPublisher $publisher;

    private const DECK = [
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

    public function __construct(GameRepository $repo, RabbitMQPublisher $publisher)
    {
        $this->repo = $repo;
        $this->publisher = $publisher;
    }


    public function startGame(array $players, string $roomid): void
    {
        $deck = self::DECK;
        shuffle($deck);

        $board = array_splice($deck, 0, 4);
        $number = 1;
        foreach ($players as $k => $p) {
            $players[$k]['hand'] = array_splice($deck, 0, 6);
            $players[$k]['score'] = 0;
            $players[$k]['cardcount'] = 0;
            $players[$k]['turn'] = $number;
            $number++;
        }
        try {
            $this->repo->create($players, $board, $deck, $roomid);
        } catch (Exception $e) {
            throw new InternalServerException('Failed to start game: ' . $e->getMessage());
        }
    }

    public function getGameViewData(int $userId): array
    {
        $gameData = $this->repo->getgameWithPlayerid($userId);
        if (!$gameData) {
            throw new NotFoundException('Game not found for this user.');
        }

        $result = [];
        $result['game'] = $gameData;
        $result['game']['deckcount'] = count($gameData["deck"]);

        $players = [];
        foreach ($gameData['players'] as $p) {
            if ($userId == $p['user_id']) {
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
        return $result;
    }

    public function isUserTurn(int $userId): bool
    {
        $gameData = $this->repo->getgameWithPlayerid($userId);
        if (!$gameData) {
            throw new NotFoundException('You are not in a game.');
        }
        return ((int)$gameData['player_turn'] === $userId);
    }

    public function playCard(int $userId, string $card): array
    {
        $game = $this->repo->getgameWithPlayerid($userId);
        if (!$game) {
            throw new NotFoundException('Game not found for this user.');
        }

        $gameData = $game;
        if ($gameData['status'] !== 'playing') {
            throw new BadRequestException('Game is not in playing state.');
        }

        if ((int)$gameData['player_turn'] !== $userId) {
            throw new BadRequestException('Not your turn.');
        }

        $players = $game["players"];
        $emptyhand = true;
        $nextUserId = 0;
        $nextUsername = "";

        foreach ($players as $p) {
            if ((int)$p['user_id'] === $userId) {
                $currentHand = (array)$p['hand'];
                if (!in_array($card, $currentHand)) {
                    throw new ValidationException('You do not have that card.');
                }

                $index = array_search($card, $currentHand);
                unset($currentHand[$index]);
                if (count($currentHand) != 0) {
                    $emptyhand = false;
                }
                $currentHand = array_values($currentHand);
                try {
                    $this->repo->updateHand($gameData['_id'], $userId, $currentHand);
                } catch (Exception $e) {
                    throw new InternalServerException('Failed to update hand: ' . $e->getMessage());
                }

                $board = (array)$gameData['board'];
                $scoreEarned = 0;
                $cardsTaken = 0;
                $lastRank = "";
                $lastCard = end($board);
                if (!empty($lastCard)) {
                    $lastRank = $this->extractRank($lastCard);
                }

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

                    try {
                        $this->repo->removeBoard($gameData['_id'], $userId);
                        $this->repo->updateScore($gameData['_id'], $userId, $scoreEarned, $cardsTaken);
                    } catch (Exception $e) {
                        throw new InternalServerException('Failed to update board/score: ' . $e->getMessage());
                    }
                } else {
                    try {
                        $this->repo->updateBoard($gameData['_id'], [$card]);
                    } catch (Exception $e) {
                        throw new InternalServerException('Failed to update board: ' . $e->getMessage());
                    }
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
        try {
            $this->repo->updateTurn($gameData['_id'], $nextUserId, $nextUsername);
        } catch (Exception $e) {
            throw new InternalServerException('Failed to update turn: ' . $e->getMessage());
        }

        if (empty($deck) && $emptyhand) {
            $game = $this->repo->getgameWithPlayerid($userId);
            if (!$game) {
                throw new InternalServerException('Game state lost after updates.');
            }
            $winner = 0;
            $maxscore = 0;
            $cardcount = 0;

            foreach ($game["players"] as $player) {
                if ($game['lastcut'] == $player["user_id"]) {
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
                }
            }

            try {
                $this->repo->updateWinner($gameData['_id'], $winner, 0);
                $this->repo->setgameStatus($gameData['_id'], 'finished');
            } catch (Exception $e) {
                throw new InternalServerException('Failed to finalize game: ' . $e->getMessage());
            }

            try {
                $this->publisher->publishFinishGame($gameData['roomid'], $winner);
            } catch (Exception $e) {
                try {
                    $this->repo->setgameStatus($gameData['_id'], 'waiting');
                } catch (Exception $rollback) {
                }
                error_log(sprintf(
                    'Failed to publish finished game for room %s: %s',
                    $gameData['roomid'],
                    $e->getMessage()
                ));
                throw new InternalServerException('Could not finish game, please retry.');
            }

            return [
                'game_over' => true,
                'winner_id' => $winner,
                'message'   => 'Game finished.'
            ];
        }

        if ($emptyhand && !empty($deck)) {
            foreach ($players as $p) {
                $hand = array_splice($deck, 0, 6);
                try {
                    $this->repo->updateHand($gameData['_id'], $p['user_id'], $hand);
                } catch (Exception $e) {
                    throw new InternalServerException('Failed to deal new cards: ' . $e->getMessage());
                }
            }
            try {
                $this->repo->updateDeck($gameData['_id'], $deck);
            } catch (Exception $e) {
                throw new InternalServerException('Failed to update deck after redeal: ' . $e->getMessage());
            }
            return [
                'game_over' => false,
                'next_turn' => $nextUserId,
                'message'   => 'New cards dealt.'
            ];
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
