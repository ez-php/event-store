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
 * @package EzPhp\EventStore\Projection
 */
final readonly class GlobalProjectionist
{
    /**
     * @param GlobalEventReaderInterface $reader
     * @param CheckpointStoreInterface   $checkpoints
     * @param int                        $batchSize   Events per readAll() page and per checkpoint save.
     *
     * @throws EventStoreException When the batch size is below 1.
     */
    public function __construct(
        private GlobalEventReaderInterface $reader,
        private CheckpointStoreInterface $checkpoints,
        private int $batchSize = 500,
    ) {
        if ($batchSize < 1) {
            throw new EventStoreException('GlobalProjectionist needs a batch size of at least 1.');
        }
    }

    /**
     * Project every event after the checkpoint of $projection.
     *
     * @param string                   $projection Checkpoint name, e.g. 'order-list'.
     * @param list<ProjectorInterface> $projectors Called in order for every event.
     * @param int|null                 $maxEvents  Stop after about this many events (whole pages); null = until caught up.
     *
     * @return int Number of events projected in this run.
     */
    public function run(string $projection, array $projectors, ?int $maxEvents = null): int
    {
        $position = $this->checkpoints->get($projection);
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

            $this->checkpoints->save($projection, $position);

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
