# ez-php/event-store

Append-only event store with stream replay and projections for event sourcing.

---

## Why

`ez-php/events` is a deliberately synchronous, in-process event dispatcher — it
does not persist anything. `ez-php/event-store` is the separate, dedicated
package for actually recording a stream of domain events and rebuilding state
from it: append events to a named stream, replay the stream from the start (or
from a checkpoint), and drive one or more projections from that replay.

It composes naturally with `ez-php/audit`, which already models entity
lifecycle changes as events but only keeps a flat log — an event store adds
per-aggregate streams, versioning, and optimistic concurrency on top of that
idea.

`AppendToStreamListener` is the glue for that composition: an `ez-php/events`
listener that appends a dispatched event straight into a stream, so you don't
call `append()` by hand in every listener. Requires `ez-php/events` (a soft
dependency — declared in `require-dev` here, install it separately):

```php
use EzPhp\EventStore\AppendToStreamListener;
use EzPhp\EventStore\DomainEvent;

// OrderPlaced must implement both EzPhp\Events\EventInterface and DomainEvent
$dispatcher->listen(OrderPlaced::class, new AppendToStreamListener(
    $store,
    fn (DomainEvent $event): string => 'order-' . $event->orderId(),
));
```

Events dispatched that do *not* implement `DomainEvent` are silently ignored
by the listener — register it only for event classes meant to be appended.

---

## Installation

```bash
composer require ez-php/event-store
```

Register the service provider (e.g. in `provider/modules.php`):

```php
$app->register(\EzPhp\EventStore\EventStoreServiceProvider::class);
```

This binds `EventStoreInterface` to a `PdoEventStore` using the application's
`DatabaseInterface` connection. It requires `DatabaseInterface` to already be
bound — an event store with nowhere to write is not a usable configuration, so
resolving it without a database throws rather than degrading silently.

---

## Usage

### Define events

```php
use EzPhp\EventStore\DomainEvent;

final readonly class OrderPlaced implements DomainEvent
{
    public function __construct(private int $orderId, private int $total)
    {
    }

    public function eventType(): string
    {
        return 'order.placed';
    }

    public function payload(): array
    {
        return ['order_id' => $this->orderId, 'total' => $this->total];
    }
}
```

### Append to a stream

```php
$store = $app->make(EventStoreInterface::class);

$store->append("order-{$orderId}", [
    new OrderPlaced($orderId, total: 4200),
]);
```

Pass `expectedVersion` to guard against two writers racing on the same
stream — the append fails with `ConcurrencyException` if the stream has moved
on since the caller last read it:

```php
$version = $store->getVersion($streamId);

// ... decide what to append based on current state ...

$store->append($streamId, $newEvents, expectedVersion: $version);
```

### Replay a stream

```php
$events = $store->load("order-{$orderId}"); // list<StoredEvent>, in version order
```

### Projections

```php
use EzPhp\EventStore\Projection\Projectionist;
use EzPhp\EventStore\Projection\ProjectorInterface;
use EzPhp\EventStore\StoredEvent;

final class OrderSummaryProjector implements ProjectorInterface
{
    public function project(StoredEvent $event): void
    {
        match ($event->eventType) {
            'order.placed' => /* insert/update a read-model row */ null,
            default => null,
        };
    }
}

(new Projectionist($store))->replay($streamId, [new OrderSummaryProjector()]);
```

`Projectionist` is deliberately minimal — it does not schedule itself,
persist checkpoints, or subscribe to new events as they are appended. Pass
`fromVersion` to resume a projector from wherever it last left off; owning
that checkpoint (and when to trigger a replay) is left to the caller.

---

## Storage

`PdoEventStore` auto-creates its `event_store_events` table on first use,
adapting the DDL to the PDO driver (SQLite for tests, MySQL for production).
In production, prefer a proper migration over relying on this — see
`PdoEventStore`'s class doc for the MySQL DDL to copy into one.

---

## License

MIT
