<?php

declare(strict_types=1);

namespace MiraFive\Symfony\Tests;

use MiraFive\Symfony\Tests\Support\BundleTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

final class LifecycleTest extends BundleTestCase
{
    public function test_events_are_sent_once_after_the_response(): void
    {
        $kernel = $this->boot();
        $spy = $this->spy($kernel);
        $request = Request::create('/track');

        $response = $kernel->handle($request);
        self::assertSame([], $spy->batches(), 'nothing is sent before the response');

        $kernel->terminate($request, $response);
        self::assertCount(1, $spy->batches());

        // What the core's shutdown function and destructor do later: the buffer is already empty.
        $mira = $this->mira($kernel);
        $mira->flush();
        $mira->__destruct();
        $kernel->getContainer()->get('services_resetter')?->reset();

        self::assertCount(1, $spy->batches());
        self::assertSame(['signup'], $spy->eventNames());
    }

    public function test_a_request_that_tracks_nothing_sends_nothing(): void
    {
        $kernel = $this->boot();

        $this->serve($kernel, Request::create('/plain'), reset: true);

        self::assertSame([], $this->spy($kernel)->requests);
    }

    public function test_a_worker_runtime_sends_each_requests_events_before_the_next_request(): void
    {
        $kernel = $this->boot();
        $spy = $this->spy($kernel);

        foreach (['first', 'second', 'third'] as $event) {
            $this->serve($kernel, Request::create('/track', parameters: ['event' => $event]), reset: true);
        }

        self::assertCount(3, $spy->batches());
        self::assertSame(['first', 'second', 'third'], $spy->eventNames());
    }

    public function test_the_reset_sends_what_was_tracked_after_the_terminate_flush(): void
    {
        $kernel = $this->boot();
        $spy = $this->spy($kernel);

        $this->serve($kernel, Request::create('/track', parameters: ['late' => '1']));
        self::assertSame(['signup'], $spy->eventNames());

        $kernel->getContainer()->get('services_resetter')?->reset();
        self::assertSame(['signup', 'late'], $spy->eventNames());

        $this->serve($kernel, Request::create('/track', parameters: ['event' => 'next']), reset: true);
        self::assertSame(['signup', 'late', 'next'], $spy->eventNames(), 'the next request starts with an empty buffer');
    }

    public function test_events_are_sent_when_a_console_command_ends(): void
    {
        $kernel = $this->boot();
        $spy = $this->spy($kernel);
        $this->mira($kernel)->track('import finished', properties: ['rows' => 12]);

        $dispatcher = $this->service($kernel, 'event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->dispatch(new ConsoleTerminateEvent(new Command('app:import'), new ArrayInput([]), new NullOutput, 0), ConsoleEvents::TERMINATE);

        self::assertSame(['import finished'], $spy->eventNames());
    }
}
