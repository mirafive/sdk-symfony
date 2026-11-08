<?php

declare(strict_types=1);

namespace MiraFive\Symfony\Command;

use MiraFive\Mira;
use MiraFive\MiraError;
use MiraFive\ScriptTag;
use MiraFive\Symfony\ClientFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Checks the keys and host: the ingest answers whether the key works and stores nothing. */
#[AsCommand(name: self::NAME, description: self::DESCRIPTION)]
final class CheckCommand extends Command
{
    public const string NAME = 'mirafive:check';

    public const string DESCRIPTION = 'Check the MIRA FIVE keys and host: the ingest validates a probe and stores nothing';

    public function __construct(private readonly ClientFactory $clients, private readonly ?string $publicKey = null)
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
        $key = $this->clients->secretKey();
        $public = trim($this->publicKey ?? '');

        $io->definitionList(
            ['Host' => $this->clients->host()],
            ['Secret key' => $key === '' ? '(none)' : substr($key, 0, 12).'…'],
            ['Public key' => $public === '' ? '(none, mirafive_script() prints nothing)' : (ScriptTag::isSecretKey($public) ? '(a secret key!)' : $public)],
            ['Delivery' => $this->clients->queued() ? 'Messenger' : 'after the response'],
        );

        $problem = match (true) {
            ! $this->clients->switchedOn() => 'MIRA FIVE is switched off (mirafive.enabled: false).',
            ScriptTag::isSecretKey($public) => 'MIRAFIVE_PUBLIC_KEY holds a secret key. Use the public key (mira_pk_…); mirafive_script() refuses to print a secret key.',
            $key === '' => 'No secret key: set MIRAFIVE_SECRET_KEY to the project\'s secret key (mira_sk_…).',
            str_starts_with($key, Mira::PUBLIC_KEY_PREFIX) => 'MIRAFIVE_SECRET_KEY holds a public key (mira_pk_…). Server code needs the secret key (mira_sk_…).',
            default => null,
        };

        if ($problem !== null) {
            $io->error($problem);

            return self::FAILURE;
        }

        try {
            $this->clients->mira()->checkConnection();
        } catch (MiraError $error) {
            $io->error($error->getMessage());

            return self::FAILURE;
        }

        $io->success('The secret key and host work. Nothing was stored.');

        return self::SUCCESS;
    }
}
