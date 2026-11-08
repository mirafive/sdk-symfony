<?php

declare(strict_types=1);

namespace MiraFive\Symfony\Tests\Support;

use MiraFive\Symfony\MiraFiveBundle;
use MiraFive\Testing\RecordingTransport;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param  array<string, mixed>  $mirafive
     * @param  array<string, mixed>  $framework  merged into the framework configuration
     */
    public function __construct(
        private readonly array $mirafive = [],
        private readonly array $framework = [],
    ) {
        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle;
        yield new TwigBundle;
        yield new MiraFiveBundle;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/mirafive-sdk-symfony/'.md5(serialize([$this->mirafive, $this->framework, Kernel::VERSION])).'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/mirafive-sdk-symfony/log';
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'router' => ['utf8' => true],
            ...$this->framework,
        ]);
        $container->extension('twig', ['strict_variables' => true]);
        $container->extension('mirafive', $this->mirafive);

        $services = $container->services();
        $services->set('logger', MemoryLogger::class)->public();
        $services->set(AppController::class)->autowire()->public()->tag('controller.service_arguments');

        // Every test kernel records instead of sending; `test: true` does the same on its own.
        if (($this->mirafive['test'] ?? false) !== true) {
            $services->set('mirafive.transport', RecordingTransport::class)->public();
            $services->alias(RecordingTransport::class, 'mirafive.transport')->public();
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('track', '/track')->controller([AppController::class, 'track']);
        $routes->add('page', '/page')->controller([AppController::class, 'page']);
    }
}
