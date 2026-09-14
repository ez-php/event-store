<?php

declare(strict_types=1);

namespace EzPhp\EventStore;

use DateTimeImmutable;

/**
 * Class StoredEvent
 *
 * An event as it was persisted and read back from the store: the raw
 * {@see DomainEvent} plus the stream metadata assigned on append (its
 * position in the stream, when it was written).
 *
 * @package EzPhp\EventStore
 */
final readonly class StoredEvent
{
    /**
     * @param string             $streamId    Identifier of the stream this event belongs to.
     * @param int                $version     1-based position of this event within the stream.
     * @param string             $eventType   The event's {@see DomainEvent::eventType()}.
     * @param array<string, mixed> $payload    The event's {@see DomainEvent::payload()}.
     * @param DateTimeImmutable  $occurredAt  When the event was appended.
     */
    public function __construct(
        public string $streamId,
        public int $version,
        public string $eventType,
        public array $payload,
        public DateTimeImmutable $occurredAt,
    ) {
    }
}
