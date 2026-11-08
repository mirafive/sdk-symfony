<?php

declare(strict_types=1);

namespace MiraFive\Symfony\Messenger;

/** One batch as the client handed it off. It carries no key: the worker sends it with its own. */
final readonly class DeliverBatch
{
    public function __construct(public string $payload) {}
}
