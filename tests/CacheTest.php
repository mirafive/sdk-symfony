<?php

declare(strict_types=1);

namespace MiraFive\Symfony\Tests;

use InvalidArgumentException;
use MiraFive\Mira;
use MiraFive\Symfony\ClientFactory;
use MiraFive\Symfony\Tests\Support\BundleTestCase;
use MiraFive\Symfony\Tests\Support\SpyTransport;
use stdClass;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class CacheTest extends BundleTestCase
{
    public function test_a_psr6_pool_is_accepted(): void
    {
        $factory = new ClientFactory(self::KEY, null, 'full', true, new SpyTransport, cache: new ArrayAdapter);

        self::assertInstanceOf(Mira::class, $factory->mira());
    }

    public function test_a_cache_service_of_another_kind_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ClientFactory(self::KEY, null, 'full', true, new SpyTransport, cache: new stdClass))->mira();
    }

    public function test_the_default_cache_app_pool_is_accepted(): void
    {
        $kernel = $this->boot(['cache' => 'cache.app']);

        self::assertInstanceOf(Mira::class, $this->mira($kernel));
    }
}
