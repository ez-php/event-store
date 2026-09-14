<?php

declare(strict_types=1);

namespace EzPhp\EventStore;

use EzPhp\Contracts\ContainerInterface;
use EzPhp\Contracts\DatabaseInterface;
use EzPhp\Contracts\ServiceProvider;

/**
 * Class EventStoreServiceProvider
 *
 * Binds {@see EventStoreInterface} to a {@see PdoEventStore} backed by the
 * application's {@see DatabaseInterface} connection.
 *
 * register() resolves DatabaseInterface lazily inside the binding closure
 * (per the "register() must not use other services" rule) rather than eagerly
 * in register() itself — if DatabaseInterface is never bound, resolving
 * EventStoreInterface simply fails with a clear ContainerException instead of
 * this provider degrading silently, since an event store with no database is
 * not a usable configuration.
 *
 * @package EzPhp\EventStore
 */
final class EventStoreServiceProvider extends ServiceProvider
{
    /**
     * Bind EventStoreInterface to the PDO-backed PdoEventStore.
     */
    public function register(): void
    {
        $this->app->bind(EventStoreInterface::class, function (ContainerInterface $app): EventStoreInterface {
            $db = $app->make(DatabaseInterface::class);

            return new PdoEventStore($db->getPdo());
        });
    }
}
