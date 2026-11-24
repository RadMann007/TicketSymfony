<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261119134613 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée la table ticket_event pour l\'historique des tickets.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE ticket_event (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(40) NOT NULL, field VARCHAR(40) DEFAULT NULL, old_value LONGTEXT DEFAULT NULL, new_value LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, ticket_id INT NOT NULL, actor_id INT DEFAULT NULL, INDEX IDX_139E692C700047D2 (ticket_id), INDEX IDX_139E692C10DAF24A (actor_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE ticket_event ADD CONSTRAINT FK_139E692C700047D2 FOREIGN KEY (ticket_id) REFERENCES ticket (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ticket_event ADD CONSTRAINT FK_139E692C10DAF24A FOREIGN KEY (actor_id) REFERENCES user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ticket_event DROP FOREIGN KEY FK_139E692C700047D2');
        $this->addSql('ALTER TABLE ticket_event DROP FOREIGN KEY FK_139E692C10DAF24A');
        $this->addSql('DROP TABLE ticket_event');
    }
}
