<?php

declare(strict_types=1);

namespace MiraFive\Symfony\Tests;

use MiraFive\Symfony\ClientFactory;
use MiraFive\Symfony\Messenger\DeliverBatch;
use MiraFive\Symfony\Messenger\DeliverBatchHandler;
use MiraFive\Symfony\Tests\Support\BundleTestCase;
use MiraFive\Symfony\Tests\Support\SpyTransport;
use Psr\Log\AbstractLogger;
use RuntimeException;
use stdClass;
use Stringable;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/** messenger:consume and other workers: no HTTP request, so no kernel.* event ever fires. */
final class WorkerTest extends BundleTestCase
{
    public function test_a_service_reset_without_any_request_sends_the_buffer(): void
    {
        $kernel = $this->boot();
        $this->mira($kernel)->track('invoice sent', properties: ['count' => 3]);

        $kernel->getContainer()->get('services_resetter')?->reset();

        self::assertSame(['invoice sent'], $this->spy($kernel)->eventNames());
        self::assertCount(1, $this->spy($kernel)->batches());
    }

    public function test_each_handled_or_failed_message_sends_what_its_handler_tracked(): void
    {
        $kernel = $this->boot();
        $spy = $this->spy($kernel);
        $dispatcher = $this->service($kernel, 'event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $envelope = new Envelope(new stdClass);

        $this->mira($kernel)->track('invoice sent');
        $dispatcher->dispatch(new WorkerMessageHandledEvent($envelope, 'async'));
        $this->mira($kernel)->track('invoice failed');
        $dispatcher->dispatch(new WorkerMessageFailedEvent($envelope, 'async', new RuntimeException('boom')));

        self::assertCount(2, $spy->batches());
        self::assertSame(['invoice sent', 'invoice failed'], $spy->eventNames());
    }

    public function test_a_worker_switched_off_drops_queued_batches_quietly(): void
    {
        $spy = new SpyTransport;
        $logger = self::logger();

        (new DeliverBatchHandler(new ClientFactory(self::KEY, null, 'full', false, $spy), $logger))(new DeliverBatch('{}'));

        self::assertSame([], $spy->requests);
        self::assertSame([], $logger->records);
    }

    public function test_a_worker_without_a_secret_key_refuses_the_batch_loudly(): void
    {
        $spy = new SpyTransport;
        $logger = self::logger();

        try {
            (new DeliverBatchHandler(new ClientFactory(null, null, 'full', true, $spy), $logger))(new DeliverBatch('{}'));
            self::fail('A batch that cannot be delivered must not vanish.');
        } catch (UnrecoverableMessageHandlingException $exception) {
            self::assertStringContainsString('no secret key', $exception->getMessage());
        }

        self::assertSame([], $spy->requests);
        self::assertSame('error', $logger->records[0][0] ?? null);
        self::assertStringContainsString('MIRAFIVE_SECRET_KEY', $logger->records[0][1] ?? '');
    }

    /**
     * @return AbstractLogger&object{records: list<array{0: string, 1: string}>}
     */
    private static function logger(): AbstractLogger
    {
        return new class extends AbstractLogger
        {
            /** @var list<array{0: string, 1: string}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message];
            }
        };
    }
}
