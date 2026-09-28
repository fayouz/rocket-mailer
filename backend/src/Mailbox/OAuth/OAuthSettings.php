<?php

namespace App\Mailbox\OAuth;

use App\Mailbox\SecretBox;
use Doctrine\ORM\EntityManagerInterface;
use Rocket\Core\Settings\Settings;

/**
 * OAuth applications (client id and secret) of Google and Microsoft, entered by an administrator: stored in the
 * database (rocket-core settings), the secret encrypted with SecretBox. Never in the environment.
 */
final class OAuthSettings
{
    private const KEY = 'mailbox_oauth_';

    public function __construct(
        private readonly Settings $settings,
        private readonly SecretBox $secrets,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return array{clientId: string, clientSecret: string, tenant: string, redirectUri: ?string}|null null: not configured */
    public function credentials(string $provider): ?array
    {
        $stored = $this->stored($provider);
        if ('' === $stored['clientId'] || null === $stored['clientSecret']) {
            return null;
        }

        return [
            'clientId' => $stored['clientId'],
            'clientSecret' => $this->secrets->decrypt($stored['clientSecret']),
            'tenant' => $stored['tenant'],
            'redirectUri' => $stored['redirectUri'],
        ];
    }

    public function isConfigured(string $provider): bool
    {
        $stored = $this->stored($provider);

        return '' !== $stored['clientId'] && null !== $stored['clientSecret'];
    }

    /** @return array{provider: string, clientId: string, hasClientSecret: bool, tenant: string, redirectUri: ?string, configured: bool} */
    public function present(string $provider): array
    {
        $stored = $this->stored($provider);

        return [
            'provider' => $provider,
            'clientId' => $stored['clientId'],
            'hasClientSecret' => null !== $stored['clientSecret'],
            'tenant' => $stored['tenant'],
            'redirectUri' => $stored['redirectUri'],
            'configured' => '' !== $stored['clientId'] && null !== $stored['clientSecret'],
        ];
    }

    /** An empty or null secret keeps the current one; an empty client id removes the application. */
    public function save(string $provider, string $clientId, #[\SensitiveParameter] ?string $clientSecret, ?string $tenant, ?string $redirectUri): void
    {
        $clientId = trim($clientId);
        if ('' === $clientId) {
            $this->settings->remove(self::KEY.$provider);
            $this->em->flush();

            return;
        }
        $stored = $this->stored($provider);
        $clientSecret = null === $clientSecret ? '' : trim($clientSecret);
        $this->settings->set(self::KEY.$provider, [
            'clientId' => $clientId,
            'clientSecret' => '' !== $clientSecret ? $this->secrets->encrypt($clientSecret) : $stored['clientSecret'],
            'tenant' => null === $tenant || '' === trim($tenant) ? 'common' : trim($tenant),
            'redirectUri' => null === $redirectUri || '' === trim($redirectUri) ? null : trim($redirectUri),
        ]);
        $this->em->flush();
    }

    /** @return array{clientId: string, clientSecret: ?string, tenant: string, redirectUri: ?string} */
    private function stored(string $provider): array
    {
        $value = $this->settings->get(self::KEY.$provider);
        $value = \is_array($value) ? $value : [];

        return [
            'clientId' => \is_string($value['clientId'] ?? null) ? $value['clientId'] : '',
            'clientSecret' => \is_string($value['clientSecret'] ?? null) ? $value['clientSecret'] : null,
            'tenant' => \is_string($value['tenant'] ?? null) && '' !== $value['tenant'] ? $value['tenant'] : 'common',
            'redirectUri' => \is_string($value['redirectUri'] ?? null) ? $value['redirectUri'] : null,
        ];
    }
}
