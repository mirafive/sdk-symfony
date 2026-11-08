<?php

declare(strict_types=1);

namespace MiraFive\Symfony\Tests\Support;

use MiraFive\Mira;
use MiraFive\Testing\RecordingTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

abstract class BundleTestCase extends TestCase
{
    public const string KEY = 'mira_sk_live_secretTail';

    /** @var list<TestKernel> */
    private array $kernels = [];

    private mixed $exceptionHandler = null;

    protected function setUp(): void
    {
        $this->exceptionHandler = self::currentExceptionHandler();
    }

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }

        $this->kernels = [];

        // Older Symfony 7.4 releases leave the kernel's exception handler installed; PHPUnit would
        // flag every test as risky. Put back the handler the test started with.
        for ($guard = 0; $guard < 10 && self::currentExceptionHandler() !== $this->exceptionHandler; $guard++) {
            restore_exception_handler();
        }
    }

    private static function currentExceptionHandler(): mixed
    {
        $handler = set_exception_handler(null);
        restore_exception_handler();

        return $handler;
    }

    /**
     * @param  array<string, mixed>  $mirafive
     * @param  array<string, mixed>  $framework
     */
    protected function boot(array $mirafive = [], array $framework = []): TestKernel
    {
        $kernel = new TestKernel(['secret_key' => self::KEY, 'public_key' => 'mira_pk_live_123', 'host' => 'https://ingest.test', 'cache' => null, ...$mirafive], $framework);
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

    protected function recording(TestKernel $kernel): RecordingTransport
    {
        $transport = $this->service($kernel, 'mirafive.transport');
        self::assertInstanceOf(RecordingTransport::class, $transport);

        return $transport;
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

    /**
     * Every event that reached the ingest.
     *
     * @return list<array<string, mixed>>
     */
    protected function sent(TestKernel $kernel): array
    {
        return array_merge(...array_map(static fn (array $batch): array => $batch['events'] ?? [], $this->recording($kernel)->batches()));
    }
}
