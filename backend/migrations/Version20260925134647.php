<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925134647 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Own sender of each application (never the platform addresses)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE application ADD sender_email VARCHAR(180) DEFAULT NULL');
        $this->addSql('ALTER TABLE application ADD sender_name VARCHAR(120) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE application DROP sender_email');
        $this->addSql('ALTER TABLE application DROP sender_name');
    }
}
