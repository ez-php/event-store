<?php

declare(strict_types=1);

namespace EzPhp\EventStore;

/**
 * Interface GlobalEventReaderInterface
 *
 * Reads events of every stream in global order, for read models that span many
 * aggregates (all orders, all users). Kept apart from EventStoreInterface, whose
 * per-stream contract stays unchanged; PdoEventStore implements both.
 *
 * Positions are strictly increasing but may have gaps. Like any auto-increment
 * order they are assigned at insert, not at commit: under concurrent appends a
 * reader can see position 11 before a transaction holding 10 has committed, and a
 * checkpoint past 11 then skips 10. Run appends through one writer, or let
 * projections re-read a short overlap (GlobalProjectionist's `overlap`), where that matters.
 *
 * @package EzPhp\EventStore
 */
interface GlobalEventReaderInterface
{
    /**
     * Events with a position greater than $fromPosition, oldest first.
     *
     * @param int $fromPosition Exclusive lower bound (0 = from the beginning).
     * @param int $limit        Page size (≥ 1).
     *
     * @throws EventStoreException On a storage failure or an invalid limit.
     *
     * @return list<PositionedEvent>
     */
    public function readAll(int $fromPosition = 0, int $limit = 500): array;
}
