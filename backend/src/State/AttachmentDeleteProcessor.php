<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Attachment\AttachmentStorage;
use App\Entity\Attachment;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** @implements ProcessorInterface<Attachment, null> */
final class AttachmentDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.remove_processor')]
        private readonly ProcessorInterface $remove,
        private readonly AttachmentStorage $storage,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->remove->process($data, $operation, $uriVariables, $context);
        $this->storage->delete($data);

        return null;
    }
}
