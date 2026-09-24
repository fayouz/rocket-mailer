<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Email;
use App\Message\SendEmailMessage;
use App\Security\ActorContext;
use App\Security\Roles;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

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
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Email
    {
        $user = $this->actor->requireUser();

        $template = $data->getTemplate();
        if (null !== $template && !$template->isShared() && $template->getOwner() !== $user && !$this->security->isGranted(Roles::ADMIN)) {
            throw new AccessDeniedHttpException('You cannot use this template.');
        }

        $data->setSender($user);
        $data->setApplication($this->actor->getApplication());

        $email = $this->persist->process($data, $operation, $uriVariables, $context);
        $this->bus->dispatch(new SendEmailMessage($email->getId()->toRfc4122()));

        return $email;
    }
}
