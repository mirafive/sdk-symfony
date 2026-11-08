<?php

declare(strict_types=1);

use MiraFive\Symfony\Tests\Support\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

function check(TestKernel $kernel): CommandTester
{
    $tester = new CommandTester((new Application($kernel))->find('mirafive:check'));
    $tester->execute([]);

    return $tester;
}

it('passes when the ingest takes the key', function (): void {
    $kernel = $this->boot();
    $this->recording($kernel)->answerNext(answer(200, ['ok' => true, 'key' => 'secret']));

    $tester = check($kernel);

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('mira_sk_live…')->toContain('The secret key and host work')
        ->and($this->recording($kernel)->requests()[0]['url'])->toEndWith('/v1/check');
});

it('fails for a key the ingest does not know', function (): void {
    $kernel = $this->boot();
    $this->recording($kernel)->answerNext(answer(401, ['error' => 'unknown_key']));

    $tester = check($kernel);

    expect($tester->getStatusCode())->toBe(1)
        ->and($tester->getDisplay())->toContain('unknown_key');
});

it('fails on a missing or swapped key, or when switched off', function (array $config, string $message): void {
    $kernel = $this->boot($config);
    $tester = check($kernel);

    expect($tester->getStatusCode())->toBe(1)
        ->and(preg_replace('/\s+/', ' ', $tester->getDisplay()))->toContain($message)
        ->and($this->recording($kernel)->requests())->toBe([]);
})->with([
    'no key' => [['secret_key' => null], 'No secret key'],
    'public key' => [['secret_key' => 'mira_pk_live_1'], 'holds a public key'],
    'secret as public' => [['public_key' => 'mira_sk_live_1'], 'MIRAFIVE_PUBLIC_KEY holds a secret key'],
    'switched off' => [['enabled' => false], 'switched off'],
]);
