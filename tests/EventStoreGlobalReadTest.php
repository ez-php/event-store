<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\EventStore\EventStoreException;
use EzPhp\EventStore\EventUpcasterInterface;
use EzPhp\EventStore\PdoEventStore;
use EzPhp\EventStore\PositionedEvent;
use EzPhp\EventStore\StoredEvent;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Support\RecordedEvent;

/**
 * PdoEventStore::readAll() on SQLite: global order across streams, paging by
 * position, upcasters applied like in load().
 */
#[CoversClass(PdoEventStore::class)]
#[CoversClass(PositionedEvent::class)]
#[UsesClass(StoredEvent::class)]
#[UsesClass(EventStoreException::class)]
final class EventStoreGlobalReadTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    private function storeWithInterleavedStreams(): PdoEventStore
    {
        $store = new PdoEventStore($this->pdo);
        $store->append('order-1', [new RecordedEvent('order.placed', ['n' => 1])]);
        $store->append('order-2', [new RecordedEvent('order.placed', ['n' => 2])]);
        $store->append('order-1', [new RecordedEvent('order.paid', ['n' => 3])]);

        return $store;
    }

    public function test_reads_every_stream_in_commit_order(): void
    {
        $events = $this->storeWithInterleavedStreams()->readAll();

        $this->assertSame([1, 2, 3], array_map(static fn (PositionedEvent $e): int => $e->position, $events));
        $this->assertSame(['order-1', 'order-2', 'order-1'], array_map(static fn (PositionedEvent $e): string => $e->event->streamId, $events));
        $this->assertSame(2, $events[2]->event->version);
        $this->assertSame(['n' => 3], $events[2]->event->payload);
    }

    public function test_pages_by_position_and_limit(): void
    {
        $store = $this->storeWithInterleavedStreams();

        $first = $store->readAll(0, 2);
        $rest = $store->readAll($first[1]->position, 2);

        $this->assertCount(2, $first);
        $this->assertCount(1, $rest);
        $this->assertSame(3, $rest[0]->position);
        $this->assertSame([], $store->readAll(3));
    }

    public function test_upcasters_apply_to_global_reads(): void
    {
        $upcaster = new class () implements EventUpcasterInterface {
            public function upcast(StoredEvent $event): StoredEvent
            {
                return new StoredEvent($event->streamId, $event->version, strtoupper($event->eventType), $event->payload, $event->occurredAt);
            }
        };
        $this->storeWithInterleavedStreams();

        $events = (new PdoEventStore($this->pdo, [$upcaster]))->readAll();

        $this->assertSame('ORDER.PLACED', $events[0]->event->eventType);
    }

    public function test_limit_below_one_is_rejected(): void
    {
        $this->expectException(EventStoreException::class);

        (new PdoEventStore($this->pdo))->readAll(0, 0);
    }
}
