<?php

declare(strict_types=1);

namespace MiraFive\Symfony\Tests\Support;

use MiraFive\Mira;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

abstract class BundleTestCase extends TestCase
{
    public const string KEY = 'mf_ab12cd34_secretTail';

    /** @var list<TestKernel> */
    private array $kernels = [];

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }

        $this->kernels = [];
    }

    /**
     * @param  array<string, mixed>  $mirafive
     * @param  array<string, mixed>  $framework
     */
    protected function boot(array $mirafive = [], array $framework = [], bool $spy = true): TestKernel
    {
        $kernel = new TestKernel(['secret_key' => self::KEY, 'flags' => ['cache' => null], ...$mirafive], $framework, $spy && ($mirafive['test'] ?? false) !== true);
        $kernel->boot();
        $this->kernels[] = $kernel;

        return $kernel;
    }

    /** The test container: private services that are in use are reachable. */
    protected function service(TestKernel $kernel, string $id): object
    {
        $service = $kernel->getContainer()->get('test.service_container')?->get($id);

        self::assertIsObject($service);

        return $service;
    }

    protected function spy(TestKernel $kernel): SpyTransport
    {
        $spy = $this->service($kernel, SpyTransport::class);
        self::assertInstanceOf(SpyTransport::class, $spy);

        return $spy;
    }

    protected function mira(TestKernel $kernel): Mira
    {
        $mira = $this->service($kernel, Mira::class);
        self::assertInstanceOf(Mira::class, $mira);

        return $mira;
    }

    /** One request as a worker runtime serves it: handle, terminate, then reset before the next one. */
    protected function serve(TestKernel $kernel, Request $request, bool $reset = false): Response
    {
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        if ($reset) {
            $resetter = $kernel->getContainer()->get('services_resetter', ContainerInterface::NULL_ON_INVALID_REFERENCE);
            self::assertNotNull($resetter);
            self::assertTrue(method_exists($resetter, 'reset'));
            $resetter->reset();
        }

        return $response;
    }
}
