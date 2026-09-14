<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\EventStore\ConcurrencyException;
use EzPhp\EventStore\PdoEventStore;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Support\RecordedEvent;

#[CoversClass(PdoEventStore::class)]
#[UsesClass(ConcurrencyException::class)]
final class PdoEventStoreTest extends TestCase
{
    private PDO $pdo;

    private PdoEventStore $store;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->store = new PdoEventStore($this->pdo);
    }

    public function test_get_version_of_unknown_stream_is_zero(): void
    {
        self::assertSame(0, $this->store->getVersion('order-1'));
    }

    public function test_append_persists_events_in_order_and_advances_the_version(): void
    {
        $this->store->append('order-1', [
            new RecordedEvent('order.placed', ['total' => 10]),
            new RecordedEvent('order.paid', ['method' => 'card']),
        ]);

        self::assertSame(2, $this->store->getVersion('order-1'));

        $events = $this->store->load('order-1');

        self::assertCount(2, $events);
        self::assertSame(1, $events[0]->version);
        self::assertSame('order.placed', $events[0]->eventType);
        self::assertSame(['total' => 10], $events[0]->payload);
        self::assertSame(2, $events[1]->version);
        self::assertSame('order.paid', $events[1]->eventType);
        self::assertSame(['method' => 'card'], $events[1]->payload);
    }

    public function test_append_is_a_no_op_for_an_empty_event_list(): void
    {
        $this->store->append('order-1', []);

        self::assertSame(0, $this->store->getVersion('order-1'));
    }

    public function test_streams_are_isolated_from_each_other(): void
    {
        $this->store->append('order-1', [new RecordedEvent('order.placed')]);
        $this->store->append('order-2', [new RecordedEvent('order.placed')]);

        self::assertCount(1, $this->store->load('order-1'));
        self::assertCount(1, $this->store->load('order-2'));
        self::assertSame(1, $this->store->getVersion('order-1'));
        self::assertSame(1, $this->store->getVersion('order-2'));
    }

    public function test_load_from_version_skips_earlier_events(): void
    {
        $this->store->append('order-1', [
            new RecordedEvent('order.placed'),
            new RecordedEvent('order.paid'),
            new RecordedEvent('order.shipped'),
        ]);

        $events = $this->store->load('order-1', fromVersion: 1);

        self::assertCount(2, $events);
        self::assertSame('order.paid', $events[0]->eventType);
        self::assertSame('order.shipped', $events[1]->eventType);
    }

    public function test_append_with_matching_expected_version_succeeds(): void
    {
        $this->store->append('order-1', [new RecordedEvent('order.placed')]);

        $this->store->append('order-1', [new RecordedEvent('order.paid')], expectedVersion: 1);

        self::assertSame(2, $this->store->getVersion('order-1'));
    }

    public function test_append_with_stale_expected_version_throws_concurrency_exception(): void
    {
        $this->store->append('order-1', [new RecordedEvent('order.placed')]);

        try {
            $this->store->append('order-1', [new RecordedEvent('order.paid')], expectedVersion: 0);
            self::fail('Expected ConcurrencyException was not thrown.');
        } catch (ConcurrencyException $e) {
            self::assertSame('order-1', $e->streamId);
            self::assertSame(0, $e->expectedVersion);
            self::assertSame(1, $e->actualVersion);
        }

        // The failed append must not have written anything.
        self::assertSame(1, $this->store->getVersion('order-1'));
    }

    public function test_append_against_a_new_stream_with_expected_version_zero_succeeds(): void
    {
        $this->store->append('order-1', [new RecordedEvent('order.placed')], expectedVersion: 0);

        self::assertSame(1, $this->store->getVersion('order-1'));
    }
}
