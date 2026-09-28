<?php

namespace App\Mailbox\OAuth;

use App\Entity\Mailbox;
use App\Mailbox\SecretBox;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Access tokens of OAuth mailboxes: the cached one while it is valid, else a new one from the refresh token
 * (stored encrypted, with the rotated refresh token when the provider sends one).
 */
final class OAuthTokens
{
    /** Refreshed this long before it expires. */
    private const MARGIN = 120;

    public function __construct(
        private readonly OAuthClient $client,
        private readonly SecretBox $secrets,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function accessToken(Mailbox $mailbox): string
    {
        $cached = $mailbox->getEncryptedOAuthAccessToken();
        $expiresAt = $mailbox->getOAuthExpiresAt();
        if (null !== $cached && null !== $expiresAt && $expiresAt->getTimestamp() - self::MARGIN > time()) {
            return $this->secrets->decrypt($cached);
        }

        $refreshToken = $mailbox->getEncryptedOAuthRefreshToken();
        if (null === $refreshToken) {
            throw new OAuthException('La boîte n’est pas connectée à son compte : lancez la connexion OAuth.');
        }
        $tokens = $this->client->refresh(OAuthProvider::forAuthType($mailbox->getAuthType()), $this->secrets->decrypt($refreshToken));
        $this->store($mailbox, $tokens);
        $this->em->flush();

        return $tokens['accessToken'];
    }

    /** @param array{accessToken: string, refreshToken: ?string, expiresIn: int} $tokens */
    public function store(Mailbox $mailbox, array $tokens): void
    {
        $mailbox->storeOAuthTokens(
            null === $tokens['refreshToken'] ? null : $this->secrets->encrypt($tokens['refreshToken']),
            $this->secrets->encrypt($tokens['accessToken']),
            new \DateTimeImmutable('+'.max(60, $tokens['expiresIn']).' seconds'),
        );
    }
}
