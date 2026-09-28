<?php

namespace App\Security;

use App\Entity\Conversation;
use App\Entity\Mailbox;
use App\Repository\MailboxMemberRepository;
use Rocket\Core\Entity\User;
use Rocket\Core\Security\ActorContext;
use Rocket\Core\Security\ApplicationUser;
use Rocket\Core\Security\Roles;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Shared inboxes (never through an embedded composer):
 * - INBOX_READ (mailbox or conversation), in Rocket Mailer only: members read, reply, assign, close, write notes;
 *   admins included only if members;
 * - INBOX_API (mailbox or conversation): list, read and reply, also through an application: the application
 *   itself when the mailbox is attached to it, or an application impersonating a member;
 * - INBOX_MANAGE (mailbox): managers and administrators manage the members.
 * A personal mailbox: its owner only (its sole member); nobody manages its members, administrators included.
 *
 * @extends Voter<string, Mailbox|Conversation>
 */
final class MailboxVoter extends Voter
{
    public const READ = 'INBOX_READ';
    public const API = 'INBOX_API';
    public const MANAGE = 'INBOX_MANAGE';

    public function __construct(
        private readonly MailboxMemberRepository $members,
        private readonly ActorContext $actor,
        private readonly AccessDecisionManagerInterface $decisions,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::READ, self::API, self::MANAGE], true) && ($subject instanceof Mailbox || $subject instanceof Conversation);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if ($this->actor->isEmbed()) {
            return false;
        }
        $user = $token->getUser();
        $application = $this->actor->getApplication();
        $mailbox = $subject instanceof Conversation ? $subject->getMailbox() : $subject;
        if (self::API === $attribute && $user instanceof ApplicationUser) {
            // The application itself: only the shared inboxes attached to it.
            return !$mailbox->isPersonal() && $mailbox->isEnabled() && $mailbox->isInboxEnabled() && $mailbox->getApplications()->contains($user->getApplication());
        }
        if ($mailbox->isPersonal() && (self::MANAGE === $attribute || !$mailbox->isOwnedBy($user instanceof User ? $user : null))) {
            return false;
        }
        if (!$user instanceof User || (null !== $application && self::API !== $attribute)) {
            return false;
        }
        if (self::MANAGE === $attribute && $this->decisions->decide($token, [Roles::ADMIN])) {
            return true;
        }
        if (!$mailbox->isEnabled() || (self::MANAGE !== $attribute && !$mailbox->isInboxEnabled())) {
            return false;
        }
        $membership = $this->members->membership($mailbox, $user);

        return null !== $membership && (self::MANAGE !== $attribute || $membership->isManager());
    }
}
