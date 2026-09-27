<?php

declare(strict_types=1);

namespace MiraFive\Symfony\Messenger;

use MiraFive\Mira;
use MiraFive\MiraError;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Sends one queued batch with the worker's own client, byte for byte. A retryable failure is thrown for Messenger's
 * retry strategy; a refusal is final and goes to the failure transport.
 */
final readonly class DeliverBatchHandler
{
    public function __construct(private Mira $mira) {}

    public function __invoke(DeliverBatch $message): void
    {
        try {
            $this->mira->deliverPrepared($message->body);
        } catch (MiraError $error) {
            throw $error->retryable ? $error : new UnrecoverableMessageHandlingException($error->getMessage(), 0, $error);
        }
    }
}
