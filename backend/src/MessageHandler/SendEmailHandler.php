<?php

namespace App\MessageHandler;

use App\Attachment\AttachmentStorage;
use App\Enum\EmailStatus;
use App\Message\SendEmailMessage;
use App\Repository\EmailRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
        /** When set, emails are sent from this address (with the user's name) and replies go to the user. */
        #[Autowire(env: 'MAILER_SENDER')]
        private readonly string $enforcedSender,
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

        if ('' !== $this->enforcedSender) {
            $mime->from(new Address($this->enforcedSender, $sender->getDisplayName()))->replyTo($userAddress);
        } else {
            $mime->from($userAddress);
        }

        try {
            $this->mailer->send($mime);
            $email->markSent();
        } catch (TransportExceptionInterface $e) {
            $email->markFailed($e->getMessage());
        }

        $this->em->flush();
    }
}
