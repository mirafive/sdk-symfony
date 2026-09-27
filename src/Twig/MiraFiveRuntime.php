<?php

declare(strict_types=1);

namespace MiraFive\Symfony\Twig;

use InvalidArgumentException;
use MiraFive\Flags\UserFlags;
use MiraFive\Mira;
use MiraFive\Symfony\ClientFactory;
use MiraFive\Symfony\EventListener\Lifecycle;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\RuntimeExtensionInterface;

final readonly class MiraFiveRuntime implements RuntimeExtensionInterface
{
    public const string TRACKER_SRC = 'https://cdn.mirafive.io/mira.js';

    private const string QUEUE = 'window.mirafive=window.mirafive||function(){(mirafive.q=mirafive.q||[]).push(arguments)}';

    /** Twig option => the tracker's data attribute (API.md, @mirafive/tracker). */
    private const array FLAGS = [
        'hash' => 'data-hash',
        'manual' => 'data-manual',
        'autocapture' => 'data-autocapture',
        'flags' => 'data-flags',
        'track_localhost' => 'data-track-localhost',
    ];

    private const array UNIT_KEYS = ['userId', 'anonymousId', 'properties', 'consent', 'optedOut'];

    public function __construct(
        private ClientFactory $clients,
        private RequestStack $requests,
        private ?string $websiteKey,
        private string $scriptMode = 'consentless',
    ) {}

    /**
     * The hosted tracker tag with the website key. Prints nothing with `enabled: false` or without a website key; the
     * secret key is not needed.
     *
     * @param  array<string, mixed>  $options  mode ('consentless'|'full', default script_mode), hash, manual, autocapture, flags,
     *                                         track_localhost (bool), site_search (bool|string|list<string>),
     *                                         host, src, integrity, nonce (string)
     */
    public function script(array $options = []): string
    {
        $unknown = array_diff(array_keys($options), ['mode', 'site_search', 'host', 'src', 'integrity', 'nonce', ...array_keys(self::FLAGS)]);

        if ($unknown !== []) {
            throw new InvalidArgumentException('mirafive_script() does not know '.implode(', ', $unknown).'.');
        }

        $key = trim($this->websiteKey ?? '');

        if ($key === '' || ! $this->clients->switchedOn()) {
            return '';
        }

        $mode = $options['mode'] ?? $this->scriptMode;

        if ($mode !== 'consentless' && $mode !== 'full') {
            throw new InvalidArgumentException('mirafive_script() mode is "consentless" or "full".');
        }

        $host = self::string($options, 'host') ?? $this->clients->mira()->host;
        $attributes = ['src' => self::string($options, 'src') ?? self::TRACKER_SRC, 'data-key' => $key];

        if ($host !== Mira::DEFAULT_HOST) {
            $attributes['data-host'] = $host;
        }

        if ($mode === 'full') {
            $attributes['data-mode'] = 'full';
        }

        foreach (self::FLAGS as $option => $attribute) {
            if (($options[$option] ?? false) === true) {
                $attributes[$attribute] = true;
            }
        }

        $search = $options['site_search'] ?? false;

        if ($search !== false) {
            $attributes['data-site-search'] = match (true) {
                $search === true => true,
                is_string($search) => $search,
                is_array($search) => implode(',', array_filter($search, is_string(...))),
                default => throw new InvalidArgumentException('mirafive_script() site_search is a bool, a string or a list of parameters.'),
            };
        }

        // SRI only fits a pinned copy (mira.<hash>.js); the rolling mira.js changes under the same URL.
        $integrity = self::string($options, 'integrity');

        if ($integrity !== null) {
            $attributes['integrity'] = $integrity;
            $attributes['crossorigin'] = 'anonymous';
        }

        $nonce = self::string($options, 'nonce');
        $nonce = $nonce === null ? '' : ' nonce="'.self::escape($nonce).'"';
        $tag = '<script defer';

        foreach ($attributes as $name => $value) {
            $tag .= $value === true ? " {$name}" : " {$name}=\"".self::escape($value).'"';
        }

        return "<script{$nonce}>".self::QUEUE."</script>\n{$tag}{$nonce}></script>";
    }

    /**
     * The flag bootstrap block for the browser SDK (FLAGS.md §5.3). Marks the response `Cache-Control: private,
     * no-store`. The opt-out is read from `Sec-GPC: 1` or `DNT: 1` unless the unit sets `optedOut`.
     *
     * @param  UserFlags|array<string, mixed>  $unit  a UserFlags, or userId, anonymousId, properties, consent, optedOut
     */
    public function flags(UserFlags|array $unit = []): string
    {
        if (! $this->clients->mira()->enabled) {
            return '';
        }

        $this->requests->getMainRequest()?->attributes->set(Lifecycle::BOOTSTRAP_ATTRIBUTE, true);

        return ($unit instanceof UserFlags ? $unit : $this->unit($unit))->bootstrap();
    }

    /**
     * @param  array<string, mixed>  $unit
     */
    private function unit(array $unit): UserFlags
    {
        $unknown = array_diff(array_keys($unit), self::UNIT_KEYS);

        if ($unknown !== []) {
            throw new InvalidArgumentException('mirafive_flags() takes '.implode(', ', self::UNIT_KEYS).', not '.implode(', ', $unknown).'.');
        }

        $request = $this->requests->getMainRequest();
        $optedOut = $unit['optedOut'] ?? ($request !== null && ($request->headers->get('Sec-GPC') === '1' || $request->headers->get('DNT') === '1'));
        $properties = $unit['properties'] ?? [];
        $consent = $unit['consent'] ?? [];

        if (! is_array($properties) || ! is_array($consent) || ! is_bool($optedOut) || array_diff(array_keys($consent), ['experiments', 'targeting']) !== []) {
            throw new InvalidArgumentException('mirafive_flags() properties is a hash, consent a hash of experiments and targeting, optedOut a boolean.');
        }

        return $this->clients->mira()->flags()->for(
            userId: self::id($unit, 'userId'),
            anonymousId: self::id($unit, 'anonymousId'),
            properties: array_combine(array_map(strval(...), array_keys($properties)), $properties),
            consent: array_filter([
                'experiments' => self::scope($consent, 'experiments'),
                'targeting' => self::scope($consent, 'targeting'),
            ], static fn (?bool $answer): bool => $answer !== null),
            optedOut: $optedOut,
        );
    }

    /**
     * @param  array<array-key, mixed>  $consent
     */
    private static function scope(array $consent, string $scope): ?bool
    {
        $answer = $consent[$scope] ?? null;

        if ($answer !== null && ! is_bool($answer)) {
            throw new InvalidArgumentException("mirafive_flags() consent.{$scope} is a boolean.");
        }

        return $answer;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function id(array $values, string $name): ?string
    {
        $value = $values[$name] ?? null;

        return match (true) {
            $value === null, is_string($value) => $value,
            is_int($value) => (string) $value,
            default => throw new InvalidArgumentException("mirafive_flags() {$name} is a string."),
        };
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function string(array $values, string $name): ?string
    {
        $value = $values[$name] ?? null;

        if ($value !== null && ! is_string($value)) {
            throw new InvalidArgumentException("mirafive_script() {$name} is a string.");
        }

        return $value;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
