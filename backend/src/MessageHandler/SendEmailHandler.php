<?php

namespace App\MessageHandler;

use App\Attachment\AttachmentStorage;
use App\Mailbox\MailboxConnector;
use Psr\Log\LoggerInterface;
use App\Enum\EmailStatus;
use App\Message\SendEmailMessage;
use App\Repository\EmailRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email as MimeEmail;

#[AsMessageHandler]
final class SendEmailHandler
{
    public function __construct(
        private readonly EmailRepository $emails,
        private readonly MailerInterface $mailer,
        private readonly EntityManagerInterface $em,
        private readonly AttachmentStorage $storage,
        private readonly MailboxConnector $connector,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendEmailMessage $message): void
    {
        $email = $this->emails->find($message->emailId);
        if (null === $email || EmailStatus::Queued !== $email->getStatus()) {
            return;
        }

        $sender = $email->getSender();
        $userAddress = new Address($sender->getEmail(), $sender->getDisplayName());

        $mime = (new MimeEmail())
            ->subject($email->getSubject())
            ->html($email->getHtmlBody())
            ->to(...$email->getTo())
            ->cc(...$email->getCc())
            ->bcc(...$email->getBcc());

        foreach ($email->getAttachments() as $attachment) {
            $path = $this->storage->path($attachment);
            if (!is_file($path)) {
                $email->markFailed(\sprintf('Attachment "%s" is missing from the storage (ATTACHMENTS_DIR must be shared by the API and the worker).', $attachment->getFilename()));
                $this->em->flush();

                return;
            }
            $mime->attachFromPath($path, $attachment->getFilename(), $attachment->getMimeType());
        }

        $mailbox = $email->getMailbox();
        $from = $email->getFromAddress() ?? $userAddress;
        $mime->from($from);
        // Replies reach the user when the email leaves from a shared address; a mailbox receives its own replies.
        if (null === $mailbox && $from->getAddress() !== $sender->getEmail()) {
            $mime->replyTo($userAddress);
        }

        try {
            if (null === $mailbox) {
                $this->mailer->send($mime);
                $sent = null;
            } else {
                $sent = $this->connector->transport($mailbox)->send($mime);
            }
            $email->markSent();
        } catch (TransportExceptionInterface|\RuntimeException $e) {
            $email->markFailed($e->getMessage());
            $this->em->flush();

            return;
        }
        $this->em->flush();

        // The copy in the mailbox's "Sent" folder never makes a sent email fail.
        if (null !== $mailbox && $mailbox->isImapEnabled() && null !== $sent) {
            try {
                $email->markArchived($this->connector->archive($mailbox, $sent->toString()));
            } catch (\Throwable $e) {
                $this->logger->warning('Copy to the IMAP "Sent" folder failed for email {id}: {error}', ['id' => $email->getId()->toRfc4122(), 'error' => $e->getMessage()]);
                $email->markArchiveFailed($e->getMessage());
            }
            $this->em->flush();
        }
    }
}
