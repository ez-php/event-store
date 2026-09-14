<?php

declare(strict_types=1);

namespace Tests;

use DateTimeImmutable;
use EzPhp\EventStore\StoredEvent;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(StoredEvent::class)]
final class StoredEventTest extends TestCase
{
    public function test_it_exposes_its_constructor_arguments_as_readonly_properties(): void
    {
        $occurredAt = new DateTimeImmutable('2026-01-01 12:00:00');

        $event = new StoredEvent(
            streamId: 'order-1',
            version: 3,
            eventType: 'order.placed',
            payload: ['total' => 42],
            occurredAt: $occurredAt,
        );

        self::assertSame('order-1', $event->streamId);
        self::assertSame(3, $event->version);
        self::assertSame('order.placed', $event->eventType);
        self::assertSame(['total' => 42], $event->payload);
        self::assertSame($occurredAt, $event->occurredAt);
    }
}
