<?php

declare(strict_types=1);

namespace MiraFive\Symfony\Tests;

use MiraFive\Flags\MiraFlags;
use MiraFive\Mira;
use MiraFive\Symfony\Test\InteractsWithMira;
use MiraFive\Symfony\Tests\Support\TestKernel;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

final class MiraFakeTest extends KernelTestCase
{
    use InteractsWithMira;

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel(['test' => true], spy: false);
    }

    private static function client(): Mira
    {
        $mira = self::getContainer()->get(Mira::class);
        self::assertInstanceOf(Mira::class, $mira);

        return $mira;
    }

    public function test_it_asserts_on_tracked_events_still_in_the_buffer(): void
    {
        self::client()->track('signup', userId: 'u_42', properties: ['plan' => 'pro']);
        self::client()->identify('u_42', ['plan' => 'pro']);

        self::mira()->assertTracked('signup');
        self::mira()->assertTracked('signup', static fn (array $event): bool => $event['properties'] === ['plan' => 'pro'], times: 1);
        self::mira()->assertIdentified('u_42', ['plan' => 'pro']);
        self::mira()->assertNotTracked('checkout');
    }

    public function test_a_missing_event_fails_the_test(): void
    {
        self::client()->track('signup', userId: 'u_42');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The event "signup" was tracked 1 times, not 2.');

        self::mira()->assertTracked('signup', times: 2);
    }

    public function test_nothing_tracked(): void
    {
        self::mira()->assertNothingTracked();

        self::client()->track('signup');

        $this->expectException(AssertionFailedError::class);
        self::mira()->assertNothingTracked();
    }

    public function test_it_serves_a_flag_document_and_records_server_exposures(): void
    {
        self::mira()->serveFlags(['at' => 1727430000000, 'flags' => [
            'new-checkout' => ['d' => 'off', 'r' => [['x' => 'on']], 's' => 'abcdefghijk1', 't' => 'b', 'u' => 'p'],
            'pricing-test' => ['c' => 's', 'd' => 'a', 'e' => 'o', 'r' => [['w' => [['b', 10000]]]], 's' => 'abcdefghijk2', 't' => 'm', 'u' => 'p'],
        ], 'v' => 1]);
        $flags = self::getContainer()->get(MiraFlags::class);
        self::assertInstanceOf(MiraFlags::class, $flags);

        $user = $flags->for(userId: 'u_42');

        self::assertTrue($user->enabled('new-checkout'));
        self::assertSame('b', $user->variant('pricing-test'));
        self::mira()->assertTracked('$exposure', static fn (array $event): bool => $event['properties'] === ['$experiment' => 'pricing-test', '$variant' => 'b']);
    }
}
