<?php

declare(strict_types=1);

namespace EzPhp\EventStore\Projection;

use EzPhp\EventStore\EventStoreException;
use EzPhp\EventStore\GlobalEventReaderInterface;

/**
 * Class GlobalProjectionist
 *
 * Feeds the events of every stream, in global order, to the projectors of a
 * named projection, resuming from that projection's checkpoint — the
 * cross-stream counterpart of Projectionist::replay().
 *
 * run() pages through GlobalEventReaderInterface::readAll() and saves the
 * checkpoint after every page, so delivery is at-least-once: a projector that
 * throws mid-page leaves the checkpoint at the previous page, and the next run
 * repeats that page. Projectors should therefore be idempotent (upsert by id).
 *
 * Positions are assigned at insert, not at commit, so with concurrent writers a
 * checkpoint can move past an event whose transaction commits later — without
 * an overlap that event is never projected. `$overlap` makes every run start
 * that many positions before the checkpoint: late commits inside the window
 * are picked up, at the price of re-delivering the window's events each run
 * (idempotent projectors again). Size it to the positions your writers can
 * insert while one transaction is still open; 0 (default) suits a single writer.
 *
 * @package EzPhp\EventStore\Projection
 */
final readonly class GlobalProjectionist
{
    /**
     * @param GlobalEventReaderInterface $reader
     * @param CheckpointStoreInterface   $checkpoints
     * @param int                        $batchSize   Events per readAll() page and per checkpoint save.
     * @param int                        $overlap     Positions re-read before the checkpoint at the start of each run.
     *
     * @throws EventStoreException When the batch size is below 1 or the overlap negative.
     */
    public function __construct(
        private GlobalEventReaderInterface $reader,
        private CheckpointStoreInterface $checkpoints,
        private int $batchSize = 500,
        private int $overlap = 0,
    ) {
        if ($batchSize < 1) {
            throw new EventStoreException('GlobalProjectionist needs a batch size of at least 1.');
        }

        if ($overlap < 0) {
            throw new EventStoreException('GlobalProjectionist needs an overlap of at least 0.');
        }
    }

    /**
     * Project every event after the checkpoint of $projection.
     *
     * @param string                   $projection Checkpoint name, e.g. 'order-list'.
     * @param list<ProjectorInterface> $projectors Called in order for every event.
     * @param int|null                 $maxEvents  Stop after about this many events (whole pages); null = until caught up.
     *
     * @return int Number of events projected in this run (re-delivered overlap events included).
     */
    public function run(string $projection, array $projectors, ?int $maxEvents = null): int
    {
        $checkpoint = $this->checkpoints->get($projection);
        $position = max(0, $checkpoint - $this->overlap);
        $processed = 0;

        while ($maxEvents === null || $processed < $maxEvents) {
            $page = $this->reader->readAll($position, $this->batchSize);

            if ($page === []) {
                break;
            }

            foreach ($page as $positioned) {
                foreach ($projectors as $projector) {
                    $projector->project($positioned->event);
                }

                $position = $positioned->position;
                $processed++;
            }

            // A page inside the overlap window must not move the checkpoint backwards.
            $this->checkpoints->save($projection, max($position, $checkpoint));

            if (count($page) < $this->batchSize) {
                break;
            }
        }

        return $processed;
    }

    /**
     * Forget the checkpoint, so the next run() rebuilds the projection from the first event.
     *
     * @param string $projection
     *
     * @return void
     */
    public function reset(string $projection): void
    {
        $this->checkpoints->save($projection, 0);
    }
}
