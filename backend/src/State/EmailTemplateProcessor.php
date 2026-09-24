<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\EmailTemplate;
use App\Security\ActorContext;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** @implements ProcessorInterface<EmailTemplate, EmailTemplate> */
final class EmailTemplateProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persist,
        private readonly ActorContext $actor,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): EmailTemplate
    {
        $data->setOwner($this->actor->requireUser());

        return $this->persist->process($data, $operation, $uriVariables, $context);
    }
}
