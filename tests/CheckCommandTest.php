<?php

declare(strict_types=1);

namespace MiraFive\Symfony\Tests;

use MiraFive\Http\Response;
use MiraFive\Symfony\Test\MiraFake;
use MiraFive\Symfony\Tests\Support\BundleTestCase;
use MiraFive\Symfony\Tests\Support\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CheckCommandTest extends BundleTestCase
{
    private function check(TestKernel $kernel): CommandTester
    {
        $tester = new CommandTester((new Application($kernel))->find('mirafive:check'));
        $tester->execute([]);

        return $tester;
    }

    private function fake(TestKernel $kernel): MiraFake
    {
        $fake = $this->service($kernel, MiraFake::class);
        self::assertInstanceOf(MiraFake::class, $fake);

        return $fake;
    }

    public function test_it_sends_an_install_check_and_prints_the_receipt(): void
    {
        $kernel = $this->boot(['test' => true]);

        $tester = $this->check($kernel);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('install_check', $tester->getDisplay());
        self::assertStringContainsString('The secret key and host work', $tester->getDisplay());
        self::assertSame(['$install_check'], array_column($this->fake($kernel)->events(), 'name'));
    }

    public function test_a_refusal_fails_with_a_hint(): void
    {
        $kernel = $this->boot(['test' => true]);
        $this->fake($kernel)->transport()->answerNextBatch(new Response(403, [], '{"code":"website_key_as_bearer","detail":"a website key"}'));

        $tester = $this->check($kernel);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('website_key_as_bearer', $tester->getDisplay());
        self::assertStringContainsString('Server code needs the secret key', $tester->getDisplay());
    }

    public function test_it_fails_while_disabled(): void
    {
        $kernel = $this->boot(['enabled' => false]);

        $tester = $this->check($kernel);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('MIRA FIVE is disabled', $tester->getDisplay());
        self::assertSame([], $this->spy($kernel)->requests);
    }
}
