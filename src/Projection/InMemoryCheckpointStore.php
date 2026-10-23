<?php

declare(strict_types=1);

namespace EzPhp\EventStore\Projection;

/**
 * Class InMemoryCheckpointStore
 *
 * Process-local checkpoints for tests and for projections rebuilt from scratch
 * on every start.
 *
 * @package EzPhp\EventStore\Projection
 */
final class InMemoryCheckpointStore implements CheckpointStoreInterface
{
    /**
     * @var array<string, int>
     */
    private array $positions = [];

    /**
     * {@inheritdoc}
     */
    public function get(string $projection): int
    {
        return $this->positions[$projection] ?? 0;
    }

    /**
     * {@inheritdoc}
     */
    public function save(string $projection, int $position): void
    {
        $this->positions[$projection] = $position;
    }
}
