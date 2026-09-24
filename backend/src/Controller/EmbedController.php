<?php

namespace App\Controller;

use App\Entity\Application;
use App\Repository\ApplicationRepository;
use App\Security\ActorContext;
use App\Security\EmbedTokenAuthenticator;
use App\Security\Roles;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

final class EmbedController extends AbstractController
{
    public function __construct(private readonly ActorContext $actor)
    {
    }

    /**
     * Called server-to-server by an application impersonating a user (Bearer rma_… + X-Impersonate-User).
     * Returns a short-lived token the application hands to the embedded compose UI.
     */
    #[Route('/api/embed/token', name: 'api_embed_token', methods: ['POST'])]
    #[IsGranted(Roles::IMPERSONATION)]
    public function token(
        JWTEncoderInterface $encoder,
        #[Autowire(env: 'int:EMBED_TOKEN_TTL')] int $ttl,
    ): JsonResponse {
        if ($this->actor->isEmbed()) {
            throw $this->createAccessDeniedException('Embed sessions cannot mint embed tokens.');
        }

        $user = $this->actor->requireUser();
        $application = $this->actor->getApplication() ?? throw $this->createAccessDeniedException();
        $expiresAt = time() + $ttl;

        $token = $encoder->encode([
            'username' => $user->getUserIdentifier(),
            'app' => $application->getId()->toRfc4122(),
            'scope' => EmbedTokenAuthenticator::SCOPE,
            'iat' => time(),
            'exp' => $expiresAt,
        ]);

        return $this->json([
            'token' => $token,
            'applicationId' => $application->getId(),
            'expiresAt' => (new \DateTimeImmutable('@'.$expiresAt))->format(\DATE_ATOM),
        ], Response::HTTP_CREATED);
    }

    #[Route('/api/embed/context', name: 'api_embed_context', methods: ['GET'])]
    #[IsGranted(Roles::EMBED)]
    public function context(): JsonResponse
    {
        $application = $this->actor->getApplication() ?? throw $this->createAccessDeniedException();

        return $this->json([
            'user' => $this->actor->requireUser(),
            'application' => [
                'id' => $application->getId(),
                'name' => $application->getName(),
                'allowedOrigins' => $application->getAllowedOrigins(),
            ],
        ], context: ['groups' => ['user:summary']]);
    }

    /**
     * Public: origins allowed to frame the compose UI for an application.
     * Used by the front server to emit "Content-Security-Policy: frame-ancestors" and by the iframe
     * to validate postMessage senders before trusting a token.
     */
    #[Route('/api/embed/frame-policy', name: 'api_embed_frame_policy', methods: ['GET'])]
    public function framePolicy(ApplicationRepository $applications, #[MapQueryParameter] string $app = ''): JsonResponse
    {
        $application = Uuid::isValid($app) ? $applications->find($app) : null;
        $origins = $application instanceof Application && $application->isEnabled() && $application->canImpersonate()
            ? $application->getAllowedOrigins()
            : [];

        $response = $this->json(['frameAncestors' => $origins]);
        $response->setPublic();
        $response->setMaxAge(60);

        return $response;
    }
}
