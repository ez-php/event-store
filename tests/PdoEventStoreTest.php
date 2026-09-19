<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\EventStore\ConcurrencyException;
use EzPhp\EventStore\EventUpcasterInterface;
use EzPhp\EventStore\PdoEventStore;
use EzPhp\EventStore\StoredEvent;
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

    // ── upcasting ─────────────────────────────────────────────────────────────

    private function renameFieldUpcaster(string $type, string $from, string $to): EventUpcasterInterface
    {
        return new class ($type, $from, $to) implements EventUpcasterInterface {
            public function __construct(
                private readonly string $type,
                private readonly string $from,
                private readonly string $to,
            ) {
            }

            public function upcast(StoredEvent $event): StoredEvent
            {
                if ($event->eventType !== $this->type || !array_key_exists($this->from, $event->payload)) {
                    return $event;
                }

                $payload = $event->payload;
                $payload[$this->to] = $payload[$this->from];
                unset($payload[$this->from]);

                return new StoredEvent($event->streamId, $event->version, $event->eventType, $payload, $event->occurredAt);
            }
        };
    }

    public function test_load_without_upcasters_returns_events_unchanged(): void
    {
        $this->store->append('order-1', [new RecordedEvent('order.placed', ['total' => 10])]);

        self::assertSame(['total' => 10], $this->store->load('order-1')[0]->payload);
    }

    public function test_load_applies_an_upcaster_to_old_payloads(): void
    {
        $store = new PdoEventStore($this->pdo, [$this->renameFieldUpcaster('order.placed', 'total', 'amount')]);
        $store->append('order-1', [new RecordedEvent('order.placed', ['total' => 10])]);

        $events = $store->load('order-1');

        self::assertSame(['amount' => 10], $events[0]->payload);
        self::assertSame(1, $events[0]->version);
    }

    public function test_load_applies_multiple_upcasters_in_order(): void
    {
        $store = new PdoEventStore($this->pdo, [
            $this->renameFieldUpcaster('order.placed', 'total', 'amount'),
            $this->renameFieldUpcaster('order.placed', 'amount', 'gross'),
        ]);
        $store->append('order-1', [new RecordedEvent('order.placed', ['total' => 10])]);

        self::assertSame(['gross' => 10], $store->load('order-1')[0]->payload);
    }

    public function test_upcasters_leave_non_matching_events_alone(): void
    {
        $store = new PdoEventStore($this->pdo, [$this->renameFieldUpcaster('order.placed', 'total', 'amount')]);
        $store->append('order-1', [new RecordedEvent('order.paid', ['total' => 10])]);

        self::assertSame(['total' => 10], $store->load('order-1')[0]->payload);
    }

    public function test_upcasting_does_not_change_what_is_persisted(): void
    {
        $upcasting = new PdoEventStore($this->pdo, [$this->renameFieldUpcaster('order.placed', 'total', 'amount')]);
        $upcasting->append('order-1', [new RecordedEvent('order.placed', ['total' => 10])]);
        $upcasting->load('order-1');

        self::assertSame(['total' => 10], $this->store->load('order-1')[0]->payload);
    }
}
