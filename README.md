# NaluzPHP Framework (`naluz/framework`)

**NaluzPHP** is a modern, secure PHP framework for building monolithic web apps and REST APIs. This repository is the
**framework core** — the library that powers every NaluzPHP application. You normally don't clone it to build an app;
you install it with Composer (the app skeleton already depends on it).

> Created by Mark Anthony Naluz. MIT licensed. Requires PHP 8.2+.

## What is it?

A batteries-included framework in the spirit of Laravel — familiar concepts, small surface, **secure by default** — with
no hidden magic: everything is plain PHP 8.2+ with `declare(strict_types=1)` and standard PSR interfaces.

| Area | What you get |
|---|---|
| **HTTP** | PSR-7/15 request pipeline, router (groups, middleware, route cache), CORS, rate limiting, security headers, JSON API helpers |
| **Database** | Independent query builder + active-record **ORM** (relations incl. polymorphic and has-many-through, eager loading, soft deletes, casts, events, factories, seeders), schema builder & migrations, lazy-load (N+1) guard |
| **Drivers** | SQLite, MySQL/MariaDB, PostgreSQL, **SQL Server** |
| **Read/write splitting** | Separate primary and replica connections (replica pools, sticky reads, failover, read-only replicas) |
| **NoSQL** | Document stores (`file`, `memory`, **MongoDB**) with an injection-safe query builder |
| **GraphQL** | Built-in GraphQL server: code-first schemas, validation, introspection, depth/size limits, masked errors |
| **Model caching** | Opt-in query caching (`MODEL_CACHING=true`, Redis or local), 5-minute TTL, automatic invalidation and re-caching on writes |
| **Queues** | Sync, database and Redis drivers, retries/backoff, failed-job table, `queue:work` |
| **Event streaming** | Optional event-driven messaging over **Redis Streams**, **RabbitMQ** or **Kafka**: `EventBus`, consumer groups, retries, dead-letter topics, HMAC-signed messages |
| **Mail** | SMTP / log / array transports, queued sending, header-injection protection |
| **Scheduler** | Cron-style task scheduling with overlap protection |
| **Storage** | Root-confined file storage and safe uploads (content-based type detection) |
| **Views** | Compiled, auto-escaping template engine (`*.naluz.php`, `{{ }}` escapes by default) |
| **Security** | Argon2id hashing, encryption, JWT, CSRF, sessions, validation |
| **Logging** | PSR-3 channels: daily/single/stderr/Slack/stack/custom, plus an error handler |
| **HTTP client** | PSR-18 client (Guzzle) with SSRF protection |
| **CLI** | `php naluz …` — migrate, seed, queue, schedule, cache, `run:server`, and more |

PSR coverage: 1, 3, 4, 6, 7, 11, 12, 13, 14, 15, 16, 17, 18, 20.

## Getting started (recommended)

Use the application skeleton, which already requires this package:

```bash
git clone https://github.com/taliffsss/naluzphp-framework my-app
cd my-app
composer install
cp .env.example .env && php naluz key:generate --jwt
php naluz migrate
php naluz run:server --port=8001      # http://127.0.0.1:8001
```

Documentation, examples and the test suite live in the skeleton repository:
**https://github.com/taliffsss/naluzphp-framework**. The documentation is published from
[`naluz-framework-docs`](https://github.com/taliffsss/naluz-framework-docs): <https://taliffsss.github.io/naluz-framework-docs/>.

## Installing the core directly

```bash
composer require naluz/framework
```

```php
// bootstrap/app.php — creates the application (config is read from config/*.php and .env)
$app = new Naluz\Foundation\Application(dirname(__DIR__));
```

A route, a controller, a query and a model:

```php
// routes/api.php
$router->get('/ping', [StatusController::class, 'ping'])->name('ping');
$router->apiResource('posts', PostController::class);       // index/show/store/update/destroy

// controller methods receive PSR-7 requests, route params and injected services
public function index(ServerRequestInterface $request): Paginator
{
    return Post::published()->with('author')->latest()->paginate(15, 1);   // eager loaded, no N+1
}

// query builder (independent of the ORM)
$adults = $db->table('users')->where('age', '>=', 18)->orderBy('name')->paginate(15, 1);

// model
final class Post extends Naluz\Database\Orm\Model
{
    public function author(): Naluz\Database\Orm\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

The application skeleton contains a complete, working example (`PostController`, models, migrations, tests).

## Design principles

* **Secure by default** — bound parameters and validated identifiers in the query builder, auto-escaped views, CSRF on
  web routes, hashed passwords, encrypted queue payloads, SSRF-guarded HTTP client.
* **Standards first** — PSR interfaces everywhere, so you can swap components.
* **No surprises** — strict types, small classes, no global state beyond a few documented helpers.

## Current limitations (read before production)

* SQL Server support is verified by SQL-generation tests only (no live SQL Server in CI); validate it against your own
  instance.
* The MongoDB adapter is tested against a fake collection, not a live server; the `file` and `memory` NoSQL drivers are
  fully tested.
* MySQL and PostgreSQL grammars are verified by SQL-generation tests; the integration suite runs on SQLite.

## Repository layout

```
src/            the framework (namespace Naluz\)
composer.json   package definition
```

This repo is the published core; the test suite lives with the application skeleton, which exercises the package
through `vendor/`.

## Contributing & security

Issues and pull requests are welcome (see the skeleton repository for contribution guidelines). Report security issues
privately, not in public issues — see `SECURITY.md` in the skeleton repository.

## License

MIT © Mark Anthony Naluz
