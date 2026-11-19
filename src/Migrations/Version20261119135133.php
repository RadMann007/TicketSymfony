<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261119135133 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE attachment (id INT AUTO_INCREMENT NOT NULL, stored_name VARCHAR(255) NOT NULL, original_name VARCHAR(255) NOT NULL, mime_type VARCHAR(120) NOT NULL, size INT NOT NULL, created_at DATETIME NOT NULL, ticket_id INT NOT NULL, uploaded_by_id INT DEFAULT NULL, INDEX IDX_795FD9BB700047D2 (ticket_id), INDEX IDX_795FD9BBA2B28FE8 (uploaded_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE attachment ADD CONSTRAINT FK_795FD9BB700047D2 FOREIGN KEY (ticket_id) REFERENCES ticket (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE attachment ADD CONSTRAINT FK_795FD9BBA2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE attachment DROP FOREIGN KEY FK_795FD9BB700047D2');
        $this->addSql('ALTER TABLE attachment DROP FOREIGN KEY FK_795FD9BBA2B28FE8');
        $this->addSql('DROP TABLE attachment');
    }
}
