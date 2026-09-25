<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use PDOStatement;

/**
 * SQLite PDO that simulates a concurrent writer: once armed, the first time the
 * event store prepares its INSERT it first appends a row for the same stream at
 * the version the store is about to write — exactly what a second process that
 * read the same current version would do between the store's read and insert.
 */
final class EventStoreRacingPdo extends PDO
{
    private ?string $raceStream = null;

    private int $raceVersion = 0;

    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Arm the race: the next INSERT prepared by the store is preceded by a competing row.
     */
    public function raceOnNextInsert(string $streamId, int $version): void
    {
        $this->raceStream = $streamId;
        $this->raceVersion = $version;
    }

    /**
     * @param array<int|string, mixed> $options
     */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->raceStream !== null && str_starts_with(ltrim($query), 'INSERT INTO event_store_events')) {
            $competitor = parent::prepare(
                "INSERT INTO event_store_events (stream_id, version, event_type, payload, occurred_at)
                 VALUES (?, ?, 'competitor', '{}', '2026-01-01 00:00:00.000000')"
            );
            $competitor->execute([$this->raceStream, $this->raceVersion]);
            $this->raceStream = null;
        }

        return parent::prepare($query, $options);
    }
}
