<?php

declare(strict_types=1);

namespace MiraFive\Symfony\Tests;

use MiraFive\Flags\MiraFlags;
use MiraFive\Symfony\Tests\Support\BundleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

final class DisabledTest extends BundleTestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function disabled(): iterable
    {
        yield 'enabled: false' => [['enabled' => false, 'website_key' => 'mf_ab12cd34_public'], false];
        yield 'no secret key' => [['secret_key' => null, 'website_key' => 'mf_ab12cd34_public'], true];
        yield 'an empty secret key' => [['secret_key' => '  ', 'website_key' => 'mf_ab12cd34_public'], true];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    #[DataProvider('disabled')]
    public function test_a_disabled_client_records_and_sends_nothing(array $config, bool $printsTag): void
    {
        $kernel = $this->boot($config);
        $mira = $this->mira($kernel);

        $this->serve($kernel, Request::create('/track'), reset: true);
        $receipt = $mira->send([['name' => 'order completed', 'userId' => 'u_42']], idempotencyKey: 'order-1');
        $flags = $this->service($kernel, MiraFlags::class);
        self::assertInstanceOf(MiraFlags::class, $flags);
        $twig = $this->service($kernel, 'twig');
        self::assertInstanceOf(Environment::class, $twig);

        self::assertFalse($mira->enabled);
        self::assertSame(1, $receipt->accepted, 'a local receipt');
        self::assertSame('fallback', $flags->for(userId: 'u_42')->variant('pricing-test', 'fallback'));
        self::assertSame('', $twig->createTemplate('{{ mirafive_flags({userId: "u_42"}) }}')->render());
        // The tracker needs only the website key; only enabled: false removes it.
        self::assertSame($printsTag, str_contains($twig->createTemplate('{{ mirafive_script() }}')->render(), 'data-key="mf_ab12cd34_public"'));
        self::assertSame([], $this->spy($kernel)->requests);
    }

    public function test_a_disabled_client_still_refuses_what_the_collector_would_refuse(): void
    {
        $mira = $this->mira($this->boot(['enabled' => false, 'mode' => 'consentless']));

        $this->expectException(\InvalidArgumentException::class);

        $mira->track('signup', userId: 'u_42');
    }
}
