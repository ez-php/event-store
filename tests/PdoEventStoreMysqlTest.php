<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\EventStore\ConcurrencyException;
use EzPhp\EventStore\PdoEventStore;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Support\RecordedEvent;

/**
 * PdoEventStore against MySQL — the production DDL branch (JSON payload,
 * DATETIME(6), named unique key) that the SQLite-backed PdoEventStoreTest
 * never reaches.
 *
 * Skipped unless DB_HOST is set (Docker: `db`; CI: the job's MySQL service).
 * Uses DB_TESTING_DATABASE, falling back to DB_DATABASE. The table is dropped
 * before and after each test because MySQL DDL commits implicitly.
 */
#[CoversClass(PdoEventStore::class)]
#[UsesClass(ConcurrencyException::class)]
final class PdoEventStoreMysqlTest extends TestCase
{
    private PDO $pdo;

    private PdoEventStore $store;

    protected function setUp(): void
    {
        $host = (string) getenv('DB_HOST');

        if ($host === '') {
            self::markTestSkipped('MySQL not available — set DB_HOST to run this test.');
        }

        $port = (string) (getenv('DB_PORT') ?: '3306');
        $database = (string) (getenv('DB_TESTING_DATABASE') ?: getenv('DB_DATABASE'));

        try {
            $this->pdo = new PDO(
                "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
                (string) getenv('DB_USERNAME'),
                (string) getenv('DB_PASSWORD'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        } catch (PDOException $e) {
            self::markTestSkipped("MySQL not reachable at {$host}:{$port}: {$e->getMessage()}");
        }

        $this->pdo->exec('DROP TABLE IF EXISTS event_store_events');
        $this->store = new PdoEventStore($this->pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->exec('DROP TABLE IF EXISTS event_store_events');
        }
    }

    public function test_ensure_table_creates_the_mysql_schema(): void
    {
        $this->store->ensureTable();

        $columns = [];

        foreach ($this->pdo->query('SHOW COLUMNS FROM event_store_events') ?: [] as $row) {
            /** @var array{Field: string, Type: string} $row */
            $columns[$row['Field']] = strtolower($row['Type']);
        }

        self::assertSame('json', $columns['payload'] ?? null);
        self::assertSame('datetime(6)', $columns['occurred_at'] ?? null);
        self::assertStringStartsWith('bigint', $columns['id'] ?? '');

        $unique = $this->pdo->query("SHOW INDEX FROM event_store_events WHERE Key_name = 'uniq_stream_version'");
        self::assertNotFalse($unique);
        self::assertSame(['stream_id', 'version'], array_column($unique->fetchAll(PDO::FETCH_ASSOC), 'Column_name'));
    }

    public function test_append_and_load_round_trip(): void
    {
        $this->store->append('order-1', [
            new RecordedEvent('order.placed', ['total' => 10, 'items' => ['a', 'b']]),
            new RecordedEvent('order.paid', ['method' => 'card']),
        ]);
        $this->store->append('order-2', [new RecordedEvent('order.placed')]);

        self::assertSame(2, $this->store->getVersion('order-1'));
        self::assertSame(1, $this->store->getVersion('order-2'));

        $events = $this->store->load('order-1');

        self::assertCount(2, $events);
        self::assertSame(1, $events[0]->version);
        self::assertSame('order.placed', $events[0]->eventType);
        // MySQL's JSON type normalises object key order, so compare order-insensitively.
        self::assertEquals(['total' => 10, 'items' => ['a', 'b']], $events[0]->payload);
        self::assertSame(['method' => 'card'], $events[1]->payload);

        $later = $this->store->load('order-1', fromVersion: 1);
        self::assertCount(1, $later);
        self::assertSame('order.paid', $later[0]->eventType);
    }

    public function test_append_with_stale_expected_version_throws_and_writes_nothing(): void
    {
        $this->store->append('order-1', [new RecordedEvent('order.placed')]);

        try {
            $this->store->append('order-1', [new RecordedEvent('order.paid')], expectedVersion: 0);
            self::fail('Expected ConcurrencyException was not thrown.');
        } catch (ConcurrencyException $e) {
            self::assertSame(1, $e->actualVersion);
        }

        self::assertSame(1, $this->store->getVersion('order-1'));
    }

    public function test_unique_key_rejects_a_duplicate_stream_version(): void
    {
        $this->store->append('order-1', [new RecordedEvent('order.placed')]);

        $this->expectException(PDOException::class);

        $this->pdo->exec(
            "INSERT INTO event_store_events (stream_id, version, event_type, payload, occurred_at)
             VALUES ('order-1', 1, 'order.placed', '{}', NOW(6))"
        );
    }
}
