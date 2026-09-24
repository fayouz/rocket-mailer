<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260924084842 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE attachment (id UUID NOT NULL, filename VARCHAR(255) NOT NULL, mime_type VARCHAR(127) NOT NULL, size INT NOT NULL, storage_name VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by VARCHAR(180) DEFAULT NULL, updated_by VARCHAR(180) DEFAULT NULL, owner_id UUID NOT NULL, email_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_795FD9BB570EB513 ON attachment (storage_name)');
        $this->addSql('CREATE INDEX IDX_795FD9BB8B8E8428 ON attachment (created_at)');
        $this->addSql('CREATE INDEX IDX_795FD9BB7E3C61F9 ON attachment (owner_id)');
        $this->addSql('CREATE INDEX IDX_795FD9BBA832C1C9 ON attachment (email_id)');
        $this->addSql('ALTER TABLE attachment ADD CONSTRAINT FK_795FD9BB7E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE attachment ADD CONSTRAINT FK_795FD9BBA832C1C9 FOREIGN KEY (email_id) REFERENCES email (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE attachment DROP CONSTRAINT FK_795FD9BB7E3C61F9');
        $this->addSql('ALTER TABLE attachment DROP CONSTRAINT FK_795FD9BBA832C1C9');
        $this->addSql('DROP TABLE attachment');
    }
}
