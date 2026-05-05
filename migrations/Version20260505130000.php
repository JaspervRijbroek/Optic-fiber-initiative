<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260505130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename cru column to cadastral_reference on registrations table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE registrations RENAME COLUMN cru TO cadastral_reference');
        $this->addSql('DROP INDEX idx_registrations_cru');
        $this->addSql('CREATE UNIQUE INDEX idx_registrations_cadastral_reference ON registrations (cadastral_reference)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_registrations_cadastral_reference');
        $this->addSql('ALTER TABLE registrations RENAME COLUMN cadastral_reference TO cru');
        $this->addSql('CREATE UNIQUE INDEX idx_registrations_cru ON registrations (cru)');
    }
}
