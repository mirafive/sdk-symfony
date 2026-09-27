<?php

declare(strict_types=1);

namespace MiraFive\Symfony\Tests\Support;

use MiraFive\Flags\MiraFlags;
use MiraFive\Mira;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final readonly class AppController
{
    public function __construct(private Mira $mira, private MiraFlags $flags, private Environment $twig) {}

    public function track(Request $request): Response
    {
        $this->mira->track((string) $request->query->get('event', 'signup'), userId: 'u_42', properties: ['plan' => 'pro']);

        return new Response('tracked');
    }

    public function page(): Response
    {
        return new Response($this->twig->render('page.html.twig'));
    }

    public function flag(): Response
    {
        return new Response($this->flags->for(userId: 'u_42')->variant('pricing-test', 'fallback') ?? '');
    }

    public function plain(): Response
    {
        return new Response('plain');
    }
}
