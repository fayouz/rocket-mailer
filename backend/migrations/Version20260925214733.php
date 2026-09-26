<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925214733 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Authentication servers: index names matching the mapping';
    }

    public function up(Schema $schema): void
    {
        // Already renamed by Version20260925173301 (branch rocket-core): recorded as executed, never skipped.
        if (!$schema->hasTable('user') || !$schema->getTable('user')->hasIndex('idx_8f1a7e8f4c1e9a2')) {
            $this->write('The index already matches the mapping.');

            return;
        }
        $this->addSql('ALTER INDEX idx_8f1a7e8f4c1e9a2 RENAME TO IDX_8D93D649C2F36C49');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER INDEX idx_8d93d649c2f36c49 RENAME TO idx_8f1a7e8f4c1e9a2');
    }
}
