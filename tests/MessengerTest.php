<?php

declare(strict_types=1);

namespace MiraFive\Symfony\Tests;

use MiraFive\Http\Response;
use MiraFive\MiraError;
use MiraFive\Symfony\Messenger\DeliverBatch;
use MiraFive\Symfony\Messenger\DeliverBatchHandler;
use MiraFive\Symfony\Tests\Support\BundleTestCase;
use MiraFive\Symfony\Tests\Support\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class MessengerTest extends BundleTestCase
{
    private function kernel(): TestKernel
    {
        return $this->boot(['messenger' => true], [
            'messenger' => [
                'transports' => ['async' => 'in-memory://'],
                'routing' => [DeliverBatch::class => 'async'],
            ],
        ]);
    }

    private function queue(TestKernel $kernel): InMemoryTransport
    {
        $queue = $this->service($kernel, 'messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $queue);

        return $queue;
    }

    private function handler(TestKernel $kernel): DeliverBatchHandler
    {
        $handler = $this->service($kernel, DeliverBatchHandler::class);
        self::assertInstanceOf(DeliverBatchHandler::class, $handler);

        return $handler;
    }

    private function message(TestKernel $kernel): DeliverBatch
    {
        $sent = $this->queue($kernel)->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(DeliverBatch::class, $message);

        return $message;
    }

    public function test_batches_are_queued_instead_of_sent_in_the_request(): void
    {
        $kernel = $this->kernel();
        $spy = $this->spy($kernel);

        // No reset here: the in-memory transport forgets its messages on kernel.reset.
        $this->serve($kernel, Request::create('/track'));
        $message = $this->message($kernel);
        $batch = json_decode($message->body, true);

        self::assertSame([], $spy->batches());
        self::assertIsArray($batch);
        self::assertSame('signup', $batch['events'][0]['name'] ?? null);
        self::assertStringNotContainsString(self::KEY, serialize($message), 'the secret key never travels through the queue');
    }

    public function test_the_worker_sends_the_queued_body_byte_for_byte(): void
    {
        $kernel = $this->kernel();
        $spy = $this->spy($kernel);
        $this->serve($kernel, Request::create('/track'));
        $message = $this->message($kernel);

        ($this->handler($kernel))($message);

        self::assertCount(1, $spy->batches());
        self::assertSame($message->body, $spy->batches()[0]['body']);
        self::assertSame('Bearer '.self::KEY, $spy->batches()[0]['headers']['Authorization']);
        self::assertSame('https://events.mirafive.io/v1/batch', $spy->batches()[0]['url']);
    }

    public function test_a_retryable_failure_is_left_to_the_retry_strategy(): void
    {
        $kernel = $this->kernel();
        $spy = $this->spy($kernel);
        $this->serve($kernel, Request::create('/track'));
        $message = $this->message($kernel);
        // The core retries in the worker first (maxRetries 2), then Messenger's strategy takes over.
        array_push($spy->answers, ...array_fill(0, 3, new Response(503, [], '{"code":"sink_unavailable","detail":"later"}')));

        try {
            ($this->handler($kernel))($message);
            self::fail('A 503 must be thrown for Messenger to retry.');
        } catch (MiraError $error) {
            self::assertSame('sink_unavailable', $error->errorCode);
            self::assertTrue($error->retryable);
            self::assertSame([$message->body, $message->body, $message->body], array_column($spy->batches(), 'body'));
        }
    }

    public function test_a_refusal_is_final(): void
    {
        $kernel = $this->kernel();
        $spy = $this->spy($kernel);
        $this->serve($kernel, Request::create('/track'));
        $spy->answers[] = new Response(400, [], '{"code":"validation_failed","detail":"no"}');

        $this->expectException(UnrecoverableMessageHandlingException::class);

        ($this->handler($kernel))($this->message($kernel));
    }

    public function test_send_and_the_install_check_are_never_queued(): void
    {
        $kernel = $this->kernel();
        $spy = $this->spy($kernel);
        $spy->answers[] = new Response(202, [], '{"batch":"b","accepted":1,"dropped":0}');

        $receipt = $this->mira($kernel)->send([['name' => 'order completed', 'userId' => 'u_42']], idempotencyKey: 'order-981');
        $tester = new CommandTester((new Application($kernel))->find('mirafive:check'));
        $tester->execute([]);

        self::assertSame('b', $receipt->batch, 'the collector\'s receipt');
        self::assertSame([], $this->queue($kernel)->getSent());
        self::assertSame(['order completed', '$install_check'], $spy->eventNames());
    }
}
