<?php

declare(strict_types=1);

namespace EzPhp\EventStore;

/**
 * Class UpcasterRegistry
 *
 * Ordered list of {@see EventUpcasterInterface}s an application wants applied
 * to every event read through the container-bound {@see EventStoreInterface}.
 * Bind an instance (`$app->instance(UpcasterRegistry::class, ...)`) and
 * {@see EventStoreServiceProvider} hands its upcasters to the PdoEventStore;
 * with no binding, no upcasting happens.
 *
 * @package EzPhp\EventStore
 */
final readonly class UpcasterRegistry
{
    /**
     * @param list<EventUpcasterInterface> $upcasters Applied in this order.
     */
    public function __construct(private array $upcasters = [])
    {
    }

    /**
     * @return list<EventUpcasterInterface>
     */
    public function all(): array
    {
        return $this->upcasters;
    }
}
