<?php

declare(strict_types=1);

namespace EzPhp\EventStore;

use RuntimeException;

/**
 * Class EventStoreException
 *
 * Base exception for all event store failures. Exists specifically to be
 * extended (e.g. {@see ConcurrencyException}), so it is a documented carve-out
 * from the "concrete classes are final" rule.
 *
 * @package EzPhp\EventStore
 */
class EventStoreException extends RuntimeException
{
}
