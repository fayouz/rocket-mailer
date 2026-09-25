<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925134035 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Health checks of the LDAP server and of the sending mailboxes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE service_check (id VARCHAR(120) NOT NULL, status VARCHAR(20) NOT NULL, detail TEXT NOT NULL, latency_ms DOUBLE PRECISION DEFAULT NULL, checked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_ok_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, failing_since TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE service_check');
    }
}
