<?php

declare(strict_types=1);

namespace MiraFive\Symfony\Tests;

use MiraFive\Symfony\Tests\Support\BundleTestCase;
use Twig\Environment;
use Twig\Error\RuntimeError;

final class TwigTest extends BundleTestCase
{
    private const string QUEUE = '<script>window.mirafive=window.mirafive||function(){(mirafive.q=mirafive.q||[]).push(arguments)}</script>';

    /**
     * @param  array<string, mixed>  $config
     */
    private function render(string $template, array $config = []): string
    {
        $kernel = $this->boot(['website_key' => 'mf_ab12cd34_public', ...$config]);

        $twig = $this->service($kernel, 'twig');
        self::assertInstanceOf(Environment::class, $twig);

        return $twig->createTemplate($template)->render();
    }

    public function test_the_script_tag_carries_the_website_key(): void
    {
        self::assertSame(
            self::QUEUE."\n".'<script defer src="https://cdn.mirafive.io/mira.js" data-key="mf_ab12cd34_public"></script>',
            $this->render('{{ mirafive_script() }}'),
        );
    }

    public function test_the_script_tag_takes_the_tracker_options(): void
    {
        $html = $this->render(
            "{{ mirafive_script({mode: 'full', autocapture: true, site_search: ['q', 'term'], hash: false, nonce: 'r4nd0m'}) }}",
            ['host' => 'https://collector.example.test'],
        );

        self::assertSame(
            '<script nonce="r4nd0m">window.mirafive=window.mirafive||function(){(mirafive.q=mirafive.q||[]).push(arguments)}</script>'."\n"
            .'<script defer src="https://cdn.mirafive.io/mira.js" data-key="mf_ab12cd34_public" data-host="https://collector.example.test" data-mode="full" data-autocapture data-site-search="q,term" nonce="r4nd0m"></script>',
            $html,
        );
    }

    public function test_script_mode_sets_the_default_mode_of_the_tag(): void
    {
        self::assertStringContainsString(' data-mode="full"', $this->render('{{ mirafive_script() }}', ['script_mode' => 'full']));
        self::assertStringNotContainsString('data-mode', $this->render("{{ mirafive_script({mode: 'consentless'}) }}", ['script_mode' => 'full']));
    }

    public function test_a_pinned_copy_carries_its_integrity(): void
    {
        $html = $this->render("{{ mirafive_script({src: 'https://cdn.mirafive.io/mira.3f9a1c.js', integrity: 'sha384-abc'}) }}");

        self::assertStringContainsString('src="https://cdn.mirafive.io/mira.3f9a1c.js" data-key="mf_ab12cd34_public" integrity="sha384-abc" crossorigin="anonymous"></script>', $html);
    }

    public function test_the_script_tag_escapes_every_attribute(): void
    {
        $html = $this->render(
            '{{ mirafive_script({src: "/mira.js\" onload=\"alert(1)", nonce: "\"><script>"}) }}',
            ['website_key' => 'mf_x"><script>alert(1)</script>'],
        );

        self::assertStringContainsString('data-key="mf_x&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $html);
        self::assertStringContainsString('src="/mira.js&quot; onload=&quot;alert(1)"', $html);
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertSame(2, substr_count($html, '<script'));
    }

    public function test_the_script_tag_is_empty_without_a_website_key(): void
    {
        self::assertSame('', $this->render('{{ mirafive_script() }}', ['website_key' => null]));
    }

    public function test_an_unknown_script_option_is_an_error(): void
    {
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessageMatches('/does not know autocaptur/');

        $this->render('{{ mirafive_script({autocaptur: true}) }}');
    }
}
