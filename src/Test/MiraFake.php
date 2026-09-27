<?php

declare(strict_types=1);

namespace MiraFive\Symfony\Test;

use MiraFive\Mira;
use PHPUnit\Framework\Assert;

/**
 * Assertions over what the application tracked, for `mirafive.test: true`. Each helper flushes the client first, so
 * events still in the buffer count. Events are the wire events (PROTOCOL §3): name, time, userId, properties, …
 */
final readonly class MiraFake
{
    /** Used in test mode when no secret key is configured; it never leaves the process. */
    public const string KEY = 'mf_test0000_fake';

    public function __construct(private Mira $mira, private RecordingTransport $transport) {}

    /**
     * @return list<array<array-key, mixed>>
     */
    public function events(?string $name = null): array
    {
        $this->mira->flush();
        $events = [];

        foreach ($this->transport->batches() as $batch) {
            foreach (is_array($batch['events'] ?? null) ? $batch['events'] : [] as $event) {
                if (is_array($event) && ($name === null || ($event['name'] ?? null) === $name)) {
                    $events[] = $event;
                }
            }
        }

        return $events;
    }

    /**
     * @param  (callable(array<array-key, mixed>): bool)|null  $where  receives each wire event with that name
     */
    public function assertTracked(string $name, ?callable $where = null, ?int $times = null): void
    {
        $matches = $this->matching($name, $where);

        if ($times === null) {
            Assert::assertNotEmpty($matches, "The event \"{$name}\" was not tracked".($where === null ? '.' : ' with the expected details.'));

            return;
        }

        Assert::assertCount($times, $matches, "The event \"{$name}\" was tracked ".count($matches)." times, not {$times}.");
    }

    /**
     * @param  (callable(array<array-key, mixed>): bool)|null  $where
     */
    public function assertNotTracked(string $name, ?callable $where = null): void
    {
        Assert::assertEmpty($this->matching($name, $where), "The event \"{$name}\" was tracked.");
    }

    public function assertNothingTracked(): void
    {
        $names = array_map(static fn (array $event): mixed => $event['name'] ?? null, $this->events());

        Assert::assertEmpty($names, 'Events were tracked: '.implode(', ', array_filter($names, is_string(...))).'.');
    }

    /**
     * @param  array<string, mixed>|null  $traits  when given, the traits must equal these
     */
    public function assertIdentified(string $userId, ?array $traits = null): void
    {
        $this->assertTracked('$identify', static fn (array $event): bool => ($event['userId'] ?? null) === $userId
            && ($traits === null || ($event['properties'] ?? []) == $traits));
    }

    /**
     * Serves this flag document (FLAGS.md §3) to MiraFlags. Call it before the first flag read of the test.
     *
     * @param  string|array<string, mixed>  $document
     */
    public function serveFlags(string|array $document): void
    {
        $this->transport->serveFlags($document);
    }

    /**
     * @return list<array<array-key, mixed>> the recorded batches, decoded
     */
    public function batches(): array
    {
        $this->mira->flush();

        return $this->transport->batches();
    }

    /** Forgets everything recorded so far. */
    public function clear(): void
    {
        $this->mira->flush();
        $this->transport->clear();
    }

    public function transport(): RecordingTransport
    {
        return $this->transport;
    }

    /**
     * @param  (callable(array<array-key, mixed>): bool)|null  $where
     * @return list<array<array-key, mixed>>
     */
    private function matching(string $name, ?callable $where): array
    {
        return array_values(array_filter($this->events($name), static fn (array $event): bool => $where === null || $where($event)));
    }
}
