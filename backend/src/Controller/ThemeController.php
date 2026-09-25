<?php

namespace App\Controller;

use App\Entity\Application;
use App\Repository\ApplicationRepository;
use App\Theme\ProjectTheme;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class ThemeController extends AbstractController
{
    /**
     * Public (the login page and the embedded composer need it before any session): the palette to apply,
     * the application's one with ?app=<id>, else the project's one. Colors are not secret.
     */
    #[Route('/api/theme', name: 'api_theme', methods: ['GET'])]
    public function __invoke(ProjectTheme $theme, ApplicationRepository $applications, #[MapQueryParameter] string $app = ''): JsonResponse
    {
        $application = Uuid::isValid($app) ? $applications->find($app) : null;
        // Not cached: a palette change shows at the next page load.
        return $this->json($theme->for($application instanceof Application && $application->isEnabled() ? $application : null));
    }
}
