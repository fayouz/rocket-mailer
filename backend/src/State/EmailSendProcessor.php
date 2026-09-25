<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Application;
use App\Entity\Email;
use App\Entity\Mailbox;
use App\Repository\MailboxRepository;
use App\Message\SendEmailMessage;
use App\Security\ActorContext;
use App\Security\Roles;
use App\Sender\SenderPolicy;
use App\Template\Placeholders;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\Address;

/**
 * Stores the email as queued, sent on behalf of the current user (possibly through an application), then dispatches it.
 *
 * @implements ProcessorInterface<Email, Email>
 */
final class EmailSendProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persist,
        private readonly ActorContext $actor,
        private readonly Security $security,
        private readonly MessageBusInterface $bus,
        private readonly SenderPolicy $senders,
        private readonly MailboxRepository $mailboxes,
        #[Autowire(env: 'int:ATTACHMENTS_MAX_TOTAL_SIZE')] private readonly int $maxTotalAttachmentSize,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Email
    {
        $user = $this->actor->requireUser();
        $application = $this->actor->getApplication();
        // First: resolving may flush (first-run seeding of the sender addresses).
        $mailbox = $this->resolveMailbox($data, $application);
        if (null !== $mailbox) {
            $data->setMailbox($mailbox);
            $data->applyFrom($mailbox->toAddress());
        } else {
            $data->applyFrom($this->senders->resolve($data->getRequestedFrom(), $user, $application));
        }

        $template = $data->getTemplate();
        if (null !== $template && !$template->isShared() && $template->getOwner() !== $user && !$this->security->isGranted(Roles::ADMIN)) {
            throw new AccessDeniedHttpException('You cannot use this template.');
        }
        $this->renderContent($data);

        $totalSize = 0;
        foreach ($data->getAttachments() as $attachment) {
            if ($attachment->getOwner() !== $user) {
                throw new AccessDeniedHttpException('You can only attach files you uploaded.');
            }
            if (null !== $attachment->getEmail()) {
                throw new UnprocessableEntityHttpException(\sprintf('"%s" has already been sent with another email.', $attachment->getFilename()));
            }
            $totalSize += $attachment->getSize();
        }
        if ($totalSize > $this->maxTotalAttachmentSize) {
            throw new UnprocessableEntityHttpException(\sprintf('Attachments exceed %d MB in total.', intdiv($this->maxTotalAttachmentSize, 1024 * 1024)));
        }
        foreach ($data->getAttachments() as $attachment) {
            $attachment->attachTo($data);
        }

        $data->setSender($user);
        $data->setApplication($application);

        $email = $this->persist->process($data, $operation, $uriVariables, $context);
        $this->bus->dispatch(new SendEmailMessage($email->getId()->toRfc4122()));

        return $email;
    }

    /**
     * The sending mailbox: given explicitly ("mailbox"), or designated by its address in "from".
     * Through an application, only its own mailboxes; otherwise, those available to all users.
     */
    private function resolveMailbox(Email $email, ?Application $application): ?Mailbox
    {
        $requested = null;
        if (null !== $email->getRequestedFrom() && '' !== trim($email->getRequestedFrom())) {
            try {
                $requested = mb_strtolower(Address::create(trim($email->getRequestedFrom()))->getAddress());
            } catch (\Throwable) {
                $requested = null; // Reported by SenderPolicy.
            }
        }

        $mailbox = $email->getMailbox();
        if (null === $mailbox) {
            if (null === $requested) {
                return null;
            }
            foreach ($this->mailboxes->usableBy($application) as $candidate) {
                if ($candidate->getEmail() === $requested) {
                    return $candidate;
                }
            }

            return null;
        }

        if (!$mailbox->isUsableBy($application)) {
            throw new UnprocessableEntityHttpException(\sprintf('The mailbox "%s" cannot be used here.', $mailbox->getName()));
        }
        if (null !== $requested && $requested !== $mailbox->getEmail()) {
            throw new UnprocessableEntityHttpException('"from" and "mailbox" designate different addresses.');
        }

        return $mailbox;
    }

    /**
     * Fills the subject and the body from the template when they are missing, then replaces the variables.
     * Every variable of the template must end up with a value (given, or its default): no "{{ … }}" is ever sent.
     */
    private function renderContent(Email $email): void
    {
        $template = $email->getTemplate();
        if (null !== $template) {
            if ('' === trim($email->getHtmlBody())) {
                $email->setHtmlBody($template->getRenderedHtml());
            }
            if ('' === trim($email->getSubject())) {
                $email->setSubject($template->getDefaultSubject() ?? '');
            }
        }

        $values = Placeholders::flatten($email->getVariables()) + ($template?->getVariableDefaults() ?? []);
        if ($values) {
            $email->setSubject(Placeholders::render($email->getSubject(), $values, html: false));
            $email->setHtmlBody(Placeholders::render($email->getHtmlBody(), $values, html: true));
        }

        if (null !== $template) {
            $expected = array_column($template->getVariables(), 'name');
            $missing = array_values(array_intersect($expected, Placeholders::names($email->getSubject(), $email->getHtmlBody())));
            if ($missing) {
                throw new UnprocessableEntityHttpException(\sprintf('Missing template variables: %s.', implode(', ', $missing)));
            }
        }

        if ('' === trim($email->getSubject()) || mb_strlen($email->getSubject()) > 255) {
            throw new UnprocessableEntityHttpException('subject: the subject is required (255 characters at most); this template has no default subject.');
        }
        if ('' === trim($email->getHtmlBody())) {
            throw new UnprocessableEntityHttpException('htmlBody: the content is required.');
        }
    }
}
