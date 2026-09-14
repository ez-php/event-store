<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Contracts\DatabaseInterface;
use EzPhp\EventStore\EventStoreInterface;
use EzPhp\EventStore\EventStoreServiceProvider;
use EzPhp\EventStore\PdoEventStore;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Support\EventStoreFakeContainer;

/**
 * @uses \Tests\Support\EventStoreFakeContainer
 */
#[CoversClass(EventStoreServiceProvider::class)]
#[UsesClass(PdoEventStore::class)]
final class EventStoreServiceProviderTest extends TestCase
{
    public function test_register_binds_event_store_interface(): void
    {
        $container = new EventStoreFakeContainer();
        $provider = new EventStoreServiceProvider($container);

        $provider->register();

        self::assertTrue($container->wasBound(EventStoreInterface::class));
    }

    public function test_resolving_event_store_interface_wires_it_to_the_database_pdo_connection(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $database = new class ($pdo) implements DatabaseInterface {
            public function __construct(private readonly PDO $pdo)
            {
            }

            public function query(string $sql, array $bindings = []): array
            {
                return [];
            }

            public function execute(string $sql, array $bindings = []): int
            {
                return 0;
            }

            public function transaction(callable $fn): mixed
            {
                return $fn();
            }

            public function getPdo(): PDO
            {
                return $this->pdo;
            }
        };

        $container = new EventStoreFakeContainer();
        $container->instance(DatabaseInterface::class, $database);

        $provider = new EventStoreServiceProvider($container);
        $provider->register();

        $store = $container->make(EventStoreInterface::class);

        self::assertInstanceOf(PdoEventStore::class, $store);
    }
}
