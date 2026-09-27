<?php

declare(strict_types=1);

namespace MiraFive\Symfony\Tests\Support;

use MiraFive\Symfony\MiraFiveBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param  array<string, mixed>  $mirafive
     * @param  array<string, mixed>  $framework  merged into the framework configuration
     * @param  bool  $spy  replaces mirafive.transport with a public SpyTransport
     */
    public function __construct(
        private readonly array $mirafive = [],
        private readonly array $framework = [],
        private readonly bool $spy = true,
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
        return sys_get_temp_dir().'/mirafive-sdk-symfony/'.md5(serialize([$this->mirafive, $this->framework, $this->spy, Kernel::VERSION])).'/cache';
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
        $container->extension('twig', ['default_path' => __DIR__.'/templates', 'strict_variables' => true]);
        $container->extension('mirafive', $this->mirafive);

        $services = $container->services();
        $services->set(AppController::class)->autowire()->public()->tag('controller.service_arguments');

        if ($this->spy) {
            $services->set('mirafive.transport', SpyTransport::class)->public();
            $services->alias(SpyTransport::class, 'mirafive.transport')->public();
        }

        $services->set('test.mirafive.lifecycle_probe', LateTracker::class)
            ->args([service('MiraFive\Mira')])
            ->tag('kernel.event_subscriber');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('track', '/track')->controller([AppController::class, 'track']);
        $routes->add('page', '/page')->controller([AppController::class, 'page']);
        $routes->add('flag', '/flag')->controller([AppController::class, 'flag']);
        $routes->add('plain', '/plain')->controller([AppController::class, 'plain']);
    }
}
