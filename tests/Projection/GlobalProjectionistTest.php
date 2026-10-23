<?php

declare(strict_types=1);

namespace Tests\Projection;

use EzPhp\EventStore\EventStoreException;
use EzPhp\EventStore\PdoEventStore;
use EzPhp\EventStore\PositionedEvent;
use EzPhp\EventStore\Projection\GlobalProjectionist;
use EzPhp\EventStore\Projection\InMemoryCheckpointStore;
use EzPhp\EventStore\Projection\PdoCheckpointStore;
use EzPhp\EventStore\Projection\ProjectorInterface;
use EzPhp\EventStore\StoredEvent;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Tests\Support\RecordedEvent;
use Tests\TestCase;

/**
 * GlobalProjectionist over a SQLite PdoEventStore with both checkpoint stores:
 * resume from the checkpoint, paging, at-least-once on a failing projector, reset.
 */
#[CoversClass(GlobalProjectionist::class)]
#[CoversClass(InMemoryCheckpointStore::class)]
#[CoversClass(PdoCheckpointStore::class)]
#[UsesClass(PdoEventStore::class)]
#[UsesClass(PositionedEvent::class)]
#[UsesClass(StoredEvent::class)]
#[UsesClass(EventStoreException::class)]
final class GlobalProjectionistTest extends TestCase
{
    private PDO $pdo;

    private PdoEventStore $store;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->store = new PdoEventStore($this->pdo);

        foreach (['a', 'b', 'a', 'c', 'b'] as $i => $stream) {
            $this->store->append($stream, [new RecordedEvent('e', ['i' => $i + 1])]);
        }
    }

    /**
     * @return ProjectorInterface&object{seen: list<int>}
     */
    private function recorder(?int $failAt = null): ProjectorInterface
    {
        return new class ($failAt) implements ProjectorInterface {
            /** @var list<int> */
            public array $seen = [];

            public function __construct(private readonly ?int $failAt)
            {
            }

            public function project(StoredEvent $event): void
            {
                $i = is_int($event->payload['i'] ?? null) ? $event->payload['i'] : 0;

                if ($i === $this->failAt) {
                    throw new RuntimeException("projector failed at {$i}");
                }

                $this->seen[] = $i;
            }
        };
    }

    public function test_projects_every_stream_in_order_and_resumes_from_the_checkpoint(): void
    {
        $checkpoints = new InMemoryCheckpointStore();
        $projectionist = new GlobalProjectionist($this->store, $checkpoints, batchSize: 2);
        $projector = $this->recorder();

        $this->assertSame(5, $projectionist->run('all', [$projector]));
        $this->assertSame([1, 2, 3, 4, 5], $projector->seen);
        $this->assertSame(5, $checkpoints->get('all'));

        $this->store->append('d', [new RecordedEvent('e', ['i' => 6])]);
        $later = $this->recorder();

        $this->assertSame(1, $projectionist->run('all', [$later]));
        $this->assertSame([6], $later->seen);
        $this->assertSame(0, $projectionist->run('all', [$later]));
    }

    public function test_projections_have_independent_checkpoints_and_max_events(): void
    {
        $checkpoints = new InMemoryCheckpointStore();
        $projectionist = new GlobalProjectionist($this->store, $checkpoints, batchSize: 2);

        $this->assertSame(2, $projectionist->run('first', [$this->recorder()], maxEvents: 2));
        $this->assertSame(2, $checkpoints->get('first'));
        $this->assertSame(0, $checkpoints->get('second'));
    }

    public function test_a_failing_projector_leaves_the_checkpoint_at_the_last_full_page(): void
    {
        $checkpoints = new PdoCheckpointStore($this->pdo);
        $projectionist = new GlobalProjectionist($this->store, $checkpoints, batchSize: 2);

        try {
            $projectionist->run('orders', [$this->recorder(failAt: 4)]);
            $this->fail('Expected the projector exception.');
        } catch (RuntimeException $e) {
            $this->assertSame('projector failed at 4', $e->getMessage());
        }

        $this->assertSame(2, $checkpoints->get('orders')); // page [3, 4] is repeated next run

        $retry = $this->recorder();
        $projectionist->run('orders', [$retry]);

        $this->assertSame([3, 4, 5], $retry->seen);
        $this->assertSame(5, (new PdoCheckpointStore($this->pdo))->get('orders'));
    }

    public function test_reset_rebuilds_from_the_first_event(): void
    {
        $checkpoints = new PdoCheckpointStore($this->pdo);
        $projectionist = new GlobalProjectionist($this->store, $checkpoints);
        $projectionist->run('rebuild', [$this->recorder()]);

        $projectionist->reset('rebuild');
        $again = $this->recorder();
        $projectionist->run('rebuild', [$again]);

        $this->assertSame([1, 2, 3, 4, 5], $again->seen);
    }

    public function test_batch_size_below_one_is_rejected(): void
    {
        $this->expectException(EventStoreException::class);

        new GlobalProjectionist($this->store, new InMemoryCheckpointStore(), 0);
    }
}
