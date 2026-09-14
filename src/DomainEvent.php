<?php

declare(strict_types=1);

namespace EzPhp\EventStore;

/**
 * Interface DomainEvent
 *
 * Contract for an event appended to an event store stream. Implementations
 * are plain application-level event objects (e.g. `OrderPlaced`,
 * `AccountDebited`) — the store itself never inspects their internals beyond
 * this contract.
 *
 * @package EzPhp\EventStore
 */
interface DomainEvent
{
    /**
     * A short, stable identifier for this event's type (e.g. "order.placed").
     * Stored alongside the payload so a stream can be replayed without the
     * original PHP class being available (schema evolution, projections
     * written in another process).
     */
    public function eventType(): string;

    /**
     * The event's data, as a JSON-serializable array.
     *
     * @return array<string, mixed>
     */
    public function payload(): array;
}
