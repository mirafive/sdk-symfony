<?php

declare(strict_types=1);

namespace MiraFive\Symfony\Tests\Support;

use MiraFive\Mira;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final readonly class AppController
{
    public function __construct(private Mira $mira, private Environment $twig) {}

    public function track(): Response
    {
        $this->mira->track('checkout_completed', userId: 'u_42', properties: ['items' => 3]);
        $this->mira->revenue(4900, 'EUR', userId: 'u_42', entryId: 'stripe:in_1');

        return new Response('ok');
    }

    public function page(): Response
    {
        return new Response($this->twig->createTemplate('{{ mirafive_script() }}')->render());
    }
}
