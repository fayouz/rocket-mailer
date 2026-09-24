<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260924101312 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE sender_address (id UUID NOT NULL, email VARCHAR(180) NOT NULL, name VARCHAR(120) DEFAULT NULL, is_default BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by VARCHAR(180) DEFAULT NULL, updated_by VARCHAR(180) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_80B8A94AE7927C74 ON sender_address (email)');
        $this->addSql('CREATE TABLE setting (name VARCHAR(100) NOT NULL, value JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by VARCHAR(180) DEFAULT NULL, updated_by VARCHAR(180) DEFAULT NULL, PRIMARY KEY (name))');
        $this->addSql('ALTER TABLE application ADD allowed_senders JSON DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE email ADD from_address VARCHAR(180) DEFAULT NULL');
        $this->addSql('ALTER TABLE email ADD from_name VARCHAR(180) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE sender_address');
        $this->addSql('DROP TABLE setting');
        $this->addSql('ALTER TABLE application DROP allowed_senders');
        $this->addSql('ALTER TABLE email DROP from_address');
        $this->addSql('ALTER TABLE email DROP from_name');
    }
}
