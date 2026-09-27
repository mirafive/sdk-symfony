<?php

declare(strict_types=1);

namespace MiraFive\Symfony\Tests;

use MiraFive\Flags\MiraFlags;
use MiraFive\Mode;
use MiraFive\Symfony\MiraFiveBundle;
use MiraFive\Symfony\Tests\Support\BundleTestCase;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ConfigurationTest extends BundleTestCase
{
    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function process(array $config): array
    {
        $extension = (new MiraFiveBundle)->getContainerExtension();
        self::assertNotNull($extension);
        self::assertSame('mirafive', $extension->getAlias());
        self::assertTrue(method_exists($extension, 'getConfiguration'));
        $configuration = $extension->getConfiguration([], new ContainerBuilder);
        self::assertInstanceOf(ConfigurationInterface::class, $configuration);

        /** @var array<string, mixed> */
        return (new Processor)->processConfiguration($configuration, [$config]);
    }

    public function test_the_defaults_read_the_shared_environment_variables(): void
    {
        self::assertSame([
            'secret_key' => '%env(default::MIRAFIVE_SECRET_KEY)%',
            'website_key' => '%env(default::MIRAFIVE_WEBSITE_KEY)%',
            'host' => '%env(default::MIRAFIVE_HOST)%',
            'mode' => 'full',
            'script_mode' => 'consentless',
            'enabled' => true,
            'messenger' => null,
            'flags' => ['refresh_seconds' => 30, 'cache' => 'cache.app'],
            'test' => false,
        ], self::process([]));
    }

    public function test_messenger_true_means_the_default_bus(): void
    {
        self::assertSame('messenger.default_bus', self::process(['messenger' => true])['messenger']);
        self::assertNull(self::process(['messenger' => false])['messenger']);
        self::assertSame('command.bus', self::process(['messenger' => 'command.bus'])['messenger']);
    }

    public function test_the_refresh_interval_is_at_least_ten_seconds(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        self::process(['flags' => ['refresh_seconds' => 5]]);
    }

    public function test_the_mode_is_full_or_consentless(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        self::process(['mode' => 'anonymous']);
    }

    public function test_the_clients_are_autowired_from_the_configuration(): void
    {
        $kernel = $this->boot(['host' => 'https://collector.example.test/', 'mode' => 'consentless']);
        $mira = $this->mira($kernel);
        $flags = $this->service($kernel, MiraFlags::class);

        self::assertSame('https://collector.example.test', $mira->host);
        self::assertSame(Mode::Consentless, $mira->mode);
        self::assertInstanceOf(MiraFlags::class, $flags);
        self::assertSame($mira->flags(), $flags, 'one MiraFlags per process');
    }

    public function test_the_secret_key_comes_from_the_environment_by_default(): void
    {
        $_ENV['MIRAFIVE_SECRET_KEY'] = self::KEY;

        try {
            $kernel = new Support\TestKernel(['flags' => ['cache' => null]]);
            $kernel->boot();
            $this->mira($kernel)->track('signup');
            $this->mira($kernel)->flush();
            $request = $this->spy($kernel)->batches()[0] ?? null;

            self::assertSame('Bearer '.self::KEY, $request['headers']['Authorization'] ?? null);
            $kernel->shutdown();
        } finally {
            $_ENV['MIRAFIVE_SECRET_KEY'] = '';
        }
    }
}
