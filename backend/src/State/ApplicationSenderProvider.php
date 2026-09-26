<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\ApplicationSender;
use App\Repository\ApplicationSenderRepository;
use Rocket\Core\Repository\ApplicationRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Sender settings of the application {id}: the saved ones, or empty ones for an application that has none yet
 * (saved on the first PATCH). Not found when the application itself does not exist.
 *
 * @implements ProviderInterface<ApplicationSender>
 */
final class ApplicationSenderProvider implements ProviderInterface
{
    public function __construct(
        private readonly ApplicationRepository $applications,
        private readonly ApplicationSenderRepository $senders,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?ApplicationSender
    {
        $id = (string) ($uriVariables['id'] ?? '');
        $application = Uuid::isValid($id) ? $this->applications->find($id) : null;

        return null === $application ? null : $this->senders->forApplication($application);
    }
}
