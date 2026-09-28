<?php

namespace App\Mailbox\OAuth;

use App\Mailbox\SecretBox;

/**
 * The "state" parameter of the OAuth flow: the pending request (user, mailbox, PKCE verifier, return URL),
 * encrypted and authenticated with SecretBox, valid 15 minutes. Nothing is stored server side.
 */
final class OAuthState
{
    private const TTL = 900;

    public function __construct(private readonly SecretBox $secrets)
    {
    }

    /** @param array<string, mixed> $data */
    public function encode(array $data): string
    {
        $data['exp'] = time() + self::TTL;

        return rtrim(strtr(base64_encode($this->secrets->encrypt(json_encode($data, \JSON_THROW_ON_ERROR))), '+/', '-_'), '=');
    }

    /** @return array<string, mixed> */
    public function decode(string $state): array
    {
        try {
            $raw = base64_decode(strtr($state, '-_', '+/'), true);
            $data = false === $raw ? null : json_decode($this->secrets->decrypt($raw), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $data = null;
        }
        if (!\is_array($data) || !\is_int($data['exp'] ?? null)) {
            throw new OAuthException('Demande de connexion invalide : recommencez depuis Rocket Mailer.');
        }
        if ($data['exp'] < time()) {
            throw new OAuthException('La demande de connexion a expiré : recommencez.');
        }

        return $data;
    }
}
