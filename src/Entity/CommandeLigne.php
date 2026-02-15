<?php

namespace App\Entity;

use App\Repository\CommandeLigneRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CommandeLigneRepository::class)]
class CommandeLigne
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'commandeLignes')]
    private ?Commande $commande = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $uid = null;

    #[ORM\Column(nullable: true)]
    private ?int $quantity = null;

    #[ORM\Column(nullable: true)]
    private ?float $unitPriceHt = null;

    #[ORM\Column(nullable: true)]
    private ?float $unitPriceTtc = null;

    #[ORM\Column(nullable: true)]
    private ?float $lineTotalHt = null;

    #[ORM\Column(nullable: true)]
    private ?float $lineTotalTtc = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCommande(): ?Commande
    {
        return $this->commande;
    }

    public function setCommande(?Commande $commande): static
    {
        $this->commande = $commande;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getUid(): ?string
    {
        return $this->uid;
    }

    public function setUid(?string $uid): static
    {
        $this->uid = $uid;

        return $this;
    }

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function setQuantity(?int $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getUnitPriceHt(): ?float
    {
        return $this->unitPriceHt;
    }

    public function setUnitPriceHt(?float $unitPriceHt): static
    {
        $this->unitPriceHt = $unitPriceHt;

        return $this;
    }

    public function getUnitPriceTtc(): ?float
    {
        return $this->unitPriceTtc;
    }

    public function setUnitPriceTtc(?float $unitPriceTtc): static
    {
        $this->unitPriceTtc = $unitPriceTtc;

        return $this;
    }

    public function getLineTotalHt(): ?float
    {
        return $this->lineTotalHt;
    }

    public function setLineTotalHt(?float $lineTotalHt): static
    {
        $this->lineTotalHt = $lineTotalHt;

        return $this;
    }

    public function getLineTotalTtc(): ?float
    {
        return $this->lineTotalTtc;
    }

    public function setLineTotalTtc(?float $lineTotalTtc): static
    {
        $this->lineTotalTtc = $lineTotalTtc;

        return $this;
    }
}
