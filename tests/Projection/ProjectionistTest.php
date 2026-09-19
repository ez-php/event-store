<?php

declare(strict_types=1);

namespace Tests\Projection;

use EzPhp\EventStore\EventUpcasterInterface;
use EzPhp\EventStore\PdoEventStore;
use EzPhp\EventStore\Projection\Projectionist;
use EzPhp\EventStore\Projection\ProjectorInterface;
use EzPhp\EventStore\StoredEvent;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Support\RecordedEvent;
use Tests\TestCase;

#[CoversClass(Projectionist::class)]
#[UsesClass(PdoEventStore::class)]
final class ProjectionistTest extends TestCase
{
    public function test_replay_applies_events_to_every_projector_in_stream_order(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $store = new PdoEventStore($pdo);

        $store->append('order-1', [
            new RecordedEvent('order.placed', ['total' => 10]),
            new RecordedEvent('order.paid', ['method' => 'card']),
        ]);

        $projectorA = new class () implements ProjectorInterface {
            /** @var array<int, string> */
            public array $seen = [];

            public function project(StoredEvent $event): void
            {
                $this->seen[] = $event->eventType;
            }
        };

        $projectorB = new class () implements ProjectorInterface {
            /** @var array<int, string> */
            public array $seen = [];

            public function project(StoredEvent $event): void
            {
                $this->seen[] = $event->eventType;
            }
        };

        (new Projectionist($store))->replay('order-1', [$projectorA, $projectorB]);

        self::assertSame(['order.placed', 'order.paid'], $projectorA->seen);
        self::assertSame(['order.placed', 'order.paid'], $projectorB->seen);
    }

    public function test_replay_with_no_projectors_does_not_load_the_stream(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $store = new PdoEventStore($pdo);

        // No events appended — the table doesn't even exist yet. If replay()
        // called load() anyway it would still return [] harmlessly, so this
        // mainly documents the early-return as intentional, not load-bearing.
        (new Projectionist($store))->replay('order-1', []);

        self::assertSame(0, $store->getVersion('order-1'));
    }

    public function test_replay_from_version_resumes_a_projector_from_a_checkpoint(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $store = new PdoEventStore($pdo);

        $store->append('order-1', [
            new RecordedEvent('order.placed'),
            new RecordedEvent('order.paid'),
            new RecordedEvent('order.shipped'),
        ]);

        $projector = new class () implements ProjectorInterface {
            /** @var array<int, string> */
            public array $seen = [];

            public function project(StoredEvent $event): void
            {
                $this->seen[] = $event->eventType;
            }
        };

        (new Projectionist($store))->replay('order-1', [$projector], fromVersion: 1);

        self::assertSame(['order.paid', 'order.shipped'], $projector->seen);
    }

    public function test_replay_projects_upcasted_events_when_the_store_has_upcasters(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $upcaster = new class () implements EventUpcasterInterface {
            public function upcast(StoredEvent $event): StoredEvent
            {
                return $event->eventType === 'order.placed.v1'
                    ? new StoredEvent($event->streamId, $event->version, 'order.placed', $event->payload, $event->occurredAt)
                    : $event;
            }
        };
        $store = new PdoEventStore($pdo, [$upcaster]);
        $store->append('order-1', [new RecordedEvent('order.placed.v1', ['total' => 10])]);

        $projector = new class () implements ProjectorInterface {
            /** @var array<int, string> */
            public array $seen = [];

            public function project(StoredEvent $event): void
            {
                $this->seen[] = $event->eventType;
            }
        };

        (new Projectionist($store))->replay('order-1', [$projector]);

        self::assertSame(['order.placed'], $projector->seen);
    }
}
