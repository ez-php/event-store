<?php

declare(strict_types=1);

namespace EzPhp\EventStore;

/**
 * Class ConcurrencyException
 *
 * Thrown when {@see EventStoreInterface::append()} is called with an
 * `expectedVersion` that no longer matches the stream's actual version —
 * another writer appended to the stream first (optimistic concurrency
 * conflict).
 *
 * @package EzPhp\EventStore
 */
final class ConcurrencyException extends EventStoreException
{
    /**
     * @param string $streamId        The stream that was written to.
     * @param int    $expectedVersion The version the caller expected.
     * @param int    $actualVersion   The stream's actual current version.
     */
    public function __construct(
        public readonly string $streamId,
        public readonly int $expectedVersion,
        public readonly int $actualVersion,
    ) {
        parent::__construct(sprintf(
            'Concurrency conflict on stream "%s": expected version %d, actual version %d.',
            $streamId,
            $expectedVersion,
            $actualVersion,
        ));
    }
}
