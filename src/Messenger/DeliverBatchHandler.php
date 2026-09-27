<?php

declare(strict_types=1);

namespace MiraFive\Symfony\Messenger;

use MiraFive\MiraError;
use MiraFive\Symfony\ClientFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Sends one queued batch with the worker's own client, byte for byte. A retryable failure is thrown for Messenger's
 * retry strategy; a refusal, or a worker without a secret key, is final and goes to the failure transport.
 */
final readonly class DeliverBatchHandler
{
    public function __construct(private ClientFactory $clients, private ?LoggerInterface $logger = null) {}

    public function __invoke(DeliverBatch $message): void
    {
        // enabled: false on this worker: dropped on purpose, as the disabled client records nothing.
        if (! $this->clients->switchedOn()) {
            return;
        }

        $mira = $this->clients->mira();

        // Enabled but without a key, the core would answer with a local receipt and the batch would vanish.
        if (! $mira->enabled) {
            $problem = 'MIRA FIVE cannot deliver a queued batch: this worker has no secret key (MIRAFIVE_SECRET_KEY).';
            $this->logger?->error($problem);

            throw new UnrecoverableMessageHandlingException($problem);
        }

        try {
            $mira->deliverPrepared($message->body);
        } catch (MiraError $error) {
            throw $error->retryable ? $error : new UnrecoverableMessageHandlingException($error->getMessage(), 0, $error);
        }
    }
}
