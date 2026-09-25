<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260925112119 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sending mailboxes (SMTP or provider, IMAP copy), attached to applications';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE mailbox (id UUID NOT NULL, name VARCHAR(120) NOT NULL, email VARCHAR(180) NOT NULL, display_name VARCHAR(120) DEFAULT NULL, transport VARCHAR(8) NOT NULL, smtp_host VARCHAR(255) DEFAULT NULL, smtp_port INT DEFAULT NULL, smtp_encryption VARCHAR(8) NOT NULL, smtp_username VARCHAR(255) DEFAULT NULL, smtp_password TEXT DEFAULT NULL, dsn TEXT DEFAULT NULL, dsn_hint VARCHAR(255) DEFAULT NULL, imap_enabled BOOLEAN NOT NULL, imap_host VARCHAR(255) DEFAULT NULL, imap_port INT DEFAULT NULL, imap_encryption VARCHAR(8) NOT NULL, imap_username VARCHAR(255) DEFAULT NULL, imap_password TEXT DEFAULT NULL, imap_sent_folder VARCHAR(255) DEFAULT NULL, available_to_users BOOLEAN NOT NULL, enabled BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by VARCHAR(180) DEFAULT NULL, updated_by VARCHAR(180) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE mailbox_application (mailbox_id UUID NOT NULL, application_id UUID NOT NULL, PRIMARY KEY (mailbox_id, application_id))');
        $this->addSql('CREATE INDEX IDX_5333F95566EC35CC ON mailbox_application (mailbox_id)');
        $this->addSql('CREATE INDEX IDX_5333F9553E030ACD ON mailbox_application (application_id)');
        $this->addSql('ALTER TABLE mailbox_application ADD CONSTRAINT FK_5333F95566EC35CC FOREIGN KEY (mailbox_id) REFERENCES mailbox (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mailbox_application ADD CONSTRAINT FK_5333F9553E030ACD FOREIGN KEY (application_id) REFERENCES application (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE email ADD mailbox_name VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE email ADD archived_in VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE email ADD archive_error TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE email ADD mailbox_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE email ADD CONSTRAINT FK_E7927C7466EC35CC FOREIGN KEY (mailbox_id) REFERENCES mailbox (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_E7927C7466EC35CC ON email (mailbox_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mailbox_application DROP CONSTRAINT FK_5333F95566EC35CC');
        $this->addSql('ALTER TABLE mailbox_application DROP CONSTRAINT FK_5333F9553E030ACD');
        $this->addSql('DROP TABLE mailbox');
        $this->addSql('DROP TABLE mailbox_application');
        $this->addSql('ALTER TABLE email DROP CONSTRAINT FK_E7927C7466EC35CC');
        $this->addSql('DROP INDEX IDX_E7927C7466EC35CC');
        $this->addSql('ALTER TABLE email DROP mailbox_name');
        $this->addSql('ALTER TABLE email DROP archived_in');
        $this->addSql('ALTER TABLE email DROP archive_error');
        $this->addSql('ALTER TABLE email DROP mailbox_id');
    }
}
