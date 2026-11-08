<?php

declare(strict_types=1);

use MiraFive\Mira;
use MiraFive\Symfony\Tests\Support\TestKernel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Request;

it('sends what a request tracked after the response', function (): void {
    $kernel = $this->boot();

    $response = $kernel->handle(Request::create('/track'));

    expect($this->recording($kernel)->requests())->toBe([]);

    $kernel->terminate(Request::create('/track'), $response);
    $request = $this->recording($kernel)->requests()[0];

    expect($request['url'])->toBe('https://ingest.test/v1/batch')
        ->and($request['headers']['Authorization'])->toBe('Bearer mira_sk_live_secretTail')
        ->and(array_column($this->sent($kernel), 'event'))->toBe(['checkout_completed', '$revenue']);
});

it('flushes on kernel.reset between requests of a worker runtime', function (): void {
    $kernel = $this->boot();
    $mira = $this->mira($kernel);

    $mira->track('late');
    $resetter = $kernel->getContainer()->get('services_resetter');
    $resetter->reset();

    expect($this->sent($kernel))->toHaveCount(1);
});

it('flushes after a console command', function (): void {
    $kernel = $this->boot();
    $this->mira($kernel)->track('from_cli');

    $kernel->getContainer()->get('event_dispatcher')->dispatch(
        new ConsoleTerminateEvent(new Command('x'), new ArrayInput([]), new NullOutput, 0),
        'console.terminate',
    );

    expect($this->sent($kernel))->toHaveCount(1);
});

it('uses the default host and reads keys from the environment', function (): void {
    $_ENV['MIRAFIVE_SECRET_KEY'] = 'mira_sk_from_env';

    try {
        $kernel = new TestKernel(['cache' => null]);
        $kernel->boot();
        $mira = $this->service($kernel, Mira::class);
        $mira->track('a');
        $mira->flush();
        $request = $this->recording($kernel)->requests()[0];
        $kernel->shutdown();
    } finally {
        $_ENV['MIRAFIVE_SECRET_KEY'] = '';
    }

    expect($request['url'])->toBe('https://events.mirafive.io/v1/batch')
        ->and($request['headers']['Authorization'])->toBe('Bearer mira_sk_from_env');
});

it('sends nothing without a secret key or when switched off', function (array $config): void {
    $kernel = $this->boot($config);
    $mira = $this->mira($kernel);

    $this->serve($kernel, Request::create('/track'));

    expect($mira->sends)->toBeFalse()
        ->and($this->recording($kernel)->requests())->toBe([]);
})->with([
    'no key' => [['secret_key' => null]],
    'switched off' => [['enabled' => false]],
]);

it('shares the breaker through the cache pool', function (): void {
    $kernel = $this->boot(['cache' => 'cache.app', 'max_retries' => 0], ['cache' => ['app' => 'cache.adapter.array']]);
    $this->recording($kernel)->answerNext(answer(503));
    $mira = $this->mira($kernel);

    $mira->track('a');
    $mira->flush();
    $mira->track('b');
    $mira->flush();

    expect($this->recording($kernel)->requests())->toHaveCount(1);
});

it('makes stable event ids', function (): void {
    expect(Mira::eventIdFor('order:123:paid'))->toBe('dc7e1871-544e-8ba6-bf35-0db3d8768abf');
});
