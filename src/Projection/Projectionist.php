<?php

declare(strict_types=1);

namespace EzPhp\EventStore\Projection;

use EzPhp\EventStore\EventStoreInterface;

/**
 * Class Projectionist
 *
 * Drives one or more {@see ProjectorInterface}s from an {@see EventStoreInterface}
 * stream — the mechanism for rebuilding read models from the append-only log,
 * either as a one-off replay (a new projector added later) or repeatedly from
 * a checkpoint (`$fromVersion`) to catch a projection up incrementally.
 *
 * Deliberately minimal: it does not schedule itself, persist checkpoints, or
 * subscribe to new events as they are appended — callers own when and how
 * often replay() runs (e.g. a console command, a queued job after append).
 *
 * @package EzPhp\EventStore\Projection
 */
final class Projectionist
{
    public function __construct(private readonly EventStoreInterface $store)
    {
    }

    /**
     * Replay a single stream's events, in order, into one or more projectors.
     *
     * @param string                    $streamId
     * @param list<ProjectorInterface>  $projectors
     * @param int                       $fromVersion Resume after this version (0 = replay the whole stream).
     */
    public function replay(string $streamId, array $projectors, int $fromVersion = 0): void
    {
        if ($projectors === []) {
            return;
        }

        foreach ($this->store->load($streamId, $fromVersion) as $event) {
            foreach ($projectors as $projector) {
                $projector->project($event);
            }
        }
    }
}
