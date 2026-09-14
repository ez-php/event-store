<?php

declare(strict_types=1);

namespace EzPhp\EventStore\Projection;

use EzPhp\EventStore\StoredEvent;

/**
 * Interface ProjectorInterface
 *
 * A projector turns a sequence of {@see StoredEvent}s into read-optimized
 * state — a denormalized row, an in-memory aggregate, a search index entry.
 * It is applied one event at a time, in stream order, by
 * {@see Projectionist::replay()}.
 *
 * @package EzPhp\EventStore\Projection
 */
interface ProjectorInterface
{
    /**
     * Apply one event to the projection. Called in ascending version order.
     *
     * Implementations decide for themselves which event types they care
     * about (typically a `match ($event->eventType) { ... }`) and ignore
     * the rest.
     */
    public function project(StoredEvent $event): void;
}
