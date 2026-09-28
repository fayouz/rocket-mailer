<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Shared inboxes: members of a mailbox, received messages grouped in conversations (notes, read markers),
 * replies threaded by Message-ID.
 */
final class Version20260928103829 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Shared inboxes: mailbox members, conversations, received messages and attachments, notes, read markers';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE conversation (id UUID NOT NULL, subject VARCHAR(255) NOT NULL, normalized_subject VARCHAR(255) NOT NULL, participants JSON NOT NULL, status VARCHAR(8) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_message_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_activity_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, message_count INT NOT NULL, snippet VARCHAR(255) NOT NULL, last_from VARCHAR(255) NOT NULL, mailbox_id UUID NOT NULL, assignee_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_8A8E26E966EC35CC6F60E3AF ON conversation (mailbox_id, last_message_at)');
        $this->addSql('CREATE INDEX IDX_8A8E26E966EC35CC3A649E3C ON conversation (mailbox_id, normalized_subject)');
        $this->addSql('CREATE INDEX IDX_8A8E26E966EC35CC ON conversation (mailbox_id)');
        $this->addSql('CREATE INDEX IDX_8A8E26E959EC7D60 ON conversation (assignee_id)');
        $this->addSql('CREATE TABLE conversation_note (id UUID NOT NULL, body TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, conversation_id UUID NOT NULL, author_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_18C299089AC03968B8E8428 ON conversation_note (conversation_id, created_at)');
        $this->addSql('CREATE INDEX IDX_18C299089AC0396 ON conversation_note (conversation_id)');
        $this->addSql('CREATE INDEX IDX_18C29908F675F31B ON conversation_note (author_id)');
        $this->addSql('CREATE TABLE conversation_read (id UUID NOT NULL, read_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, conversation_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4F28227B9AC0396A76ED395 ON conversation_read (conversation_id, user_id)');
        $this->addSql('CREATE INDEX IDX_4F28227B9AC0396 ON conversation_read (conversation_id)');
        $this->addSql('CREATE INDEX IDX_4F28227BA76ED395 ON conversation_read (user_id)');
        $this->addSql('CREATE TABLE inbound_attachment (id UUID NOT NULL, filename VARCHAR(255) NOT NULL, mime_type VARCHAR(127) NOT NULL, size INT NOT NULL, storage_name VARCHAR(64) NOT NULL, message_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_225B5A6C570EB513 ON inbound_attachment (storage_name)');
        $this->addSql('CREATE INDEX IDX_225B5A6C537A1329 ON inbound_attachment (message_id)');
        $this->addSql('CREATE TABLE inbound_message (id UUID NOT NULL, imap_uid BIGINT DEFAULT NULL, message_id VARCHAR(255) DEFAULT NULL, in_reply_to VARCHAR(255) DEFAULT NULL, reference_ids JSON NOT NULL, from_address VARCHAR(255) NOT NULL, from_name VARCHAR(255) DEFAULT NULL, reply_to VARCHAR(255) DEFAULT NULL, recipients_to JSON NOT NULL, recipients_cc JSON NOT NULL, subject VARCHAR(255) NOT NULL, date TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, text TEXT NOT NULL, html TEXT DEFAULT NULL, has_remote_images BOOLEAN NOT NULL, size INT NOT NULL, received_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, mailbox_id UUID NOT NULL, conversation_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_76B3031F66EC35CC537A1329 ON inbound_message (mailbox_id, message_id)');
        $this->addSql('CREATE INDEX IDX_76B3031F9AC0396AA9E377A ON inbound_message (conversation_id, date)');
        $this->addSql('CREATE INDEX IDX_76B3031F66EC35CC ON inbound_message (mailbox_id)');
        $this->addSql('CREATE INDEX IDX_76B3031F9AC0396 ON inbound_message (conversation_id)');
        $this->addSql('CREATE TABLE mailbox_member (id UUID NOT NULL, role VARCHAR(16) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by VARCHAR(180) DEFAULT NULL, updated_by VARCHAR(180) DEFAULT NULL, mailbox_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_EB0A059066EC35CCA76ED395 ON mailbox_member (mailbox_id, user_id)');
        $this->addSql('CREATE INDEX IDX_EB0A059066EC35CC ON mailbox_member (mailbox_id)');
        $this->addSql('CREATE INDEX IDX_EB0A0590A76ED395 ON mailbox_member (user_id)');
        $this->addSql('ALTER TABLE conversation ADD CONSTRAINT FK_8A8E26E966EC35CC FOREIGN KEY (mailbox_id) REFERENCES mailbox (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE conversation ADD CONSTRAINT FK_8A8E26E959EC7D60 FOREIGN KEY (assignee_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE conversation_note ADD CONSTRAINT FK_18C299089AC0396 FOREIGN KEY (conversation_id) REFERENCES conversation (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE conversation_note ADD CONSTRAINT FK_18C29908F675F31B FOREIGN KEY (author_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE conversation_read ADD CONSTRAINT FK_4F28227B9AC0396 FOREIGN KEY (conversation_id) REFERENCES conversation (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE conversation_read ADD CONSTRAINT FK_4F28227BA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE inbound_attachment ADD CONSTRAINT FK_225B5A6C537A1329 FOREIGN KEY (message_id) REFERENCES inbound_message (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE inbound_message ADD CONSTRAINT FK_76B3031F66EC35CC FOREIGN KEY (mailbox_id) REFERENCES mailbox (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE inbound_message ADD CONSTRAINT FK_76B3031F9AC0396 FOREIGN KEY (conversation_id) REFERENCES conversation (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE mailbox_member ADD CONSTRAINT FK_EB0A059066EC35CC FOREIGN KEY (mailbox_id) REFERENCES mailbox (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE mailbox_member ADD CONSTRAINT FK_EB0A0590A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE email ADD message_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE email ADD in_reply_to VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE email ADD reference_ids JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE email ADD conversation_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE email ADD CONSTRAINT FK_E7927C749AC0396 FOREIGN KEY (conversation_id) REFERENCES conversation (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_E7927C74537A1329 ON email (message_id)');
        $this->addSql('CREATE INDEX IDX_E7927C749AC0396 ON email (conversation_id)');
        $this->addSql('ALTER TABLE mailbox ADD inbox_enabled BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE mailbox ADD inbox_uid_validity BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE mailbox ADD inbox_last_uid BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE mailbox ADD inbox_fetched_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE mailbox ADD inbox_error TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE conversation DROP CONSTRAINT FK_8A8E26E966EC35CC');
        $this->addSql('ALTER TABLE conversation DROP CONSTRAINT FK_8A8E26E959EC7D60');
        $this->addSql('ALTER TABLE conversation_note DROP CONSTRAINT FK_18C299089AC0396');
        $this->addSql('ALTER TABLE conversation_note DROP CONSTRAINT FK_18C29908F675F31B');
        $this->addSql('ALTER TABLE conversation_read DROP CONSTRAINT FK_4F28227B9AC0396');
        $this->addSql('ALTER TABLE conversation_read DROP CONSTRAINT FK_4F28227BA76ED395');
        $this->addSql('ALTER TABLE inbound_attachment DROP CONSTRAINT FK_225B5A6C537A1329');
        $this->addSql('ALTER TABLE inbound_message DROP CONSTRAINT FK_76B3031F66EC35CC');
        $this->addSql('ALTER TABLE inbound_message DROP CONSTRAINT FK_76B3031F9AC0396');
        $this->addSql('ALTER TABLE mailbox_member DROP CONSTRAINT FK_EB0A059066EC35CC');
        $this->addSql('ALTER TABLE mailbox_member DROP CONSTRAINT FK_EB0A0590A76ED395');
        $this->addSql('DROP TABLE conversation');
        $this->addSql('DROP TABLE conversation_note');
        $this->addSql('DROP TABLE conversation_read');
        $this->addSql('DROP TABLE inbound_attachment');
        $this->addSql('DROP TABLE inbound_message');
        $this->addSql('DROP TABLE mailbox_member');
        $this->addSql('ALTER TABLE email DROP CONSTRAINT FK_E7927C749AC0396');
        $this->addSql('DROP INDEX IDX_E7927C74537A1329');
        $this->addSql('DROP INDEX IDX_E7927C749AC0396');
        $this->addSql('ALTER TABLE email DROP message_id');
        $this->addSql('ALTER TABLE email DROP in_reply_to');
        $this->addSql('ALTER TABLE email DROP reference_ids');
        $this->addSql('ALTER TABLE email DROP conversation_id');
        $this->addSql('ALTER TABLE mailbox DROP inbox_enabled');
        $this->addSql('ALTER TABLE mailbox DROP inbox_uid_validity');
        $this->addSql('ALTER TABLE mailbox DROP inbox_last_uid');
        $this->addSql('ALTER TABLE mailbox DROP inbox_fetched_at');
        $this->addSql('ALTER TABLE mailbox DROP inbox_error');
    }
}
