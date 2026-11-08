<?php

declare(strict_types=1);

namespace MiraFive\Symfony;

use Closure;
use InvalidArgumentException;
use MiraFive\Http\CurlTransport;
use MiraFive\Http\StreamTransport;
use MiraFive\Http\Transport;
use MiraFive\Mira;
use MiraFive\Symfony\Messenger\DeliverBatch;
use MiraFive\Testing\MiraFake;
use MiraFive\Testing\RecordingTransport;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * @internal Builds the one client from the bundle configuration and remembers it, so only a client
 * that exists is flushed. Tagged kernel.reset: it exists whenever the client does, so a reset
 * between requests (FrankenPHP, RoadRunner) or Messenger messages always reaches it.
 */
final class ClientFactory implements ResetInterface
{
    private ?Mira $mira = null;

    public function __construct(
        private readonly ?string $secretKey,
        private readonly ?string $host,
        private readonly bool $enabled,
        private readonly Transport $transport,
        private readonly int $timeoutMs = 3_000,
        private readonly int $flushDeadlineMs = 3_000,
        private readonly int $maxRetries = 2,
        private readonly ?MessageBusInterface $bus = null,
        private readonly ?object $cache = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $test = false,
    ) {}

    public static function defaultTransport(): Transport
    {
        return extension_loaded('curl') ? new CurlTransport : new StreamTransport;
    }

    /** `enabled` as configured, whatever the keys; decides whether the script tag is printed. */
    public function switchedOn(): bool
    {
        return $this->test || $this->enabled;
    }

    public function secretKey(): string
    {
        $key = trim($this->secretKey ?? '');

        return $key === '' && $this->test ? MiraFake::KEY : $key;
    }

    public function host(): string
    {
        $host = rtrim(trim($this->host ?? ''), '/');

        return $host === '' ? Mira::DEFAULT_HOST : $host;
    }

    public function queued(): bool
    {
        return $this->bus !== null;
    }

    public function mira(): Mira
    {
        $key = $this->secretKey();
        $recording = $this->transport instanceof RecordingTransport ? $this->transport : null;

        // The bundle flushes on kernel.terminate, console.terminate and kernel.reset, never in a shutdown function.
        return $this->mira ??= new Mira(
            secretKey: $key,
            host: $this->host(),
            timeoutMs: $this->timeoutMs,
            flushDeadlineMs: $this->flushDeadlineMs,
            maxRetries: $this->maxRetries,
            onError: $recording === null ? null : $recording->recordError(...),
            logger: $this->logger,
            cache: $this->psr16(),
            transport: $this->transport,
            // Without a key nothing is sent and nothing is logged; bin/console mirafive:check tells why.
            enabled: $this->switchedOn() && ($key !== '' || $this->bus !== null),
            flushOnShutdown: false,
            handOff: $this->handOff(),
            sleep: $this->test ? static function (): void {} : null,
        );
    }

    /** Sends what the client buffered. Never throws. */
    public function flush(): void
    {
        $this->mira?->flush();
    }

    public function reset(): void
    {
        $this->mira?->flush();
    }

    /**
     * @return (Closure(string): void)|null
     */
    private function handOff(): ?Closure
    {
        $bus = $this->bus;

        return $bus === null ? null : static function (string $payload) use ($bus): void {
            $bus->dispatch(new DeliverBatch($payload));
        };
    }

    private function psr16(): ?CacheInterface
    {
        return match (true) {
            $this->cache === null, $this->cache instanceof CacheInterface => $this->cache,
            $this->cache instanceof CacheItemPoolInterface && class_exists(Psr16Cache::class) => new Psr16Cache($this->cache),
            $this->cache instanceof CacheItemPoolInterface => null,
            default => throw new InvalidArgumentException('mirafive.cache must name a PSR-16 or PSR-6 cache service, got '.$this->cache::class.'.'),
        };
    }
}
