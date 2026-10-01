# Changelog

## Unreleased (1.1.0)

### Added
- Model observers: `Model::observe(Observer::class|object|list)` turns public methods named after model events
  (`saving`, `saved`, `creating`, `created`, `updating`, `updated`, `deleting`, `deleted`) into listeners. Observers are
  built lazily through the container; attaching one twice is ignored; returning `false` from an `-ing` method cancels.
- `#[ObservedBy(Observer::class)]` attribute for declaring a model's observers (inherited by child models).
- `make:observer` generator.

## 1.0.0 — first release

First stable release of the framework core. See [releases/v1.0.0.md](releases/v1.0.0.md) for the full release notes.
