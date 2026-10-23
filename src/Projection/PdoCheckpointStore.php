<?php

declare(strict_types=1);

namespace EzPhp\EventStore\Projection;

use EzPhp\EventStore\EventStoreException;
use PDO;
use Throwable;

/**
 * Class PdoCheckpointStore
 *
 * Checkpoints in an `event_store_checkpoints` table (projection name primary
 * key, last position), auto-created on first use like `event_store_events`
 * (SQLite for tests, MySQL in production; prefer a migration there).
 * save() is one driver-aware upsert.
 *
 * Production schema (MySQL):
 *
 *   CREATE TABLE event_store_checkpoints (
 *       projection VARCHAR(255)    NOT NULL PRIMARY KEY,
 *       position   BIGINT UNSIGNED NOT NULL
 *   );
 *
 * @package EzPhp\EventStore\Projection
 */
final class PdoCheckpointStore implements CheckpointStoreInterface
{
    private bool $tableChecked = false;

    /**
     * @param PDO $pdo
     */
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $projection): int
    {
        $this->ensureTable();

        try {
            $stmt = $this->pdo->prepare('SELECT position FROM event_store_checkpoints WHERE projection = ?');
            $stmt->execute([$projection]);
            $position = $stmt->fetchColumn();
        } catch (Throwable $e) {
            throw new EventStoreException("Failed to read checkpoint \"{$projection}\": {$e->getMessage()}", 0, $e);
        }

        return is_numeric($position) ? (int) $position : 0;
    }

    /**
     * {@inheritdoc}
     */
    public function save(string $projection, int $position): void
    {
        $this->ensureTable();

        $sql = 'INSERT INTO event_store_checkpoints (projection, position) VALUES (:projection, :position)';
        $sql .= $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? ' ON DUPLICATE KEY UPDATE position = :new_position'
            : ' ON CONFLICT (projection) DO UPDATE SET position = excluded.position';
        $bindings = ['projection' => $projection, 'position' => $position];

        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $bindings['new_position'] = $position;
        }

        try {
            $this->pdo->prepare($sql)->execute($bindings);
        } catch (Throwable $e) {
            throw new EventStoreException("Failed to save checkpoint \"{$projection}\": {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Create the event_store_checkpoints table if it does not exist yet.
     *
     * @return void
     */
    public function ensureTable(): void
    {
        if ($this->tableChecked) {
            return;
        }

        try {
            $this->pdo->exec(
                $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                    ? 'CREATE TABLE IF NOT EXISTS event_store_checkpoints (
                           projection VARCHAR(255)    NOT NULL PRIMARY KEY,
                           position   BIGINT UNSIGNED NOT NULL
                       )'
                    : 'CREATE TABLE IF NOT EXISTS event_store_checkpoints (
                           projection TEXT    NOT NULL PRIMARY KEY,
                           position   INTEGER NOT NULL
                       )'
            );
        } catch (Throwable $e) {
            throw new EventStoreException("Failed to create event_store_checkpoints table: {$e->getMessage()}", 0, $e);
        }

        $this->tableChecked = true;
    }
}
