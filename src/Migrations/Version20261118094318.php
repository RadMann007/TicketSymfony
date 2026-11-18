<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261118094318 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la priorité et l\'échéance aux tickets.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ticket ADD priority VARCHAR(10) DEFAULT \'normal\' NOT NULL, ADD due_date DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ticket DROP priority, DROP due_date');
    }
}
