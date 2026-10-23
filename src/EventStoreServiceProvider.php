<?php

declare(strict_types=1);

namespace EzPhp\EventStore;

use EzPhp\Contracts\ContainerInterface;
use EzPhp\Contracts\DatabaseInterface;
use EzPhp\Contracts\ServiceProvider;
use EzPhp\EventStore\Projection\CheckpointStoreInterface;
use EzPhp\EventStore\Projection\PdoCheckpointStore;

/**
 * Class EventStoreServiceProvider
 *
 * Binds {@see EventStoreInterface} to a {@see PdoEventStore} backed by the
 * application's {@see DatabaseInterface} connection; {@see GlobalEventReaderInterface}
 * to the same store and {@see Projection\CheckpointStoreInterface} to a
 * {@see Projection\PdoCheckpointStore} on that connection.
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
     * If an {@see UpcasterRegistry} is bound, its upcasters are applied on load().
     */
    public function register(): void
    {
        $this->app->bind(EventStoreInterface::class, function (ContainerInterface $app): EventStoreInterface {
            $db = $app->make(DatabaseInterface::class);

            $upcasters = $app->has(UpcasterRegistry::class)
                ? $app->make(UpcasterRegistry::class)->all()
                : [];

            return new PdoEventStore($db->getPdo(), $upcasters);
        });

        // The same store reads globally (PdoEventStore implements both contracts).
        $this->app->bind(
            GlobalEventReaderInterface::class,
            function (ContainerInterface $app): GlobalEventReaderInterface {
                $store = $app->make(EventStoreInterface::class);

                if (!$store instanceof GlobalEventReaderInterface) {
                    throw new EventStoreException(sprintf('%s does not implement %s; bind GlobalEventReaderInterface yourself.', $store::class, GlobalEventReaderInterface::class));
                }

                return $store;
            },
        );

        $this->app->bind(
            CheckpointStoreInterface::class,
            fn (ContainerInterface $app): CheckpointStoreInterface => new PdoCheckpointStore($app->make(DatabaseInterface::class)->getPdo()),
        );
    }
}
