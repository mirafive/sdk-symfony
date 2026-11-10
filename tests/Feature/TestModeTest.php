<?php

declare(strict_types=1);

use MiraFive\Mira;
use MiraFive\Testing\MiraFake;
use Symfony\Component\HttpFoundation\Request;

it('records instead of sending in test mode, with assertions', function (): void {
    $kernel = $this->boot(['test' => true, 'secret_key' => null]);
    $this->serve($kernel, Request::create('/track'));

    $fake = $this->service($kernel, MiraFake::class);
    assert($fake instanceof MiraFake);

    $fake->assertTracked('checkout_completed', fn (array $event): bool => $event['properties']['items'] === 3);
    $fake->assertRevenue(fn (array $event): bool => $event['revenue']['entry_id'] === 'stripe:in_1');
    $fake->assertNoErrors();

    expect($fake->transport->requests()[0]['headers']['Authorization'])->toBe('Bearer mira_sk_fake');
});

it('names the problems in test mode', function (): void {
    $kernel = $this->boot(['test' => true]);
    $fake = $this->service($kernel, MiraFake::class);
    assert($fake instanceof MiraFake);

    $fake->mira->revenue(19.99, 'EUR');

    expect($fake->errors()[0]->getMessage())->toBe('revenue: amount must be an integer in minor units (1999 for 19.99)');
});

it('erases a person through the injected client and records the request in test mode', function (): void {
    $kernel = $this->boot(['test' => true]);
    $fake = $this->service($kernel, MiraFake::class);
    assert($fake instanceof MiraFake);

    $proof = $this->service($kernel, Mira::class)->deletePerson('cust 1');
    $request = $fake->transport->requests()[0];

    expect($proof->deleted)->toBe(['events' => 0])
        ->and($request['method'])->toBe('DELETE')
        ->and($request['url'])->toEndWith('/v1/persons/cust%201');
});
