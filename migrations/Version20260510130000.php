<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260510130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow optional cadastral reference and add GPS latitude/longitude to registrations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE registrations_tmp (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, nombre VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, cadastral_reference VARCHAR(255) DEFAULT NULL, latitude DOUBLE PRECISION DEFAULT NULL, longitude DOUBLE PRECISION DEFAULT NULL, unsubscribe_token VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('INSERT INTO registrations_tmp (id, nombre, email, cadastral_reference, unsubscribe_token, created_at) SELECT id, nombre, email, cadastral_reference, unsubscribe_token, created_at FROM registrations');
        $this->addSql('DROP TABLE registrations');
        $this->addSql('ALTER TABLE registrations_tmp RENAME TO registrations');
        $this->addSql('CREATE UNIQUE INDEX idx_registrations_cadastral_reference ON registrations (cadastral_reference)');
        $this->addSql('CREATE UNIQUE INDEX idx_registrations_token ON registrations (unsubscribe_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE registrations_tmp (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, nombre VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, cadastral_reference VARCHAR(255) NOT NULL, unsubscribe_token VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL)');
        $this->addSql("INSERT INTO registrations_tmp (id, nombre, email, cadastral_reference, unsubscribe_token, created_at) SELECT id, nombre, email, COALESCE(cadastral_reference, '__ROLLBACK_GPS_' || CAST(id AS TEXT) || '__'), unsubscribe_token, created_at FROM registrations");
        $this->addSql('DROP TABLE registrations');
        $this->addSql('ALTER TABLE registrations_tmp RENAME TO registrations');
        $this->addSql('CREATE UNIQUE INDEX idx_registrations_cadastral_reference ON registrations (cadastral_reference)');
        $this->addSql('CREATE UNIQUE INDEX idx_registrations_token ON registrations (unsubscribe_token)');
    }
}
