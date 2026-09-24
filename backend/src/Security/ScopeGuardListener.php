<?php

namespace App\Security;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Restricts delegated sessions to an allow-list of endpoints:
 * - embed sessions (ROLE_EMBED) can only compose and send emails;
 * - applications that do not impersonate anyone can only identify themselves.
 * Runs after the firewall (priority 8).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
final class ScopeGuardListener
{
    private const EMBED_ALLOWED = [
        ['GET', '#^/api/me$#'],
        ['GET', '#^/api/embed/context$#'],
        ['GET', '#^/api/senders$#'],
        ['GET', '#^/api/email_templates(/[^/]+)?$#'],
        ['POST', '#^/api/emails$#'],
        ['GET', '#^/api/emails/[^/]+$#'],
        ['POST', '#^/api/attachments$#'],
        ['GET', '#^/api/attachments/[^/]+$#'],
        ['DELETE', '#^/api/attachments/[^/]+$#'],
    ];

    private const APPLICATION_ALLOWED = [
        ['GET', '#^/api/me$#'],
    ];

    public function __construct(private readonly Security $security)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api') || $request->isMethod('OPTIONS')) {
            return;
        }

        $user = $this->security->getUser();
        $allowed = match (true) {
            $user instanceof ApplicationUser => self::APPLICATION_ALLOWED,
            null !== $user && $this->security->isGranted(Roles::EMBED) => self::EMBED_ALLOWED,
            default => null,
        };

        if (null === $allowed) {
            return;
        }

        foreach ($allowed as [$method, $pattern]) {
            if ($request->isMethod($method) && preg_match($pattern, $request->getPathInfo())) {
                return;
            }
        }

        throw new AccessDeniedHttpException('This endpoint is not available with the current credentials.');
    }
}
