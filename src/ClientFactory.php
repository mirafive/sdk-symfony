<?php

declare(strict_types=1);

namespace MiraFive\Symfony;

use Closure;
use InvalidArgumentException;
use MiraFive\Http\CurlTransport;
use MiraFive\Http\StreamTransport;
use MiraFive\Http\Transport;
use MiraFive\Mira;
use MiraFive\Mode;
use MiraFive\Symfony\Messenger\DeliverBatch;
use MiraFive\Symfony\Test\MiraFake;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Messenger\MessageBusInterface;

/** @internal Builds the one client from the bundle configuration and remembers it, so only a client that exists is flushed. */
final class ClientFactory
{
    private ?Mira $mira = null;

    public function __construct(
        private readonly ?string $secretKey,
        private readonly ?string $host,
        private readonly string $mode,
        private readonly bool $enabled,
        private readonly Transport $transport,
        private readonly ?MessageBusInterface $bus = null,
        private readonly int $refreshSeconds = 30,
        private readonly ?object $cache = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $test = false,
    ) {}

    public static function defaultTransport(): Transport
    {
        return extension_loaded('curl') ? new CurlTransport : new StreamTransport;
    }

    /** `enabled` as configured, whatever the keys; decides whether the tracker tag is printed. */
    public function switchedOn(): bool
    {
        return $this->test || $this->enabled;
    }

    public function mira(): Mira
    {
        $key = trim($this->secretKey ?? '');
        $key = $key === '' && $this->test ? MiraFake::KEY : $key;
        $host = trim($this->host ?? '');

        // The bundle flushes on kernel.terminate, console.terminate and kernel.reset, never in a shutdown function.
        return $this->mira ??= new Mira(
            key: $key,
            host: $host === '' ? null : $host,
            mode: Mode::from($this->mode),
            transport: $this->transport,
            logger: $this->logger,
            cache: $this->psr16(),
            enabled: $this->switchedOn() && $key !== '',
            flushOnShutdown: false,
            flagsRefreshSeconds: $this->refreshSeconds,
            handOff: $this->handOff(),
        );
    }

    /** Sends what the client buffered. Never throws. */
    public function flush(): void
    {
        $this->mira?->flush();
    }

    /**
     * @return (Closure(string, string): void)|null
     */
    private function handOff(): ?Closure
    {
        $bus = $this->bus;

        return $bus === null ? null : static function (string $body) use ($bus): void {
            $bus->dispatch(new DeliverBatch($body));
        };
    }

    private function psr16(): ?CacheInterface
    {
        return match (true) {
            $this->cache === null, $this->cache instanceof CacheInterface => $this->cache,
            $this->cache instanceof CacheItemPoolInterface => new Psr16Cache($this->cache),
            default => throw new InvalidArgumentException('mirafive.flags.cache must name a PSR-16 or PSR-6 cache service, got '.$this->cache::class.'.'),
        };
    }
}
