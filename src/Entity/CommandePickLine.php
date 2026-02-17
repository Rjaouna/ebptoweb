<?php

namespace App\Entity;

use App\Repository\CommandePickLineRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CommandePickLineRepository::class)]
#[ORM\Table(name: 'commande_pick_line')]
#[ORM\UniqueConstraint(name: 'uniq_pick_order_line', columns: ['commande_id','commande_ligne_id'])]
class CommandePickLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Commande::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Commande $commande = null;

    #[ORM\ManyToOne(targetEntity: CommandeLigne::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CommandeLigne $commandeLigne = null;

    #[ORM\Column(type: 'integer')]
    private int $expectedQty = 0;

    #[ORM\Column(type: 'integer')]
    private int $pickedQty = 0;

    #[ORM\Column(type: 'boolean')]
    private bool $done = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Commande $commande, CommandeLigne $ligne)
    {
        $this->commande = $commande;
        $this->commandeLigne = $ligne;
        $this->expectedQty = (int)($ligne->getQuantity() ?? 0);
        $this->pickedQty = 0;
        $this->done = false;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getCommande(): ?Commande { return $this->commande; }
    public function getCommandeLigne(): ?CommandeLigne { return $this->commandeLigne; }

    public function getExpectedQty(): int { return $this->expectedQty; }
    public function setExpectedQty(int $q): self { $this->expectedQty = max(0, $q); return $this; }

    public function getPickedQty(): int { return $this->pickedQty; }
    public function setPickedQty(int $q): self
    {
        $this->pickedQty = max(0, $q);
        $this->done = ($this->pickedQty >= $this->expectedQty) && $this->expectedQty > 0;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function isDone(): bool { return $this->done; }
    public function setDone(bool $done): self { $this->done = $done; $this->updatedAt = new \DateTimeImmutable(); return $this; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
