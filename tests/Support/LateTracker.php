<?php

declare(strict_types=1);

namespace MiraFive\Symfony\Tests\Support;

use MiraFive\Mira;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Tracks after the bundle's terminate flush when a request asks for it, as a late terminate listener might. */
final readonly class LateTracker implements EventSubscriberInterface
{
    public function __construct(private Mira $mira) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => ['onTerminate', -2048]];
    }

    public function onTerminate(TerminateEvent $event): void
    {
        if ($event->getRequest()->query->has('late')) {
            $this->mira->track('late');
        }
    }
}
