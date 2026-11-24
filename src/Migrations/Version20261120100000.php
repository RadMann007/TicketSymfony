<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Normalise les valeurs des événements déjà enregistrés en libellés lisibles.
 */
final class Version20261120100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Convertit les anciennes valeurs brutes de ticket_event en libellés.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE ticket_event SET old_value = CASE old_value WHEN 'low' THEN 'Basse' WHEN 'normal' THEN 'Normale' WHEN 'high' THEN 'Haute' ELSE old_value END, new_value = CASE new_value WHEN 'low' THEN 'Basse' WHEN 'normal' THEN 'Normale' WHEN 'high' THEN 'Haute' ELSE new_value END WHERE type = 'priority_changed'");

        $this->addSql("UPDATE ticket_event SET old_value = CASE old_value WHEN '0' THEN 'En cours' WHEN '1' THEN 'Terminé' ELSE old_value END, new_value = CASE new_value WHEN '0' THEN 'En cours' WHEN '1' THEN 'Terminé' ELSE new_value END WHERE type = 'status_changed'");

        $this->addSql("UPDATE ticket_event SET old_value = 'Aucune' WHERE type = 'due_date_changed' AND old_value IS NULL");
        $this->addSql("UPDATE ticket_event SET new_value = 'Aucune' WHERE type = 'due_date_changed' AND new_value IS NULL");
        $this->addSql("UPDATE ticket_event SET old_value = DATE_FORMAT(old_value, '%d/%m/%Y') WHERE type = 'due_date_changed' AND old_value REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'");
        $this->addSql("UPDATE ticket_event SET new_value = DATE_FORMAT(new_value, '%d/%m/%Y') WHERE type = 'due_date_changed' AND new_value REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'");

        $this->addSql("UPDATE ticket_event e LEFT JOIN `user` u ON u.id = e.old_value SET e.old_value = COALESCE(u.email, 'Non assigné') WHERE e.type = 'assigned' AND (e.old_value IS NULL OR e.old_value = '' OR e.old_value REGEXP '^[0-9]+$')");
        $this->addSql("UPDATE ticket_event e LEFT JOIN `user` u ON u.id = e.new_value SET e.new_value = COALESCE(u.email, 'Non assigné') WHERE e.type = 'assigned' AND (e.new_value IS NULL OR e.new_value = '' OR e.new_value REGEXP '^[0-9]+$')");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException("Les libellés ne permettent pas de restaurer les valeurs brutes d'origine.");
    }
}
