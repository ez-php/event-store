<?php

declare(strict_types=1);

namespace EzPhp\EventStore;

/**
 * Interface EventUpcasterInterface
 *
 * One step of schema evolution for stored events: transforms an event as it
 * was persisted (old event type / payload shape) into the shape current
 * code expects, at read time. The persisted rows are never rewritten — the
 * log stays append-only.
 *
 * Each upcaster handles one version step and returns the event unchanged
 * when it does not apply. A multi-version migration (v1 → v2 → v3) is a
 * list of upcasters given to {@see PdoEventStore} in order; each receives
 * the previous one's output.
 *
 * @package EzPhp\EventStore
 */
interface EventUpcasterInterface
{
    /**
     * Upcast a single event, or return it unchanged if this step does not apply.
     * The returned event should keep the input's stream id, version and timestamp.
     *
     * @param StoredEvent $event
     *
     * @return StoredEvent
     */
    public function upcast(StoredEvent $event): StoredEvent;
}
