<?php

declare(strict_types=1);

namespace MiraFive\Symfony\Messenger;

use MiraFive\MiraError;
use MiraFive\Symfony\ClientFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Sends one queued batch with the worker's client: the same events and sequence number however
 * often it runs. A retryable failure is thrown for Messenger's retry strategy; a refusal, or a
 * worker without a secret key, is final and goes to the failure transport.
 */
final readonly class DeliverBatchHandler
{
    public function __construct(private ClientFactory $clients, private ?LoggerInterface $logger = null) {}

    public function __invoke(DeliverBatch $message): void
    {
        // Switched off on this worker: dropped on purpose, as everything else is.
        if (! $this->clients->switchedOn()) {
            return;
        }

        if ($this->clients->secretKey() === '') {
            $problem = 'MIRA FIVE cannot deliver a queued batch: this worker has no secret key (MIRAFIVE_SECRET_KEY).';
            $this->logger?->error($problem);

            throw new UnrecoverableMessageHandlingException($problem);
        }

        try {
            $this->clients->mira()->deliverPrepared($message->payload);
        } catch (MiraError $error) {
            throw $error->retryable ? $error : new UnrecoverableMessageHandlingException($error->getMessage(), 0, $error);
        }
    }
}
