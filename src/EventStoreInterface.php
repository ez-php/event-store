<?php

declare(strict_types=1);

namespace EzPhp\EventStore;

/**
 * Interface EventStoreInterface
 *
 * Contract for an append-only event store: events are written to named
 * streams and never modified or removed, and a stream can be replayed from
 * the beginning (or from a given version) to reconstruct state.
 *
 * @package EzPhp\EventStore
 */
interface EventStoreInterface
{
    /**
     * Append one or more events to a stream.
     *
     * When `$expectedVersion` is given, the append only succeeds if the
     * stream's current version equals it — an optimistic concurrency check
     * that protects against two writers racing on the same stream. Pass
     * `null` to append unconditionally (e.g. streams that are never
     * concurrently written to).
     *
     * @param string             $streamId
     * @param list<DomainEvent>  $events
     * @param int|null           $expectedVersion
     *
     * @throws ConcurrencyException If `$expectedVersion` no longer matches.
     * @throws EventStoreException  On any other persistence failure.
     */
    public function append(string $streamId, array $events, ?int $expectedVersion = null): void;

    /**
     * Load a stream's events in the order they were appended.
     *
     * @param string $streamId
     * @param int    $fromVersion Skip events at or below this version (0 = from the start).
     *
     * @return list<StoredEvent>
     */
    public function load(string $streamId, int $fromVersion = 0): array;

    /**
     * The stream's current version — the number of events appended to it so
     * far (0 for a stream that does not exist yet).
     *
     * @param string $streamId
     */
    public function getVersion(string $streamId): int;
}
