<?php

declare(strict_types=1);

namespace MiraFive\Symfony\Command;

use MiraFive\Mira;
use MiraFive\MiraError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Sends `$install_check` (never stored or billed) to MIRA FIVE and prints the receipt. `send()` never goes through Messenger. */
#[AsCommand(name: self::NAME, description: self::DESCRIPTION)]
final class CheckCommand extends Command
{
    public const string NAME = 'mirafive:check';

    public const string DESCRIPTION = 'Checks the MIRA FIVE secret key and host with an install check';

    private const array HINTS = [
        'unauthorized' => 'MIRAFIVE_SECRET_KEY must hold the secret key of a server source in MIRA FIVE.',
        'website_key_as_bearer' => 'This is the public website key. Server code needs the secret key of a server source.',
        'network_error' => 'MIRA FIVE could not be reached. Check MIRAFIVE_HOST and outgoing HTTPS from this machine.',
        'timeout' => 'MIRA FIVE did not answer in time. Check MIRAFIVE_HOST and outgoing HTTPS from this machine.',
    ];

    public function __construct(private readonly Mira $mira)
    {
        parent::__construct(self::NAME);
    }

    protected function configure(): void
    {
        $this->setDescription(self::DESCRIPTION);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (! $this->mira->enabled) {
            $io->error('MIRA FIVE is disabled: set MIRAFIVE_SECRET_KEY (or mirafive.secret_key) and keep mirafive.enabled true.');

            return self::FAILURE;
        }

        $io->text("Sending \$install_check to {$this->mira->host} …");

        try {
            $receipt = $this->mira->send([['name' => '$install_check']]);
        } catch (MiraError $error) {
            $io->error("{$error->errorCode}: {$error->getMessage()}");

            if (isset(self::HINTS[$error->errorCode])) {
                $io->text(self::HINTS[$error->errorCode]);
            }

            return self::FAILURE;
        }

        $io->definitionList(
            ['batch' => $receipt->batch],
            ['accepted' => (string) $receipt->accepted],
            ['dropped' => (string) $receipt->dropped],
            ['reason' => $receipt->reason ?? '-'],
        );

        if ($receipt->reason !== 'install_check') {
            $io->warning('MIRA FIVE answered, but not with the install_check receipt. Is the host a MIRA FIVE ingest host?');

            return self::FAILURE;
        }

        $io->success('The secret key and host work. Nothing was stored or billed.');

        return self::SUCCESS;
    }
}
