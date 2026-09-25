<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260925114326 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'HTML layouts around templates';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE email_layout (id UUID NOT NULL, name VARCHAR(120) NOT NULL, description TEXT DEFAULT NULL, html TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by VARCHAR(180) DEFAULT NULL, updated_by VARCHAR(180) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE email_template ADD layout_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE email_template ADD CONSTRAINT FK_9C0600CA8C22AA1A FOREIGN KEY (layout_id) REFERENCES email_layout (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_9C0600CA8C22AA1A ON email_template (layout_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE email_layout');
        $this->addSql('ALTER TABLE email_template DROP CONSTRAINT FK_9C0600CA8C22AA1A');
        $this->addSql('DROP INDEX IDX_9C0600CA8C22AA1A');
        $this->addSql('ALTER TABLE email_template DROP layout_id');
    }
}
