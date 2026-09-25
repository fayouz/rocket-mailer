<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Link users to their authentication server';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD authentication_server_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD CONSTRAINT FK_8F1A7E8F4C1E9A2 FOREIGN KEY (authentication_server_id) REFERENCES authentication_server (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_8F1A7E8F4C1E9A2 ON "user" (authentication_server_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP CONSTRAINT FK_8F1A7E8F4C1E9A2');
        $this->addSql('DROP INDEX IDX_8F1A7E8F4C1E9A2');
        $this->addSql('ALTER TABLE "user" DROP authentication_server_id');
    }
}