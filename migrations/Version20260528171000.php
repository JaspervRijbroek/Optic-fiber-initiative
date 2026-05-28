<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260528171000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop GPS coordinates from registrations table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE registrations DROP gps_latitude');
        $this->addSql('ALTER TABLE registrations DROP gps_longitude');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE registrations ADD gps_latitude DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE registrations ADD gps_longitude DOUBLE PRECISION DEFAULT NULL');
    }
}
