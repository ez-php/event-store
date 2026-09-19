<?php

declare(strict_types=1);

namespace EzPhp\EventStore;

use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Class PdoEventStore
 *
 * Append-only, PDO-backed implementation of {@see EventStoreInterface}.
 *
 * Production schema (MySQL):
 *
 *   CREATE TABLE event_store_events (
 *       id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 *       stream_id   VARCHAR(255)    NOT NULL,
 *       version     INT UNSIGNED    NOT NULL,
 *       event_type  VARCHAR(255)    NOT NULL,
 *       payload     JSON            NOT NULL,
 *       occurred_at DATETIME(6)     NOT NULL,
 *       UNIQUE      uniq_stream_version (stream_id, version),
 *       INDEX       idx_stream (stream_id)
 *   );
 *
 * ensureTable() auto-creates the table when it does not yet exist, adapting the
 * DDL to the PDO driver (SQLite for tests, MySQL for production). In production,
 * prefer running a proper migration instead of relying on ensureTable().
 *
 * Concurrency: `append()` reads the stream's current version and compares it to
 * `$expectedVersion` inside a transaction before inserting, then relies on the
 * `uniq_stream_version` unique index to reject a genuine race (two transactions
 * both passing the version check) as a last line of defence.
 *
 * @package EzPhp\EventStore
 */
final class PdoEventStore implements EventStoreInterface
{
    private bool $tableChecked = false;

    /**
     * @param PDO                         $pdo
     * @param list<EventUpcasterInterface> $upcasters Applied in order to every event returned by load(); persisted rows are never modified.
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly array $upcasters = [],
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function append(string $streamId, array $events, ?int $expectedVersion = null): void
    {
        if ($events === []) {
            return;
        }

        $this->ensureTable();

        $ownsTransaction = !$this->pdo->inTransaction();

        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $currentVersion = $this->getVersion($streamId);

            if ($expectedVersion !== null && $expectedVersion !== $currentVersion) {
                throw new ConcurrencyException($streamId, $expectedVersion, $currentVersion);
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO event_store_events (stream_id, version, event_type, payload, occurred_at)
                 VALUES (?, ?, ?, ?, ?)'
            );

            $version = $currentVersion;

            foreach ($events as $event) {
                $version++;

                $stmt->execute([
                    $streamId,
                    $version,
                    $event->eventType(),
                    json_encode($event->payload(), JSON_THROW_ON_ERROR),
                    (new DateTimeImmutable())->format('Y-m-d H:i:s.u'),
                ]);
            }

            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (ConcurrencyException $e) {
            if ($ownsTransaction) {
                $this->pdo->rollBack();
            }

            throw $e;
        } catch (Throwable $e) {
            if ($ownsTransaction) {
                $this->pdo->rollBack();
            }

            throw new EventStoreException("Failed to append to stream \"{$streamId}\": {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function load(string $streamId, int $fromVersion = 0): array
    {
        $this->ensureTable();

        try {
            $stmt = $this->pdo->prepare(
                'SELECT version, event_type, payload, occurred_at
                 FROM event_store_events
                 WHERE stream_id = ? AND version > ?
                 ORDER BY version ASC'
            );
            $stmt->execute([$streamId, $fromVersion]);
        } catch (Throwable $e) {
            throw new EventStoreException("Failed to load stream \"{$streamId}\": {$e->getMessage()}", 0, $e);
        }

        $events = [];

        /** @var array{version: int|string, event_type: string, payload: string, occurred_at: string} $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            /** @var mixed $decoded */
            $decoded = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);

            $event = new StoredEvent(
                streamId: $streamId,
                version: (int) $row['version'],
                eventType: $row['event_type'],
                payload: is_array($decoded) ? $decoded : [],
                occurredAt: new DateTimeImmutable($row['occurred_at']),
            );

            foreach ($this->upcasters as $upcaster) {
                $event = $upcaster->upcast($event);
            }

            $events[] = $event;
        }

        return $events;
    }

    /**
     * {@inheritdoc}
     */
    public function getVersion(string $streamId): int
    {
        $this->ensureTable();

        try {
            $stmt = $this->pdo->prepare('SELECT MAX(version) AS current_version FROM event_store_events WHERE stream_id = ?');
            $stmt->execute([$streamId]);
        } catch (Throwable $e) {
            throw new EventStoreException("Failed to read version of stream \"{$streamId}\": {$e->getMessage()}", 0, $e);
        }

        /** @var array{current_version: int|string|null}|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false || $row['current_version'] === null ? 0 : (int) $row['current_version'];
    }

    /**
     * Create the event_store_events table if it does not yet exist.
     * Runs at most once per PdoEventStore instance. Adapts DDL to the PDO driver.
     */
    public function ensureTable(): void
    {
        if ($this->tableChecked) {
            return;
        }

        $this->tableChecked = true;

        try {
            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

            if ($driver === 'sqlite') {
                $this->pdo->exec(
                    'CREATE TABLE IF NOT EXISTS event_store_events (
                        id          INTEGER PRIMARY KEY AUTOINCREMENT,
                        stream_id   TEXT    NOT NULL,
                        version     INTEGER NOT NULL,
                        event_type  TEXT    NOT NULL,
                        payload     TEXT    NOT NULL,
                        occurred_at TEXT    NOT NULL,
                        UNIQUE (stream_id, version)
                    )'
                );
                $this->pdo->exec(
                    'CREATE INDEX IF NOT EXISTS idx_event_store_stream ON event_store_events (stream_id)'
                );
            } else {
                $this->pdo->exec(
                    'CREATE TABLE IF NOT EXISTS event_store_events (
                        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        stream_id   VARCHAR(255)    NOT NULL,
                        version     INT UNSIGNED    NOT NULL,
                        event_type  VARCHAR(255)    NOT NULL,
                        payload     JSON            NOT NULL,
                        occurred_at DATETIME(6)     NOT NULL,
                        UNIQUE      uniq_stream_version (stream_id, version),
                        INDEX       idx_stream (stream_id)
                    )'
                );
            }
        } catch (Throwable $e) {
            throw new EventStoreException("Failed to create event_store_events table: {$e->getMessage()}", 0, $e);
        }
    }
}
