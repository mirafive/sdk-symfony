<?php

declare(strict_types=1);

namespace MiraFive\Symfony\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class MiraFiveExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('mirafive_script', [MiraFiveRuntime::class, 'script'], ['is_safe' => ['html']]),
            new TwigFunction('mirafive_flags', [MiraFiveRuntime::class, 'flags'], ['is_safe' => ['html']]),
        ];
    }
}
