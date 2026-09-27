<?php

declare(strict_types=1);

namespace MiraFive\Symfony\Test;

use JsonException;
use MiraFive\Http\Response;
use MiraFive\Http\Transport;
use Throwable;

/**
 * The transport of `mirafive.test`: records every batch and answers like MIRA FIVE, without the network. Serves the
 * flag document set with `serveFlags()`, empty otherwise.
 */
final class RecordingTransport implements Transport
{
    /** @var list<array<array-key, mixed>> decoded batches, in the order they were sent */
    private array $batches = [];

    /** @var list<Response|Throwable> */
    private array $answers = [];

    private ?string $flags = null;

    public function request(string $method, string $url, array $headers, ?string $body, int $timeoutMs): Response
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ($method === 'POST' && str_ends_with($path, '/v1/batch')) {
            return $this->batch($body ?? '');
        }

        if ($method === 'GET' && str_ends_with($path, '/v1/flags')) {
            return new Response(200, ['ETag' => 'W/"f-test"'], $this->flags ?? '{"at":'.(int) floor(microtime(true) * 1000).',"flags":{},"v":1}');
        }

        if ($method === 'POST' && str_ends_with($path, '/v1/flags/segments')) {
            return new Response(200, [], '{"units":[{"segments":[],"unavailable":[]}]}');
        }

        return new Response(404, [], '{"code":"not_found","detail":"not served by the MIRA FIVE test transport"}');
    }

    /** Answers the next batch with this instead of a 202, e.g. `new Response(429, ['Retry-After' => '1'])`. */
    public function answerNextBatch(Response|Throwable $answer): void
    {
        $this->answers[] = $answer;
    }

    /**
     * @param  string|array<string, mixed>  $document  a server flag document (FLAGS.md §3), JSON or decoded
     */
    public function serveFlags(string|array $document): void
    {
        $this->flags = is_string($document) ? $document : json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    public function batches(): array
    {
        return $this->batches;
    }

    public function clear(): void
    {
        $this->batches = [];
        $this->answers = [];
    }

    private function batch(string $body): Response
    {
        $answer = array_shift($this->answers);

        if ($answer instanceof Throwable) {
            throw $answer;
        }

        if ($answer instanceof Response) {
            return $answer;
        }

        try {
            $batch = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new Response(400, [], '{"code":"invalid_json","detail":"the body is not JSON"}');
        }

        $events = is_array($batch) ? ($batch['events'] ?? null) : null;

        if (! is_array($batch) || ! is_array($events)) {
            return new Response(400, [], '{"code":"validation_failed","detail":"not a batch"}');
        }

        $this->batches[] = $batch;
        $checks = count(array_filter($events, static fn (mixed $event): bool => is_array($event) && ($event['name'] ?? null) === '$install_check'));

        return new Response(202, [], (string) json_encode([
            'batch' => $batch['batch'] ?? null,
            'accepted' => $checks > 0 ? 0 : count($events),
            'dropped' => $checks > 0 ? count($events) : 0,
            'reason' => $checks > 0 ? 'install_check' : null,
        ]));
    }
}
