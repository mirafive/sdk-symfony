<?php

declare(strict_types=1);

namespace MiraFive\Symfony\Tests;

use InvalidArgumentException;
use MiraFive\Flags\MiraFlags;
use MiraFive\Symfony\ClientFactory;
use MiraFive\Symfony\Tests\Support\BundleTestCase;
use MiraFive\Symfony\Tests\Support\SpyTransport;
use stdClass;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class FlagCacheTest extends BundleTestCase
{
    public function test_a_psr6_pool_shares_the_flag_document_between_processes(): void
    {
        $pool = new ArrayAdapter;
        $first = new SpyTransport;
        $second = new SpyTransport;

        (new ClientFactory(self::KEY, null, 'full', true, $first, cache: $pool))->mira()->flags()->ready();
        $ready = (new ClientFactory(self::KEY, null, 'full', true, $second, cache: $pool))->mira()->flags()->ready();

        self::assertTrue($ready);
        self::assertCount(1, $first->requests);
        self::assertSame([], $second->requests, 'the second process reads the cached document');
    }

    public function test_a_cache_service_of_another_kind_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ClientFactory(self::KEY, null, 'full', true, new SpyTransport, cache: new stdClass))->mira();
    }

    public function test_the_default_cache_app_pool_is_accepted(): void
    {
        $kernel = $this->boot(['flags' => ['cache' => 'cache.app']]);

        self::assertTrue($this->service($kernel, MiraFlags::class) instanceof MiraFlags);
    }
}
