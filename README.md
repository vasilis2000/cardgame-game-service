# Card Game Service

A PHP microservice that powers a multiplayer card game. It exposes a small JSON HTTP API for starting games, viewing game state, checking turn order, and playing cards, backed by MongoDB for persistence, Redis for caching, and RabbitMQ for publishing game-finished events.

## Architecture

- **Router** (`src/router.php`) — maps HTTP method + path segments to controller actions and centralizes exception-to-HTTP-status handling.
- **Controllers** (`src/Controllers`) — validate request input and translate service results into HTTP responses.
- **Services** (`src/Services`) — game rules and orchestration (dealing cards, scoring, turn order, win conditions).
- **Repositories** (`src/Repositories`) — MongoDB persistence, with Redis cache invalidation on writes.
- **Middleware** (`src/Middleware`) — CORS handling and JWT-based authentication.
- **Utilities** (`src/Utilities`) — config loading, JWT verification, and connections to MongoDB, Redis, and RabbitMQ.
- **bin/finish_game_queue.php** — a standalone worker that consumes the `game_finish_queue` RabbitMQ queue to react to finished games (e.g. notify other services).

## Tech Stack

- PHP 8+ (`declare(strict_types=1)` throughout)
- [MongoDB](https://www.mongodb.com/) — primary datastore (`mongodb/mongodb`)
- [Redis](https://redis.io/) via [Predis](https://github.com/predis/predis) — short-lived caching of game/user lookups
- [RabbitMQ](https://www.rabbitmq.com/) via `php-amqplib/php-amqplib` — publishes a message when a game finishes
- [firebase/php-jwt](https://github.com/firebase/php-jwt) — JWT authentication
- [vlucas/phpdotenv](https://github.com/vlucas/phpdotenv) — `.env` loading
- Docker / Docker Compose for local development

## Getting Started

### Prerequisites

- Docker and Docker Compose

### Setup

1. Clone the repository.
2. Copy `.env.example` to `.env` (or create `.env`) and fill in the required variables (see below).
3. Build and start the stack:

   ```bash
   docker-compose up --build
   ```

4. The API will be available at the port configured in `docker-compose.yml`, routed through `public/index.php`.

### Running the queue worker

The `bin/finish_game_queue.php` worker consumes messages published to `game_finish_queue` whenever a game ends. Run it as a long-lived process (e.g. inside its own container or via a process manager such as Supervisor):

```bash
php bin/finish_game_queue.php
```

## Environment Variables

Loaded and validated by `src/Utilities/Config.php` on startup. Missing required keys cause the app to fail fast with a `RuntimeException`.

### Required

| Variable         | Description                          |
|------------------|---------------------------------------|
| `MONGO_URI`      | MongoDB connection string             |
| `MONGO_DB`       | MongoDB database name                 |
| `RABBITMQ_HOST`  | RabbitMQ host                         |
| `RABBITMQ_PORT`  | RabbitMQ port                         |
| `RABBITMQ_USER`  | RabbitMQ username                     |
| `RABBITMQ_PASS`  | RabbitMQ password                     |
| `JWT_SECRET`     | Secret used to verify JWTs (HS256)    |
| `REDIS_URL`      | Redis connection URL                  |

### Optional (with defaults)

| Variable           | Default              | Description                                   |
|--------------------|----------------------|------------------------------------------------|
| `JWT_EXPIRY`       | `3600`                | JWT expiry, in seconds                         |
| `ALLOWED_ORIGINS`  | `http://localhost`   | Comma-separated list of allowed CORS origins    |

## Authentication

All endpoints except `game/start` require a valid JWT sent as a Bearer token:

```
Authorization: Bearer <token>
```

The token must decode to an object containing `user_id` and `username` claims. Authentication is handled by `AuthMiddleware` / `JwtHelper` and surfaced via `AuthHelper::getAuthenticatedUser()`.

## API Reference

Base path: `/game`

All responses are JSON. Errors follow the shape `{ "message": "..." }` with an appropriate HTTP status code (`400`, `401`, `404`, `405`, `409`, `422`, `500`).

### `POST /game/start`

Starts a new game for a set of players in a room. Shuffles the 52-card deck, deals 4 cards to the board and 6 cards to each player's hand.

**Body:**

```json
{
  "roomid": "room-123",
  "players": [
    { "user_id": 1, "username": "alice" },
    { "user_id": 2, "username": "bob" }
  ]
}
```

**Response `200`:**

```json
{ "0": "game started" }
```

### `GET /game/view`

Returns the current game state for the authenticated user: the board, deck count, and each player's info (the authenticated user's full hand, other players' hand counts only).

**Response `200`:**

```json
{
  "game": {
    "board": ["U+1F0A1", "..."],
    "roomid": "room-123",
    "status": "playing",
    "deckcount": 30
  },
  "players": [
    { "id": 1, "username": "alice", "score": 0, "hand": ["U+1F0A1", "..."] },
    { "id": 2, "username": "bob", "score": 0, "hand": 6 }
  ]
}
```

### `GET /game/getturn`

Returns whether it is currently the authenticated user's turn.

**Response `200`:**

```json
{ "turn": true }
```

### `POST /game/playcard`

Plays a card from the authenticated user's hand. Handles matching against the board (including Jack "sweep" rules), scoring, turn advancement, re-dealing when hands are empty, and finalizing the game (publishing to RabbitMQ) once the deck and all hands are exhausted.

**Body:**

```json
{ "getselected": "U+1F0A1" }
```

**Response `200` (normal turn):**

```json
{ "game_over": false, "next_turn": 2 }
```

**Response `200` (game finished):**

```json
{ "game_over": true, "winner_id": 1, "message": "Game finished." }
```

## Game Rules Notes

- The deck is built from 52 unicode playing-card code points (`GameService::DECK`); rank is derived via a lookup table (`extractRank`).
- Matching the top board card's rank, or playing a Jack, sweeps the board into the player's score pile.
- Face cards and Aces are worth 2 points when swept; the "big/little casino" cards (`U+1F0CA`, `U+1F0A2`) are worth 1 point.
- When both the deck and all hands are empty, the game ends: remaining board cards go to whoever made the last sweep (`lastcut`), and the winner is the player with the highest score (with a card-count tiebreaker bonus).
- Redis keys (`game:{id}`, `user_game:{id}`, `available_games`) are invalidated on every state-changing write so subsequent reads stay fresh.

## Error Handling

Domain exceptions (`ValidationException`, `AuthenticationException`, `NotFoundException`, `ConflictException`, `BadRequestException`, `InternalServerException`) are mapped to HTTP status codes centrally in `Router::handleException`. Unhandled exceptions are logged and returned as a generic `500 Internal server error`.

## License

Add your license of choice here.
