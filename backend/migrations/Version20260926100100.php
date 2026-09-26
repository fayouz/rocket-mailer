<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Mailbox\SecretBox as MailboxSecretBox;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Rocket\Core\Security\SecretBox;

/**
 * Before rocket-core, the secrets of the authentication servers (LDAP bind password, OpenID Connect client secrets)
 * were encrypted with the key of the mailboxes (MAILBOX_ENCRYPTION_KEY or APP_SECRET). rocket-core reads them with
 * its own key (SECRETS_ENCRYPTION_KEY or APP_SECRET): they are encrypted again, here, with the same environment.
 * The mailbox passwords keep their key. A secret neither key can read is left as is (to be entered again).
 */
final class Version20260926100100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Secrets of the authentication servers encrypted with the key of rocket-core';
    }

    public function up(Schema $schema): void
    {
        $this->reencrypt($this->mailboxBox(), $this->coreBox());
    }

    public function down(Schema $schema): void
    {
        $this->reencrypt($this->coreBox(), $this->mailboxBox());
    }

    private function reencrypt(MailboxSecretBox|SecretBox $from, MailboxSecretBox|SecretBox $to): void
    {
        foreach ($this->connection->fetchAllAssociative('SELECT id, name, bind_password, client_secret FROM authentication_server') as $server) {
            foreach (['bind_password', 'client_secret'] as $column) {
                $secret = $this->convert($server[$column], $from, $to, \sprintf('%s of "%s"', $column, $server['name']));
                if (null !== $secret) {
                    $this->addSql(\sprintf('UPDATE authentication_server SET %s = :secret WHERE id = :id', $column), ['secret' => $secret, 'id' => $server['id']]);
                }
            }
        }

        // LDAP settings saved before the authentication servers existed.
        $ldap = json_decode((string) $this->connection->fetchOne("SELECT value FROM setting WHERE name = 'ldap'"), true);
        if (\is_array($ldap) && null !== $secret = $this->convert($ldap['bindPassword'] ?? '', $from, $to, 'LDAP bind password (settings)')) {
            $ldap['bindPassword'] = $secret;
            $this->addSql("UPDATE setting SET value = :value WHERE name = 'ldap'", ['value' => json_encode($ldap, \JSON_THROW_ON_ERROR)]);
        }
    }

    /** The secret encrypted with $to; null when there is nothing to do (empty, already converted or unreadable). */
    private function convert(string $encrypted, MailboxSecretBox|SecretBox $from, MailboxSecretBox|SecretBox $to, string $label): ?string
    {
        if ('' === $encrypted || null !== self::tryDecrypt($to, $encrypted)) {
            return null;
        }
        $plain = self::tryDecrypt($from, $encrypted);
        if (null === $plain) {
            $this->write(\sprintf('<comment>Cannot decrypt the %s: enter it again in the administration.</comment>', $label));

            return null;
        }

        return $to->encrypt($plain);
    }

    private static function tryDecrypt(MailboxSecretBox|SecretBox $box, string $encrypted): ?string
    {
        try {
            return $box->decrypt($encrypted);
        } catch (\RuntimeException) {
            return null;
        }
    }

    private function coreBox(): SecretBox
    {
        return new SecretBox(self::env('SECRETS_ENCRYPTION_KEY'), self::env('APP_SECRET'));
    }

    private function mailboxBox(): MailboxSecretBox
    {
        return new MailboxSecretBox(self::env('MAILBOX_ENCRYPTION_KEY'), self::env('APP_SECRET'));
    }

    private static function env(string $name): string
    {
        return (string) ($_SERVER[$name] ?? $_ENV[$name] ?? getenv($name) ?: '');
    }
}
