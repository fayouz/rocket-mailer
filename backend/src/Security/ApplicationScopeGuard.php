<?php

namespace App\Security;

use Rocket\Core\Security\ApplicationUser;
use Rocket\Core\Security\ScopeGuardListener;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Widens the allow-list of rocket-core's ScopeGuardListener for applications calling without impersonating anyone
 * (their own token "rma_…", or a Rocket Auth client credentials token in suite mode): the shared inboxes attached
 * to them (list, read, reply). MailboxVoter::API then checks the attachment. Everything else goes to the core guard.
 */
#[AsDecorator(ScopeGuardListener::class)]
final class ApplicationScopeGuard
{
    public const APPLICATION_ALLOWED = [
        ['GET', '#^/api/inbox/mailboxes$#'],
        ['GET', '#^/api/inbox/mailboxes/[0-9a-f-]{36}/conversations$#'],
        ['GET', '#^/api/inbox/conversations/[0-9a-f-]{36}$#'],
        ['POST', '#^/api/inbox/conversations/[0-9a-f-]{36}/reply$#'],
    ];

    public function __construct(
        #[AutowireDecorated] private readonly ScopeGuardListener $inner,
        private readonly Security $security,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ($event->isMainRequest() && $this->security->getUser() instanceof ApplicationUser) {
            foreach (self::APPLICATION_ALLOWED as [$method, $pattern]) {
                if ($request->isMethod($method) && preg_match($pattern, $request->getPathInfo())) {
                    return;
                }
            }
        }

        ($this->inner)($event);
    }
}
