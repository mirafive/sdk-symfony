<?php

declare(strict_types=1);

namespace MiraFive\Symfony\Tests\Support;

use MiraFive\Http\Response;
use MiraFive\Http\Transport;

final class SpyTransport implements Transport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string|null}> */
    public array $requests = [];

    /** @var list<Response> */
    public array $answers = [];

    public function request(string $method, string $url, array $headers, ?string $body, int $timeoutMs, int $connectTimeoutMs = 1_000): Response
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');

        return array_shift($this->answers) ?? match (true) {
            str_ends_with($url, '/v1/flags') => new Response(200, [], '{"at":1,"flags":{},"v":1}'),
            default => new Response(202, [], '{"batch":"x","accepted":1,"dropped":0}'),
        };
    }

    /**
     * @return list<array{method: string, url: string, headers: array<string, string>, body: string|null}>
     */
    public function batches(): array
    {
        return array_values(array_filter($this->requests, static fn (array $request): bool => str_ends_with($request['url'], '/v1/batch')));
    }

    /**
     * @return list<string>
     */
    public function eventNames(): array
    {
        $names = [];

        foreach ($this->batches() as $request) {
            $batch = json_decode((string) $request['body'], true);

            foreach (is_array($batch) && is_array($batch['events'] ?? null) ? $batch['events'] : [] as $event) {
                $names[] = is_array($event) ? (string) ($event['name'] ?? '') : '';
            }
        }

        return $names;
    }
}
