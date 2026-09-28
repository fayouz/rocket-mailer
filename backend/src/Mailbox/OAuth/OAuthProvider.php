<?php

namespace App\Mailbox\OAuth;

use App\Entity\Mailbox;

/**
 * OAuth 2 providers of mailboxes: consent and token endpoints, scopes (IMAP and SMTP with XOAUTH2).
 */
final readonly class OAuthProvider
{
    public const GOOGLE = 'google';
    public const MICROSOFT = 'microsoft';
    public const IDS = [self::GOOGLE, self::MICROSOFT];

    /** Full IMAP/SMTP access: a "restricted" scope for Google (app verification needed for public use). */
    public const GOOGLE_SCOPE = 'https://mail.google.com/';
    public const MICROSOFT_SCOPE = 'offline_access https://outlook.office.com/IMAP.AccessAsUser.All https://outlook.office.com/SMTP.Send';

    /** @param array<string, string> $authorizeParams */
    private function __construct(
        public string $id,
        public string $label,
        public string $authType,
        public string $authorizeUrl,
        public string $tokenUrl,
        public string $scope,
        public array $authorizeParams,
    ) {
    }

    /** @param string $tenant Microsoft Entra tenant: "common" (work and personal accounts), "organizations", "consumers" or an id */
    public static function get(string $id, string $tenant = 'common'): self
    {
        $tenant = rawurlencode('' === trim($tenant) ? 'common' : trim($tenant));

        return match ($id) {
            self::GOOGLE => new self(
                self::GOOGLE,
                'Google',
                Mailbox::AUTH_OAUTH_GOOGLE,
                'https://accounts.google.com/o/oauth2/v2/auth',
                'https://oauth2.googleapis.com/token',
                self::GOOGLE_SCOPE,
                // A refresh token on every consent.
                ['access_type' => 'offline', 'prompt' => 'consent'],
            ),
            self::MICROSOFT => new self(
                self::MICROSOFT,
                'Microsoft',
                Mailbox::AUTH_OAUTH_MICROSOFT,
                "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/authorize",
                "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token",
                self::MICROSOFT_SCOPE,
                ['prompt' => 'select_account'],
            ),
            default => throw new \InvalidArgumentException(\sprintf('Unknown OAuth provider "%s".', $id)),
        };
    }

    public static function forAuthType(string $authType): string
    {
        return match ($authType) {
            Mailbox::AUTH_OAUTH_GOOGLE => self::GOOGLE,
            Mailbox::AUTH_OAUTH_MICROSOFT => self::MICROSOFT,
            default => throw new \InvalidArgumentException(\sprintf('"%s" is not an OAuth authentication.', $authType)),
        };
    }
}
