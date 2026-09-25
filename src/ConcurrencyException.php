<?php

declare(strict_types=1);

namespace EzPhp\EventStore;

use Throwable;

/**
 * Class ConcurrencyException
 *
 * Thrown when {@see EventStoreInterface::append()} is called with an
 * `expectedVersion` that no longer matches the stream's actual version —
 * another writer appended to the stream first (optimistic concurrency
 * conflict). Also thrown when two writers read the same version at once and
 * the loser's insert hits the `UNIQUE (stream_id, version)` constraint.
 *
 * @package EzPhp\EventStore
 */
final class ConcurrencyException extends EventStoreException
{
    /**
     * @param string $streamId        The stream that was written to.
     * @param int    $expectedVersion The version the caller expected.
     * @param int    $actualVersion   The stream's actual current version.
     * @param Throwable|null $previous  The driver error behind a lost append race, if any.
     */
    public function __construct(
        public readonly string $streamId,
        public readonly int $expectedVersion,
        public readonly int $actualVersion,
        ?Throwable $previous = null,
    ) {
        parent::__construct(sprintf(
            'Concurrency conflict on stream "%s": expected version %d, actual version %d.',
            $streamId,
            $expectedVersion,
            $actualVersion,
        ), 0, $previous);
    }
}
