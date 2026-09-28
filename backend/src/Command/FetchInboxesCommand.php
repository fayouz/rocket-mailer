<?php

namespace App\Command;

use App\Inbox\InboxFetcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:inbox:fetch', description: 'Fetch the new messages of the shared inboxes now.')]
final class FetchInboxesCommand
{
    public function __construct(private readonly InboxFetcher $fetcher)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $results = $this->fetcher->fetchAll();
        if ([] === $results) {
            $io->note('No shared inbox is enabled.');
        }
        foreach ($results as $name => $result) {
            \is_int($result) ? $io->text(\sprintf('%s: %d new message(s)', $name, $result)) : $io->warning(\sprintf('%s: %s', $name, $result));
        }

        return Command::SUCCESS;
    }
}
