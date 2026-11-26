<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute la date de complétion des tickets et alimente l'existant depuis l'historique.
 */
final class Version20261125141408 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la date de complétion des tickets.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ticket ADD completed_at DATETIME DEFAULT NULL');

        // Reprend la dernière clôture enregistrée dans l'historique ; à défaut,
        // la date de création évite une date nulle sur les tickets terminés.
        $this->addSql(<<<'SQL'
            UPDATE ticket t
            SET completed_at = COALESCE(
                (
                    SELECT MAX(e.created_at)
                    FROM ticket_event e
                    WHERE e.ticket_id = t.id
                      AND e.type = 'status_changed'
                      AND e.new_value = 'Terminé'
                ),
                t.created_at
            )
            WHERE t.is_done = 1 AND t.completed_at IS NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ticket DROP completed_at');
    }
}
