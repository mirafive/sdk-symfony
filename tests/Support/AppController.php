<?php

declare(strict_types=1);

namespace MiraFive\Symfony\Tests\Support;

use MiraFive\Mira;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AppController
{
    public function __construct(private Mira $mira) {}

    public function track(Request $request): Response
    {
        $this->mira->track((string) $request->query->get('event', 'signup'), userId: 'u_42', properties: ['plan' => 'pro']);

        return new Response('tracked');
    }

    public function plain(): Response
    {
        return new Response('plain');
    }
}
