<?php

namespace App\Controller;

use App\Security\ActorContext;
use App\Sender\SenderPolicy;
use App\Sender\Settings;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class SenderController extends AbstractController
{
    public function __construct(
        private readonly SenderPolicy $policy,
        private readonly Settings $settings,
    ) {
    }

    /** "From" addresses offered to the current user in the composer, the default first. */
    #[Route('/api/senders', name: 'api_senders', methods: ['GET'])]
    public function senders(ActorContext $actor): JsonResponse
    {
        return $this->json($this->policy->options($actor->requireUser()));
    }

    #[Route('/api/settings', name: 'api_settings', methods: ['GET'])]
    #[IsGranted(Roles::ADMIN)]
    public function settings(ActorContext $actor, #[Autowire(env: 'MAILER_DEFAULT_FROM')] string $installDefault): JsonResponse
    {
        // Seeds the sender addresses from MAILER_DEFAULT_FROM on a fresh install.
        $this->policy->options($actor->requireUser());

        return $this->json([
            'personalFromAllowed' => $this->settings->isPersonalFromAllowed(),
            'installDefaultFrom' => $installDefault,
        ]);
    }

    #[Route('/api/settings', name: 'api_settings_update', methods: ['PATCH'])]
    #[IsGranted(Roles::ADMIN)]
    public function updateSettings(#[MapRequestPayload] SettingsInput $input, EntityManagerInterface $em): JsonResponse
    {
        if (null !== $input->personalFromAllowed) {
            $this->settings->set(Settings::PERSONAL_FROM_ALLOWED, $input->personalFromAllowed);
        }
        $em->flush();

        return $this->json(['personalFromAllowed' => $this->settings->isPersonalFromAllowed()]);
    }
}
