<?php

declare(strict_types=1);

namespace MiraFive\Symfony\Messenger;

/** One batch as the PHP SDK encoded it (PROTOCOL §3). Carries no key: the handler adds the bearer when it sends. */
final readonly class DeliverBatch
{
    public function __construct(public string $body) {}
}
