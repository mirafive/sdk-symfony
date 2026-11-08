<?php

declare(strict_types=1);

use MiraFive\Symfony\Tests\Support\TestKernel;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Error\RuntimeError;

function twig(TestKernel $kernel, string $template): string
{
    $twig = $kernel->getContainer()->get('test.service_container')?->get('twig');
    assert($twig instanceof Environment);

    return $twig->createTemplate($template)->render();
}

it('prints the browser SDK with the public key', function (): void {
    $kernel = $this->boot();

    expect($this->serve($kernel, Request::create('/page'))->getContent())->toBe(<<<'HTML'
        <script>
          window.mira = window.mira || function () {
            (mira.q = mira.q || []).push(arguments);
          };
        </script>
        <script async src="https://ingest.test/sdk/mira.js"
          data-key="mira_pk_live_123"></script>
        HTML);
});

it('loads from the CDN for the default host', function (): void {
    $kernel = $this->boot(['host' => null]);

    expect(twig($kernel, '{{ mirafive_script() }}'))->toContain('src="https://cdn.mirafive.io/sdk/mira.js"'."\n".'  data-host="https://events.mirafive.io"');
});

it('takes consent and autocapture from the config and the function', function (): void {
    $kernel = $this->boot(['script' => ['consent' => 'analytics', 'autocapture' => ['forms' => false]]]);

    expect(twig($kernel, '{{ mirafive_script() }}'))->toContain('data-consent="analytics"'."\n".'  data-forms="false"')
        ->and(twig($kernel, "{{ mirafive_script({consent: 'granted', autocapture: false, nonce: 'abc'}) }}"))
        ->toContain('data-consent="granted"')
        ->toContain('data-autocapture="false"')
        ->toContain('<script nonce="abc">');
});

it('prints nothing without a public key or when switched off', function (array $config): void {
    expect(twig($this->boot($config), '{{ mirafive_script() }}'))->toBe('');
})->with([
    'no key' => [['public_key' => null]],
    'switched off' => [['enabled' => false]],
]);

it('never prints a secret key', function (): void {
    $kernel = $this->boot(['public_key' => 'mira_sk_live_secretTail']);

    $html = twig($kernel, '{{ mirafive_script() }}');

    expect($html)->toBe('<!-- MIRA FIVE: the script tag was not printed, see the log -->')
        ->and($html)->not->toContain('secretTail')
        ->and($kernel->getContainer()->get('logger')->lines)->toBe(['error [mirafive] refused to print a secret key (mira_sk_…) into a page: set MIRAFIVE_PUBLIC_KEY to the public key (mira_pk_…)']);
});

it('refuses unknown options', function (): void {
    twig($this->boot(), "{{ mirafive_script({mode: 'full'}) }}");
})->throws(RuntimeError::class, 'mirafive_script() does not know mode');
