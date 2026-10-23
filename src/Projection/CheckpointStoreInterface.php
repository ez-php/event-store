<?php

declare(strict_types=1);

namespace EzPhp\EventStore\Projection;

/**
 * Interface CheckpointStoreInterface
 *
 * Remembers, per named projection, the last global position it has processed,
 * so GlobalProjectionist resumes where the previous run stopped.
 *
 * @package EzPhp\EventStore\Projection
 */
interface CheckpointStoreInterface
{
    /**
     * Last processed position of a projection; 0 when it never ran.
     *
     * @param string $projection
     *
     * @return int
     */
    public function get(string $projection): int;

    /**
     * Record the last processed position of a projection.
     *
     * @param string $projection
     * @param int    $position
     *
     * @return void
     */
    public function save(string $projection, int $position): void;
}
