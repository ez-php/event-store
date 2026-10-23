<?php

declare(strict_types=1);

namespace EzPhp\EventStore;

/**
 * Class PositionedEvent
 *
 * A StoredEvent plus its position in the global, cross-stream order — what
 * GlobalEventReaderInterface::readAll() returns and what a checkpoint records.
 *
 * @package EzPhp\EventStore
 */
final readonly class PositionedEvent
{
    /**
     * @param int         $position Global position (strictly increasing, gaps possible).
     * @param StoredEvent $event
     */
    public function __construct(
        public int $position,
        public StoredEvent $event,
    ) {
    }
}
