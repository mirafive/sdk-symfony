<?php

declare(strict_types=1);

namespace MiraFive\Symfony\Twig;

use InvalidArgumentException;
use MiraFive\MiraError;
use MiraFive\ScriptTag;
use MiraFive\Symfony\ClientFactory;
use Psr\Log\LoggerInterface;
use Twig\Extension\RuntimeExtensionInterface;

/** What `{{ mirafive_script() }}` prints: the browser SDK with the public key. */
final readonly class MiraFiveRuntime implements RuntimeExtensionInterface
{
    private const array OPTIONS = ['consent', 'autocapture', 'src', 'host', 'debug', 'nonce'];

    /**
     * @param  array<array-key, mixed>  $script  the bundle's `script` configuration
     */
    public function __construct(
        private ClientFactory $clients,
        private ?string $publicKey,
        private array $script = [],
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * Nothing when disabled or without a public key. A secret key is never printed: the tag is
     * replaced by an HTML comment and the problem is logged.
     *
     * @param  array<array-key, mixed>  $options  consent ('auto', 'granted', 'denied', 'analytics'), autocapture (bool or {forms: false, …}), src, host, debug, nonce
     */
    public function script(array $options = []): string
    {
        $unknown = array_diff(array_keys($options), self::OPTIONS);

        if ($unknown !== []) {
            throw new InvalidArgumentException('mirafive_script() does not know '.implode(', ', $unknown).'; it takes '.implode(', ', self::OPTIONS).'.');
        }

        if (! $this->clients->switchedOn()) {
            return '';
        }

        try {
            return ScriptTag::render(
                publicKey: $this->publicKey ?? '',
                host: self::string($options, 'host') ?? $this->clients->host(),
                src: self::string($options, 'src') ?? self::string($this->script, 'src'),
                consent: self::string($options, 'consent') ?? self::string($this->script, 'consent') ?? 'auto',
                autocapture: self::autocapture($options['autocapture'] ?? $this->script['autocapture'] ?? true),
                debug: ($options['debug'] ?? false) === true,
                nonce: self::string($options, 'nonce'),
            );
        } catch (MiraError $error) {
            $this->logger?->error('[mirafive] '.$error->getMessage());

            return '<!-- MIRA FIVE: the script tag was not printed, see the log -->';
        }
    }

    /**
     * @return bool|array<string, bool>
     */
    private static function autocapture(mixed $value): bool|array
    {
        if (is_bool($value)) {
            return $value;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('mirafive_script() autocapture is true, false, or a map such as {forms: false}.');
        }

        $switches = [];

        foreach ($value as $name => $on) {
            $switches[(string) $name] = $on === true;
        }

        return $switches;
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private static function string(array $values, string $name): ?string
    {
        $value = $values[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
