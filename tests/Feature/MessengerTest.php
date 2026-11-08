<?php

declare(strict_types=1);

use MiraFive\MiraError;
use MiraFive\Symfony\Messenger\DeliverBatch;
use MiraFive\Symfony\Messenger\DeliverBatchHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

function messengerConfig(): array
{
    return [
        'messenger' => [
            'transports' => ['mirafive' => 'in-memory://'],
            'routing' => [DeliverBatch::class => 'mirafive'],
        ],
    ];
}

it('hands batches to Messenger instead of sending them', function (): void {
    $kernel = $this->boot(['messenger' => true], messengerConfig());

    $this->serve($kernel, Request::create('/track'));

    $queue = $this->service($kernel, 'messenger.transport.mirafive');
    assert($queue instanceof InMemoryTransport);
    $messages = $queue->getSent();

    expect($this->recording($kernel)->requests())->toBe([])
        ->and($messages)->toHaveCount(1);

    $message = $messages[0]->getMessage();
    assert($message instanceof DeliverBatch);
    $batch = json_decode($message->payload, true);

    expect($batch['seq'])->toBe(1)
        ->and(array_column($batch['events'], 'event'))->toBe(['checkout_completed', '$revenue'])
        ->and($message->payload)->not->toContain('secretTail');

    $handler = $this->service($kernel, DeliverBatchHandler::class);
    assert($handler instanceof DeliverBatchHandler);
    $handler($message);
    $handler($message);

    [$first, $second] = $this->recording($kernel)->requests();

    expect($first['batch']['events'])->toBe($second['batch']['events'])
        ->and($first['batch']['seq'])->toBe(1)
        ->and($first['headers']['Authorization'])->toBe('Bearer mira_sk_live_secretTail');
});

it('throws a retryable failure for Messenger to retry, and a final one as unrecoverable', function (): void {
    $kernel = $this->boot(['messenger' => true], messengerConfig());
    $handler = $this->service($kernel, DeliverBatchHandler::class);
    assert($handler instanceof DeliverBatchHandler);
    $message = new DeliverBatch('{"stream":"s","seq":1,"events":[{"event_id":"x"}]}');

    $this->recording($kernel)->answerNext(answer(503), answer(503), answer(503));
    expect(fn () => $handler($message))->toThrow(MiraError::class);

    $this->recording($kernel)->answerNext(answer(400, ['error' => 'invalid_batch']));
    expect(fn () => $handler($message))->toThrow(UnrecoverableMessageHandlingException::class);

    expect(fn () => $handler(new DeliverBatch('not json')))->toThrow(UnrecoverableMessageHandlingException::class);
});

it('fails a batch on a worker without a secret key', function (): void {
    $kernel = $this->boot(['messenger' => true, 'secret_key' => null], messengerConfig());
    $handler = $this->service($kernel, DeliverBatchHandler::class);
    assert($handler instanceof DeliverBatchHandler);

    $handler(new DeliverBatch('{"stream":"s","seq":1,"events":[{}]}'));
})->throws(UnrecoverableMessageHandlingException::class, 'no secret key');
