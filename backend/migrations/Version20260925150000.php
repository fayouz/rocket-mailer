<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Authentication servers';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE authentication_server (id UUID NOT NULL, name VARCHAR(120) NOT NULL, type VARCHAR(16) NOT NULL, enabled BOOLEAN NOT NULL, url VARCHAR(255) NOT NULL, start_tls BOOLEAN NOT NULL, base_dn VARCHAR(512) NOT NULL, bind_dn VARCHAR(512) NOT NULL, bind_password TEXT NOT NULL, user_filter TEXT NOT NULL, admin_group_dn VARCHAR(512) NOT NULL, attributes JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by VARCHAR(180) DEFAULT NULL, updated_by VARCHAR(180) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_AUTHENTICATION_SERVER_TYPE ON authentication_server (type)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE authentication_server');
    }
}
