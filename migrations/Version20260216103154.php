<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260216103154 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE commande_pick_line (id INT AUTO_INCREMENT NOT NULL, expected_qty INT NOT NULL, picked_qty INT NOT NULL, done TINYINT NOT NULL, updated_at DATETIME NOT NULL, commande_id INT NOT NULL, commande_ligne_id INT NOT NULL, INDEX IDX_5B7CACB882EA2E54 (commande_id), INDEX IDX_5B7CACB88C6396EC (commande_ligne_id), UNIQUE INDEX uniq_pick_order_line (commande_id, commande_ligne_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE commande_pick_line ADD CONSTRAINT FK_5B7CACB882EA2E54 FOREIGN KEY (commande_id) REFERENCES commande (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE commande_pick_line ADD CONSTRAINT FK_5B7CACB88C6396EC FOREIGN KEY (commande_ligne_id) REFERENCES commande_ligne (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE cart ADD CONSTRAINT FK_BA388B7A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE cart_item ADD CONSTRAINT FK_F0FE25271AD5CDBF FOREIGN KEY (cart_id) REFERENCES cart (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE commande ADD CONSTRAINT FK_6EEAA67DA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE commande_ligne ADD CONSTRAINT FK_6E98044082EA2E54 FOREIGN KEY (commande_id) REFERENCES commande (id)');
        $this->addSql('ALTER TABLE stock_reservation ADD CONSTRAINT FK_9D06EF61A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE stock_reservation ADD CONSTRAINT FK_9D06EF6182EA2E54 FOREIGN KEY (commande_id) REFERENCES commande (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE commande_pick_line DROP FOREIGN KEY FK_5B7CACB882EA2E54');
        $this->addSql('ALTER TABLE commande_pick_line DROP FOREIGN KEY FK_5B7CACB88C6396EC');
        $this->addSql('DROP TABLE commande_pick_line');
        $this->addSql('ALTER TABLE cart DROP FOREIGN KEY FK_BA388B7A76ED395');
        $this->addSql('ALTER TABLE cart_item DROP FOREIGN KEY FK_F0FE25271AD5CDBF');
        $this->addSql('ALTER TABLE commande DROP FOREIGN KEY FK_6EEAA67DA76ED395');
        $this->addSql('ALTER TABLE commande_ligne DROP FOREIGN KEY FK_6E98044082EA2E54');
        $this->addSql('ALTER TABLE stock_reservation DROP FOREIGN KEY FK_9D06EF61A76ED395');
        $this->addSql('ALTER TABLE stock_reservation DROP FOREIGN KEY FK_9D06EF6182EA2E54');
    }
}
