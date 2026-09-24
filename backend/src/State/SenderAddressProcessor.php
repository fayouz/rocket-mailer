<?php

namespace App\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\SenderAddress;
use App\Repository\SenderAddressRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Keeps exactly one default sender address while there is at least one.
 *
 * @implements ProcessorInterface<SenderAddress, SenderAddress|null>
 */
final class SenderAddressProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persist,
        #[Autowire(service: 'api_platform.doctrine.orm.state.remove_processor')]
        private readonly ProcessorInterface $remove,
        private readonly SenderAddressRepository $senders,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?SenderAddress
    {
        $others = array_filter($this->senders->findAll(), static fn (SenderAddress $s) => $s !== $data);

        if ($operation instanceof DeleteOperationInterface) {
            $this->remove->process($data, $operation, $uriVariables, $context);
            if ($data->isDefault() && [] !== $others) {
                reset($others)->setIsDefault(true);
                $this->em->flush();
            }

            return null;
        }

        if ([] === array_filter($others, static fn (SenderAddress $s) => $s->isDefault())) {
            $data->setIsDefault(true);
        }
        if ($data->isDefault()) {
            foreach ($others as $other) {
                $other->setIsDefault(false);
            }
        }

        return $this->persist->process($data, $operation, $uriVariables, $context);
    }
}
