<?php

namespace App\Controller;

use App\Security\Roles;
use App\Update\ReleaseChecker;
use App\Update\Updater;
use App\Update\UpdateException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Version of the platform, available updates and one-click update (administrators). */
final class SystemUpdateController extends AbstractController
{
    public function __construct(
        private readonly ReleaseChecker $releases,
        private readonly Updater $updater,
    ) {
    }

    #[Route('/api/system/version', name: 'api_system_version', methods: ['GET'])]
    public function version(): JsonResponse
    {
        $current = $this->releases->current();

        return $this->json(['version' => $current->label(), 'release' => $current->release]);
    }

    #[IsGranted(Roles::ADMIN)]
    #[Route('/api/system/update', name: 'api_system_update', methods: ['GET'])]
    public function status(#[MapQueryParameter] bool $refresh = false): JsonResponse
    {
        $current = $this->releases->current();
        $latest = null;
        $error = null;
        try {
            $latest = $this->releases->latest($refresh);
        } catch (UpdateException $e) {
            $error = $e->getMessage();
        }

        return $this->json([
            'current' => ['version' => $current->label(), 'release' => $current->release, 'raw' => $current->raw],
            'latest' => $latest?->toArray(),
            // null: this build has no version number to compare (development build, branch image).
            'updateAvailable' => null === $latest ? false : $current->isOlderThan($latest->version),
            'checkEnabled' => $this->releases->isEnabled(),
            'repositoryUrl' => $this->releases->repositoryUrl(),
            'error' => $error,
            'updater' => ['configured' => $this->updater->isConfigured()],
        ]);
    }

    #[IsGranted(Roles::ADMIN)]
    #[Route('/api/system/update', name: 'api_system_update_start', methods: ['POST'])]
    public function start(): JsonResponse
    {
        try {
            $this->updater->start();
        } catch (UpdateException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        return $this->json(['started' => true, 'from' => $this->releases->current()->label()], Response::HTTP_ACCEPTED);
    }
}
