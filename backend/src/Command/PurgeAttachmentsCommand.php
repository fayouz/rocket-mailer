<?php

namespace App\Command;

use App\Attachment\AttachmentStorage;
use App\Repository\AttachmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:attachments:purge', description: 'Delete uploaded attachments that were never sent (schedule it with cron).')]
final class PurgeAttachmentsCommand
{
    public function __construct(
        private readonly AttachmentRepository $attachments,
        private readonly AttachmentStorage $storage,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Option(description: 'Age of the uploads to delete, e.g. "24 hours"')] string $olderThan = '24 hours'): int
    {
        $orphans = $this->attachments->findOrphansBefore(new \DateTimeImmutable('-'.$olderThan));
        foreach ($orphans as $attachment) {
            $this->storage->delete($attachment);
            $this->em->remove($attachment);
        }
        $this->em->flush();

        $io->success(\sprintf('%d unsent attachment(s) deleted.', \count($orphans)));

        return Command::SUCCESS;
    }
}
