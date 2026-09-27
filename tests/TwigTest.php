<?php

declare(strict_types=1);

namespace MiraFive\Symfony\Tests;

use MiraFive\Flags\Hash;
use MiraFive\Symfony\Test\MiraFake;
use MiraFive\Symfony\Tests\Support\BundleTestCase;
use MiraFive\Symfony\Tests\Support\TestKernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\Error\RuntimeError;

final class TwigTest extends BundleTestCase
{
    private const string QUEUE = '<script>window.mirafive=window.mirafive||function(){(mirafive.q=mirafive.q||[]).push(arguments)}</script>';

    private const array DOCUMENT = [
        'at' => 1727430000000,
        'flags' => [
            'new-checkout' => ['d' => 'off', 'r' => [['x' => 'on']], 's' => 'abcdefghijk1', 't' => 'b', 'u' => 'p', 'w' => 1],
            'limits' => ['d' => 'pro', 'p' => ['pro' => ['note' => '</script><b>&']], 'r' => [], 's' => 'abcdefghijk2', 't' => 'c', 'u' => 'p', 'w' => 1],
            'server-only' => ['d' => 'off', 'r' => [['x' => 'on']], 's' => 'abcdefghijk3', 't' => 'b', 'u' => 'p'],
        ],
        'v' => 1,
    ];

    /**
     * @param  array<string, mixed>  $config
     */
    private function render(string $template, array $config = [], ?Request $request = null, ?TestKernel &$kernel = null): string
    {
        $kernel = $this->boot(['website_key' => 'mf_ab12cd34_public', ...$config]);

        if ($request !== null) {
            $stack = $this->service($kernel, 'request_stack');
            self::assertInstanceOf(RequestStack::class, $stack);
            $stack->push($request);
        }

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
            "{{ mirafive_script({mode: 'full', autocapture: true, site_search: ['q', 'term'], flags: true, hash: false, nonce: 'r4nd0m'}) }}",
            ['host' => 'https://collector.example.test'],
        );

        self::assertSame(
            '<script nonce="r4nd0m">window.mirafive=window.mirafive||function(){(mirafive.q=mirafive.q||[]).push(arguments)}</script>'."\n"
            .'<script defer src="https://cdn.mirafive.io/mira.js" data-key="mf_ab12cd34_public" data-host="https://collector.example.test" data-mode="full" data-autocapture data-flags data-site-search="q,term" nonce="r4nd0m"></script>',
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

    public function test_the_flag_bootstrap_is_escaped_once_and_holds_only_website_flags(): void
    {
        $kernel = $this->boot(['website_key' => 'mf_ab12cd34_public', 'test' => true]);
        $fake = $this->service($kernel, MiraFake::class);
        self::assertInstanceOf(MiraFake::class, $fake);
        $fake->serveFlags(self::DOCUMENT);
        $twig = $this->service($kernel, 'twig');
        self::assertInstanceOf(Environment::class, $twig);

        $html = $twig->createTemplate('{{ mirafive_flags({userId: "u_42", properties: {plan: "pro"}}) }}')->render();
        // FLAGS.md §5.3: <, > and & as lower-case \u escapes, nothing else.
        $u = chr(92).'u00';
        $note = "{$u}3c/script{$u}3e{$u}3cb{$u}3e{$u}26";

        self::assertSame(
            '<script type="application/json" id="mirafive-flags">{"v":1,"at":0,"values":{"new-checkout":["on"],"limits":["pro",{"note":"'.$note.'"}]},"unit":"'.Hash::fnv1a32('u_42').'"}</script>',
            preg_replace('/"at":\d+/', '"at":0', $html),
        );
    }

    public function test_the_flag_bootstrap_honours_an_opt_out_header(): void
    {
        $request = Request::create('/', server: ['HTTP_SEC_GPC' => '1']);
        $html = $this->render('{{ mirafive_flags({userId: "u_42"}) }}', ['test' => true], $request);

        self::assertStringNotContainsString('"unit"', $html);
    }

    public function test_a_page_with_a_bootstrap_is_not_stored_by_shared_caches(): void
    {
        $kernel = $this->boot(['website_key' => 'mf_ab12cd34_public', 'test' => true]);

        $page = $this->serve($kernel, Request::create('/page'), reset: true);
        $plain = $this->serve($kernel, Request::create('/plain'), reset: true);

        self::assertStringContainsString('id="mirafive-flags"', (string) $page->getContent());
        self::assertTrue($page->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($page->headers->hasCacheControlDirective('private'));
        self::assertFalse($plain->headers->hasCacheControlDirective('no-store'), 'the mark does not outlive its request');
    }
}
