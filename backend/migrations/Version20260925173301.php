<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260925173301 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'OpenID Connect authentication servers, and the external identifier of their users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_authentication_server_type');
        $this->addSql('ALTER TABLE authentication_server ADD internal_url VARCHAR(255) DEFAULT \'\' NOT NULL');
        $this->addSql('ALTER TABLE authentication_server ADD client_id VARCHAR(255) DEFAULT \'\' NOT NULL');
        $this->addSql('ALTER TABLE authentication_server ADD client_secret TEXT DEFAULT \'\' NOT NULL');
        $this->addSql('ALTER TABLE authentication_server ADD scopes VARCHAR(255) DEFAULT \'openid email profile\' NOT NULL');
        $this->addSql('ALTER TABLE authentication_server ADD link_existing_accounts BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD external_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_external_id ON "user" (authentication_server_id, external_id)');
        // Already renamed on the databases of main (Version20260925214733).
        if ($schema->getTable('user')->hasIndex('idx_8f1a7e8f4c1e9a2')) {
            $this->addSql('ALTER INDEX idx_8f1a7e8f4c1e9a2 RENAME TO IDX_8D93D649C2F36C49');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE authentication_server DROP internal_url');
        $this->addSql('ALTER TABLE authentication_server DROP client_id');
        $this->addSql('ALTER TABLE authentication_server DROP client_secret');
        $this->addSql('ALTER TABLE authentication_server DROP scopes');
        $this->addSql('ALTER TABLE authentication_server DROP link_existing_accounts');
        $this->addSql('CREATE INDEX idx_authentication_server_type ON authentication_server (type)');
        $this->addSql('DROP INDEX uniq_user_external_id');
        $this->addSql('ALTER TABLE "user" DROP external_id');
        $this->addSql('ALTER INDEX idx_8d93d649c2f36c49 RENAME TO idx_8f1a7e8f4c1e9a2');
    }
}
