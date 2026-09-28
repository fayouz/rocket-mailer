<?php

namespace App\Security;

use App\Entity\Conversation;
use App\Entity\Mailbox;
use App\Repository\MailboxMemberRepository;
use Rocket\Core\Entity\User;
use Rocket\Core\Security\ActorContext;
use Rocket\Core\Security\Roles;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Shared inboxes, only in Rocket Mailer itself (never through an application token or an embedded composer):
 * - INBOX_READ (mailbox or conversation): members read, reply, assign, close; admins included only if members;
 * - INBOX_MANAGE (mailbox): managers and administrators manage the members.
 *
 * @extends Voter<string, Mailbox|Conversation>
 */
final class MailboxVoter extends Voter
{
    public const READ = 'INBOX_READ';
    public const MANAGE = 'INBOX_MANAGE';

    public function __construct(
        private readonly MailboxMemberRepository $members,
        private readonly ActorContext $actor,
        private readonly AccessDecisionManagerInterface $decisions,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::READ, self::MANAGE], true) && ($subject instanceof Mailbox || $subject instanceof Conversation);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || null !== $this->actor->getApplication() || $this->actor->isEmbed()) {
            return false;
        }
        $mailbox = $subject instanceof Conversation ? $subject->getMailbox() : $subject;
        if (self::MANAGE === $attribute && $this->decisions->decide($token, [Roles::ADMIN])) {
            return true;
        }
        if (!$mailbox->isEnabled() || (self::READ === $attribute && !$mailbox->isInboxEnabled())) {
            return false;
        }
        $membership = $this->members->membership($mailbox, $user);

        return null !== $membership && (self::READ === $attribute || $membership->isManager());
    }
}
