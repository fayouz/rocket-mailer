<?php

namespace App\Mailbox;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Encrypts mailbox credentials at rest (libsodium secretbox). The key derives from MAILBOX_ENCRYPTION_KEY,
 * or APP_SECRET when it is empty: changing it makes the stored passwords unreadable (to be entered again).
 */
final class SecretBox
{
    private const PREFIX = 'v1:';

    private readonly string $key;

    public function __construct(
        #[Autowire(env: 'MAILBOX_ENCRYPTION_KEY')] #[\SensitiveParameter] string $encryptionKey,
        #[Autowire(env: 'APP_SECRET')] #[\SensitiveParameter] string $appSecret,
    ) {
        $material = '' !== $encryptionKey ? $encryptionKey : $appSecret;
        $this->key = sodium_crypto_generichash('rocket-mailer/mailbox-secrets|'.$material, '', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function encrypt(#[\SensitiveParameter] string $plain): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX.base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    public function decrypt(string $encrypted): string
    {
        $raw = str_starts_with($encrypted, self::PREFIX) ? base64_decode(substr($encrypted, \strlen(self::PREFIX)), true) : false;
        $plain = false === $raw || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ? false : sodium_crypto_secretbox_open(
            substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key,
        );
        if (false === $plain) {
            throw new \RuntimeException('A mailbox secret cannot be decrypted: MAILBOX_ENCRYPTION_KEY (or APP_SECRET) changed. Enter the password again.');
        }

        return $plain;
    }
}
