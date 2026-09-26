<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Color palettes: the project one and one per application';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE color_palette (id UUID NOT NULL, name VARCHAR(80) NOT NULL, primary_color VARCHAR(7) NOT NULL, secondary VARCHAR(7) DEFAULT NULL, success VARCHAR(7) DEFAULT NULL, info VARCHAR(7) DEFAULT NULL, warning VARCHAR(7) DEFAULT NULL, error VARCHAR(7) DEFAULT NULL, neutral VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by VARCHAR(180) DEFAULT NULL, updated_by VARCHAR(180) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE application ADD palette_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE application ADD CONSTRAINT FK_A45BDDC1908BC74 FOREIGN KEY (palette_id) REFERENCES color_palette (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_A45BDDC1908BC74 ON application (palette_id)');
    }

    public function down(Schema $schema): void
    {
        // The foreign key first: the table cannot be dropped while application references it.
        $this->addSql('ALTER TABLE application DROP CONSTRAINT FK_A45BDDC1908BC74');
        $this->addSql('DROP INDEX IDX_A45BDDC1908BC74');
        $this->addSql('ALTER TABLE application DROP palette_id');
        $this->addSql('DROP TABLE color_palette');
    }
}
