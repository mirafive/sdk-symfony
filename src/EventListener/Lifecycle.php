<?php

declare(strict_types=1);

namespace MiraFive\Symfony\EventListener;

use MiraFive\Symfony\ClientFactory;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Flushes after the response was sent, after a console command and after each message a Messenger worker handled or
 * failed. ClientFactory::reset() covers kernel.reset. The core's shutdown flush is switched off, so these are the only
 * flushes.
 */
final readonly class Lifecycle implements EventSubscriberInterface
{
    public function __construct(private ClientFactory $clients) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => ['flush', -1024],
            'console.terminate' => ['flush', -1024],
            WorkerMessageHandledEvent::class => ['flush', -1024],
            WorkerMessageFailedEvent::class => ['flush', -1024],
        ];
    }

    public function flush(): void
    {
        $this->clients->flush();
    }
}
