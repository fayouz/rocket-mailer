<?php

namespace App\Mailbox\OAuth;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Authorization code flow with PKCE (RFC 7636) against Google or Microsoft, and refresh of access tokens.
 */
final class OAuthClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly OAuthSettings $settings,
    ) {
    }

    public function provider(string $id): OAuthProvider
    {
        return OAuthProvider::get($id, $this->credentials($id)['tenant']);
    }

    /** URL of the consent page of the provider. */
    public function authorizationUrl(string $providerId, string $state, string $codeVerifier, string $redirectUri, ?string $loginHint): string
    {
        $provider = $this->provider($providerId);
        $params = [
            'client_id' => $this->credentials($providerId)['clientId'],
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'scope' => $provider->scope,
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ] + $provider->authorizeParams;
        if (null !== $loginHint && '' !== $loginHint) {
            $params['login_hint'] = $loginHint;
        }

        return $provider->authorizeUrl.'?'.http_build_query($params, '', '&', \PHP_QUERY_RFC3986);
    }

    /**
     * @return array{accessToken: string, refreshToken: ?string, expiresIn: int}
     */
    public function exchangeCode(string $providerId, string $code, string $codeVerifier, string $redirectUri): array
    {
        return $this->token($providerId, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
        ]);
    }

    /**
     * @return array{accessToken: string, refreshToken: ?string, expiresIn: int}
     */
    public function refresh(string $providerId, #[\SensitiveParameter] string $refreshToken): array
    {
        $params = ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken];
        if (OAuthProvider::MICROSOFT === $providerId) {
            $params['scope'] = OAuthProvider::MICROSOFT_SCOPE;
        }

        return $this->token($providerId, $params);
    }

    /** @return array{clientId: string, clientSecret: string, tenant: string, redirectUri: ?string} */
    public function credentials(string $providerId): array
    {
        return $this->settings->credentials($providerId) ?? throw new OAuthException(\sprintf(
            'L’application OAuth %s n’est pas configurée : un administrateur doit renseigner son identifiant client et son secret (Administration > Réglages).',
            OAuthProvider::get($providerId)->label,
        ));
    }

    /**
     * @param array<string, string> $params
     *
     * @return array{accessToken: string, refreshToken: ?string, expiresIn: int}
     */
    private function token(string $providerId, array $params): array
    {
        $provider = $this->provider($providerId);
        $credentials = $this->credentials($providerId);
        try {
            $response = $this->httpClient->request('POST', $provider->tokenUrl, [
                'headers' => ['Accept' => 'application/json'],
                'body' => $params + ['client_id' => $credentials['clientId'], 'client_secret' => $credentials['clientSecret']],
                'timeout' => 20,
            ]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (ExceptionInterface|\JsonException $e) {
            throw new OAuthException(\sprintf('%s ne répond pas : %s', $provider->label, $e->getMessage()), 0, $e);
        }
        if ($status >= 400 || !\is_string($data['access_token'] ?? null)) {
            $error = \is_string($data['error'] ?? null) ? $data['error'] : 'HTTP '.$status;
            $description = \is_string($data['error_description'] ?? null) ? ' ('.$data['error_description'].')' : '';

            throw new OAuthException('invalid_grant' === $error
                ? \sprintf('L’autorisation %s a expiré ou a été révoquée : reconnectez la boîte.', $provider->label)
                : \sprintf('%s a refusé le jeton : %s%s', $provider->label, $error, $description));
        }

        return [
            'accessToken' => $data['access_token'],
            'refreshToken' => \is_string($data['refresh_token'] ?? null) ? $data['refresh_token'] : null,
            'expiresIn' => is_numeric($data['expires_in'] ?? null) ? (int) $data['expires_in'] : 3600,
        ];
    }
}
