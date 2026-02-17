<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260217111837 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE store (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, legal_name VARCHAR(180) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, phone VARCHAR(40) DEFAULT NULL, website VARCHAR(255) DEFAULT NULL, address_line1 VARCHAR(120) DEFAULT NULL, address_line2 VARCHAR(120) DEFAULT NULL, postal_code VARCHAR(20) DEFAULT NULL, city VARCHAR(80) DEFAULT NULL, region VARCHAR(80) DEFAULT NULL, country VARCHAR(80) DEFAULT NULL, ice VARCHAR(80) DEFAULT NULL, vat_number VARCHAR(80) DEFAULT NULL, rc VARCHAR(80) DEFAULT NULL, if_number VARCHAR(80) DEFAULT NULL, currency VARCHAR(10) NOT NULL, locale VARCHAR(20) NOT NULL, logo_path VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE cart ADD CONSTRAINT FK_BA388B7A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE cart_item ADD CONSTRAINT FK_F0FE25271AD5CDBF FOREIGN KEY (cart_id) REFERENCES cart (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE commande ADD CONSTRAINT FK_6EEAA67DA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE commande_ligne ADD CONSTRAINT FK_6E98044082EA2E54 FOREIGN KEY (commande_id) REFERENCES commande (id)');
        $this->addSql('ALTER TABLE commande_pick_line ADD CONSTRAINT FK_5B7CACB882EA2E54 FOREIGN KEY (commande_id) REFERENCES commande (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE commande_pick_line ADD CONSTRAINT FK_5B7CACB88C6396EC FOREIGN KEY (commande_ligne_id) REFERENCES commande_ligne (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE stock_reservation ADD CONSTRAINT FK_9D06EF61A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE stock_reservation ADD CONSTRAINT FK_9D06EF6182EA2E54 FOREIGN KEY (commande_id) REFERENCES commande (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE store');
        $this->addSql('ALTER TABLE cart DROP FOREIGN KEY FK_BA388B7A76ED395');
        $this->addSql('ALTER TABLE cart_item DROP FOREIGN KEY FK_F0FE25271AD5CDBF');
        $this->addSql('ALTER TABLE commande DROP FOREIGN KEY FK_6EEAA67DA76ED395');
        $this->addSql('ALTER TABLE commande_ligne DROP FOREIGN KEY FK_6E98044082EA2E54');
        $this->addSql('ALTER TABLE commande_pick_line DROP FOREIGN KEY FK_5B7CACB882EA2E54');
        $this->addSql('ALTER TABLE commande_pick_line DROP FOREIGN KEY FK_5B7CACB88C6396EC');
        $this->addSql('ALTER TABLE stock_reservation DROP FOREIGN KEY FK_9D06EF61A76ED395');
        $this->addSql('ALTER TABLE stock_reservation DROP FOREIGN KEY FK_9D06EF6182EA2E54');
    }
}
