# Changelog

## 1.3.0 — event streaming (optional)

Full notes: [releases/v1.3.0.md](releases/v1.3.0.md).

### Added
- `Naluz\Messaging`: publish and consume events through Redis Streams, RabbitMQ or Kafka (or an in-memory broker for tests),
  all optional. `EventBus`, `Consumer`, `Subscriber`, `PublishableEvent`, `BrokerManager`, `MessagingServiceProvider`.
- Retries per consumer group, dead-letter topics (`<topic>.dlq`), optional HMAC-SHA256 message signing with key rotation,
  size and depth limits, topic-name validation.
- `messaging:consume`, `messaging:declare`, `messaging:publish`, `make:subscriber`.
- Redis Streams needs no dependency; RabbitMQ needs `php-amqplib/php-amqplib`; Kafka needs `ext-rdkafka`.

## 1.2.2 — clean release

GraphQL server and model observers, published correctly (1.2.0 and 1.2.1 were indexed by Packagist at an earlier commit without GraphQL; do not
use them). `Application::VERSION` now reports `1.2.2`. Require `^1.2.2`. See [releases/v1.2.2.md](releases/v1.2.2.md).

## 1.2.1 — packaging fix

Same code as the intended 1.2.0 (GraphQL server and model observers). The `v1.2.0` tag had been re-pointed after it was
published on Packagist, so Composer could install a 1.2.0 without GraphQL. Require `^1.2.1`. See [releases/v1.2.1.md](releases/v1.2.1.md).

## 1.2.0 — GraphQL and model observers

Full notes: [releases/v1.2.0.md](releases/v1.2.0.md).

### Added
- Built-in GraphQL server (`Naluz\GraphQL`): parser, validator, executor, introspection, `GraphQLController`, `GraphQLServiceProvider`;
  depth / node / size limits, masked internal errors, introspection off by default outside debug. See [releases/v1.2.0.md](releases/v1.2.0.md).
- Model observers: `Model::observe(Observer::class|object|list)` turns public methods named after model events
  (`saving`, `saved`, `creating`, `created`, `updating`, `updated`, `deleting`, `deleted`) into listeners. Observers are
  built lazily through the container; attaching one twice is ignored; returning `false` from an `-ing` method cancels.
- `#[ObservedBy(Observer::class)]` attribute for declaring a model's observers (inherited by child models).
- `make:observer` generator.

## 1.0.0 — first release

First stable release of the framework core. See [releases/v1.0.0.md](releases/v1.0.0.md) for the full release notes.
