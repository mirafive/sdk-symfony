<?php

declare(strict_types=1);

namespace MiraFive\Symfony\EventListener;

use MiraFive\Flags\MiraFlags;
use MiraFive\Symfony\ClientFactory;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
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
    /** Set on the main request by mirafive_flags(). A request attribute, so nothing outlives the request. */
    public const string BOOTSTRAP_ATTRIBUTE = '_mirafive_flags_bootstrap';

    public function __construct(private ClientFactory $clients) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onResponse', -10],
            KernelEvents::TERMINATE => ['flush', -1024],
            'console.terminate' => ['flush', -1024],
            WorkerMessageHandledEvent::class => ['flush', -1024],
            WorkerMessageFailedEvent::class => ['flush', -1024],
        ];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (! $event->isMainRequest() || $event->getRequest()->attributes->get(self::BOOTSTRAP_ATTRIBUTE) !== true) {
            return;
        }

        foreach (MiraFlags::BOOTSTRAP_HEADERS as $name => $value) {
            $event->getResponse()->headers->set($name, $value);
        }
    }

    public function flush(): void
    {
        $this->clients->flush();
    }
}
