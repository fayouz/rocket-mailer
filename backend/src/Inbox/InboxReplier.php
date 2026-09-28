<?php

namespace App\Inbox;

use App\Entity\Conversation;
use App\Entity\Email;
use App\Entity\InboundMessage;
use App\Message\SendEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Rocket\Core\Entity\Application;
use Rocket\Core\Entity\User;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Replies from a conversation: an Email sent through the mailbox's SMTP (then copied to its IMAP "Sent" folder by
 * SendEmailHandler, as any email of a mailbox), with In-Reply-To and References so that it threads for everyone.
 */
final class InboxReplier
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * @param list<string>|null $to default: the Reply-To (or the sender) of the last received message
     * @param list<string>      $cc
     * @param User|null         $author      null: an application acting for itself
     * @param string|null       $externalRef reference of the application, also stored on the conversation
     */
    public function reply(Conversation $conversation, ?User $author, string $htmlBody, ?array $to = null, array $cc = [], ?Application $application = null, ?string $externalRef = null): Email
    {
        $mailbox = $conversation->getMailbox();
        $last = $this->em->getRepository(InboundMessage::class)->findOneBy(['conversation' => $conversation], ['date' => 'DESC']);
        $to = array_values(array_filter($to ?? [$last?->getReplyTo() ?? $last?->getFromAddress()]));
        if ([] === $to) {
            throw new UnprocessableEntityHttpException('to: no recipient to reply to.');
        }
        if ('' === trim(strip_tags($htmlBody))) {
            throw new UnprocessableEntityHttpException('htmlBody: the content is required.');
        }

        $subject = $conversation->getSubject();
        if (!preg_match('/^re\s*:/i', $subject)) {
            $subject = 'Re: '.$subject;
        }
        $domain = substr((string) strrchr($mailbox->getEmail(), '@'), 1) ?: 'rocket-mailer.local';
        $messageId = '<'.Uuid::v7()->toRfc4122().'@'.$domain.'>';
        $references = $last?->getReferences() ?? [];
        if (null !== $last?->getMessageId()) {
            $references[] = $last->getMessageId();
        }

        $email = (new Email())
            ->setTo($to)
            ->setCc($cc)
            ->setSubject(mb_substr($subject, 0, 255))
            ->setHtmlBody($htmlBody)
            ->setMailbox($mailbox)
            ->applyFrom($mailbox->toAddress())
            ->setSender($author)
            ->setApplication($application)
            ->setExternalRef($externalRef ?? $conversation->getExternalRef())
            ->replyIn($conversation, $messageId, $last?->getMessageId(), \array_slice(array_values(array_unique($references)), -20));

        $violations = $this->validator->validate($email);
        if (\count($violations) > 0) {
            throw new UnprocessableEntityHttpException($violations->get(0)->getPropertyPath().': '.$violations->get(0)->getMessage());
        }

        if (null !== $email->getExternalRef()) {
            $conversation->setExternalRef($email->getExternalRef());
        }
        $conversation->recordReply($email, MimeParser::htmlToText($htmlBody));
        $this->em->persist($email);
        $this->em->flush();
        $this->bus->dispatch(new SendEmailMessage($email->getId()->toRfc4122()));

        return $email;
    }

    /**
     * An email sent through the API (POST /api/emails) from a shared inbox with an "externalRef" starts a (closed)
     * conversation, so that the answers thread into it and carry the application's reference.
     * Called before the email is persisted.
     */
    public function startConversation(Email $email): void
    {
        $mailbox = $email->getMailbox();
        if (null === $mailbox || !$mailbox->isInboxEnabled() || null === $email->getExternalRef() || null !== $email->getConversation()) {
            return;
        }
        $conversation = new Conversation($mailbox, $email->getSubject(), new \DateTimeImmutable());
        foreach ([...$email->getTo(), ...$email->getCc()] as $recipient) {
            $conversation->addParticipant($recipient);
        }
        $conversation->setExternalRef($email->getExternalRef());
        $conversation->setStatus(Conversation::STATUS_CLOSED);
        $domain = substr((string) strrchr($mailbox->getEmail(), '@'), 1) ?: 'rocket-mailer.local';
        $email->replyIn($conversation, '<'.Uuid::v7()->toRfc4122().'@'.$domain.'>', null, []);
        $conversation->recordReply($email, MimeParser::htmlToText($email->getHtmlBody()));
        $this->em->persist($conversation);
    }
}
