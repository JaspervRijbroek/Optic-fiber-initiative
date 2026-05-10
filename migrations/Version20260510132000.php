<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260510132000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable GPS coordinates to registrations table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE registrations ADD gps_latitude DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE registrations ADD gps_longitude DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE registrations DROP gps_latitude');
        $this->addSql('ALTER TABLE registrations DROP gps_longitude');
    }
}
