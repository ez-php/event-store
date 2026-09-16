<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Events\EventInterface;
use EzPhp\EventStore\AppendToStreamListener;
use EzPhp\EventStore\DomainEvent;
use EzPhp\EventStore\EventStoreInterface;
use EzPhp\EventStore\StoredEvent;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Application event fixture implementing both EventInterface (dispatchable
 * via ez-php/events) and DomainEvent (appendable to an event store).
 */
final readonly class AppendListenerFixtureEvent implements EventInterface, DomainEvent
{
    public function __construct(
        private int $orderId,
    ) {
    }

    public function eventType(): string
    {
        return 'order.placed';
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return ['order_id' => $this->orderId];
    }

    public function orderId(): int
    {
        return $this->orderId;
    }
}

/**
 * Plain ez-php/events event that is NOT appendable — used to verify the
 * listener ignores events that don't implement DomainEvent.
 */
final class AppendListenerNonDomainEvent implements EventInterface
{
}

/**
 * Spy EventStoreInterface recording every append() call.
 */
final class AppendListenerSpyStore implements EventStoreInterface
{
    /** @var list<array{streamId: string, events: list<DomainEvent>, expectedVersion: int|null}> */
    public array $appended = [];

    public function append(string $streamId, array $events, ?int $expectedVersion = null): void
    {
        $this->appended[] = ['streamId' => $streamId, 'events' => $events, 'expectedVersion' => $expectedVersion];
    }

    /**
     * @return list<StoredEvent>
     */
    public function load(string $streamId, int $fromVersion = 0): array
    {
        return [];
    }

    public function getVersion(string $streamId): int
    {
        return 0;
    }
}

#[CoversClass(AppendToStreamListener::class)]
final class AppendToStreamListenerTest extends TestCase
{
    public function testHandleAppendsDomainEventToResolvedStream(): void
    {
        $store = new AppendListenerSpyStore();
        $listener = new AppendToStreamListener(
            $store,
            fn (DomainEvent $event): string => 'order-' . ($event instanceof AppendListenerFixtureEvent ? $event->orderId() : 'unknown'),
        );

        $event = new AppendListenerFixtureEvent(42);
        $listener->handle($event);

        $this->assertCount(1, $store->appended);
        $this->assertSame('order-42', $store->appended[0]['streamId']);
        $this->assertSame([$event], $store->appended[0]['events']);
    }

    public function testHandleIgnoresEventsThatAreNotDomainEvents(): void
    {
        $store = new AppendListenerSpyStore();
        $listener = new AppendToStreamListener($store, fn (DomainEvent $event): string => 'irrelevant');

        $listener->handle(new AppendListenerNonDomainEvent());

        $this->assertSame([], $store->appended);
    }
}
