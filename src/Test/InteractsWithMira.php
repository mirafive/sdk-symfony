<?php

declare(strict_types=1);

namespace MiraFive\Symfony\Test;

use LogicException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * For a KernelTestCase or WebTestCase whose test environment sets `mirafive.test: true`.
 *
 * @phpstan-require-extends KernelTestCase
 */
trait InteractsWithMira
{
    protected static function mira(): MiraFake
    {
        $container = static::getContainer();
        $fake = $container->has(MiraFake::class) ? $container->get(MiraFake::class) : null;

        if (! $fake instanceof MiraFake) {
            throw new LogicException('Set mirafive.test: true in the test environment to use MiraFake.');
        }

        return $fake;
    }
}
