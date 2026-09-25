<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925132649 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Update runs (Administration → Mises à jour)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE update_run (id UUID NOT NULL, method VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, from_version VARCHAR(80) NOT NULL, target VARCHAR(80) DEFAULT NULL, log TEXT NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by VARCHAR(180) DEFAULT NULL, updated_by VARCHAR(180) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_8711D9A37B00651C ON update_run (status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE update_run');
    }
}
