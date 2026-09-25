<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Mailbox;
use App\Mailbox\SecretBox;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Encrypts the credentials received for a mailbox before storing it.
 *
 * @implements ProcessorInterface<Mailbox, Mailbox>
 */
final class MailboxProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persist,
        private readonly SecretBox $secrets,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Mailbox
    {
        $data->sealSecrets($this->secrets->encrypt(...));

        return $this->persist->process($data, $operation, $uriVariables, $context);
    }
}
