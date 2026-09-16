<?php

declare(strict_types=1);

namespace EzPhp\EventStore;

use Closure;
use EzPhp\Events\EventInterface;
use EzPhp\Events\ListenerInterface;

/**
 * Class AppendToStreamListener
 *
 * Bridges ez-php/events dispatch into an EventStoreInterface stream —
 * the glue this module's README points to ("composes naturally with
 * ez-php/audit") but does not ship, since callers previously had to call
 * append() by hand in every listener.
 *
 * The application's event class must implement both EventInterface (to be
 * dispatchable) and DomainEvent (to be appendable); events that only
 * implement EventInterface are silently ignored, mirroring how
 * ez-php/audit's AuditListener ignores event types it doesn't recognise.
 *
 * Registered by the application, not auto-wired:
 *
 *   $dispatcher->listen(OrderPlaced::class, new AppendToStreamListener(
 *       $store,
 *       fn (DomainEvent $event) => 'order-' . $event->orderId(),
 *   ));
 *
 * Requires: ez-php/events (soft dependency — require-dev only; this class
 * is only autoloaded when actually referenced).
 *
 * @package EzPhp\EventStore
 */
final class AppendToStreamListener implements ListenerInterface
{
    /**
     * @param EventStoreInterface          $store
     * @param Closure(DomainEvent): string $streamIdResolver Derives the target stream id from the event.
     */
    public function __construct(
        private readonly EventStoreInterface $store,
        private readonly Closure $streamIdResolver,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function handle(EventInterface $event): void
    {
        if (!$event instanceof DomainEvent) {
            return;
        }

        $streamId = ($this->streamIdResolver)($event);

        $this->store->append($streamId, [$event]);
    }
}
