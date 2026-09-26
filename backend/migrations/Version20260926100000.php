<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The applications come from rocket-core: their sender settings move to a table of Rocket Mailer, one row per
 * application that had any (the others get theirs when an administrator saves them).
 */
final class Version20260926100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sender settings of the applications in their own table (application_sender)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE application_sender (sender_email VARCHAR(180) DEFAULT NULL, sender_name VARCHAR(120) DEFAULT NULL, allowed_senders JSON DEFAULT \'[]\' NOT NULL, application_id UUID NOT NULL, PRIMARY KEY (application_id))');
        $this->addSql('ALTER TABLE application_sender ADD CONSTRAINT FK_EF33460A3E030ACD FOREIGN KEY (application_id) REFERENCES application (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('INSERT INTO application_sender (application_id, sender_email, sender_name, allowed_senders)
            SELECT id, sender_email, sender_name, allowed_senders FROM application
            WHERE sender_email IS NOT NULL OR sender_name IS NOT NULL OR allowed_senders::text <> \'[]\'');
        $this->addSql('ALTER TABLE application DROP allowed_senders');
        $this->addSql('ALTER TABLE application DROP sender_email');
        $this->addSql('ALTER TABLE application DROP sender_name');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE application ADD allowed_senders JSON DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE application ADD sender_email VARCHAR(180) DEFAULT NULL');
        $this->addSql('ALTER TABLE application ADD sender_name VARCHAR(120) DEFAULT NULL');
        $this->addSql('UPDATE application a SET sender_email = s.sender_email, sender_name = s.sender_name, allowed_senders = s.allowed_senders
            FROM application_sender s WHERE s.application_id = a.id');
        $this->addSql('DROP TABLE application_sender');
    }
}
