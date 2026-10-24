<?php

declare(strict_types=1);

namespace MiraFive\Symfony\Twig;

use InvalidArgumentException;
use MiraFive\Mira;
use MiraFive\Symfony\ClientFactory;
use Twig\Extension\RuntimeExtensionInterface;

final readonly class MiraFiveRuntime implements RuntimeExtensionInterface
{
    public const string TRACKER_SRC = 'https://cdn.mirafive.io/mira.js';

    private const string QUEUE = 'window.mirafive=window.mirafive||function(){(mirafive.q=mirafive.q||[]).push(arguments)}';

    /** Twig option => the tracker's data attribute (API.md, @mirafive/tracker). */
    private const array SWITCHES = [
        'hash' => 'data-hash',
        'manual' => 'data-manual',
        'autocapture' => 'data-autocapture',
        'track_localhost' => 'data-track-localhost',
    ];

    public function __construct(
        private ClientFactory $clients,
        private ?string $websiteKey,
        private string $scriptMode = 'consentless',
    ) {}

    /**
     * The hosted tracker tag with the website key. Prints nothing with `enabled: false` or without a website key; the
     * secret key is not needed.
     *
     * @param  array<string, mixed>  $options  mode ('consentless'|'full', default script_mode), hash, manual, autocapture,
     *                                         track_localhost (bool), site_search (bool|string|list<string>),
     *                                         host, src, integrity, nonce (string)
     */
    public function script(array $options = []): string
    {
        $unknown = array_diff(array_keys($options), ['mode', 'site_search', 'host', 'src', 'integrity', 'nonce', ...array_keys(self::SWITCHES)]);

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

        foreach (self::SWITCHES as $option => $attribute) {
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
