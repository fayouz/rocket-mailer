<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Personal mailboxes (kind, owner) and OAuth authentication (Google, Microsoft: encrypted tokens), detected provider.
 */
final class Version20260929090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Personal mailboxes, OAuth mailboxes, detected provider';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE mailbox ADD kind VARCHAR(16) DEFAULT 'shared' NOT NULL");
        $this->addSql('ALTER TABLE mailbox ADD owner_id UUID DEFAULT NULL');
        $this->addSql("ALTER TABLE mailbox ADD auth_type VARCHAR(16) DEFAULT 'password' NOT NULL");
        $this->addSql('ALTER TABLE mailbox ADD provider VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE mailbox ADD oauth_refresh_token TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE mailbox ADD oauth_access_token TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE mailbox ADD oauth_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE mailbox ADD CONSTRAINT FK_A69FE20B7E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_A69FE20B7E3C61F9 ON mailbox (owner_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mailbox DROP CONSTRAINT FK_A69FE20B7E3C61F9');
        $this->addSql('DROP INDEX IDX_A69FE20B7E3C61F9');
        $this->addSql('ALTER TABLE mailbox DROP kind, DROP owner_id, DROP auth_type, DROP provider, DROP oauth_refresh_token, DROP oauth_access_token, DROP oauth_expires_at');
    }
}
