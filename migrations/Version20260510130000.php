<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260510130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional GPS coordinates to registrations table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE registrations ADD COLUMN coordinate_x DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE registrations ADD COLUMN coordinate_y DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE registrations DROP COLUMN coordinate_x');
        $this->addSql('ALTER TABLE registrations DROP COLUMN coordinate_y');
    }
}

