<?php

namespace App\Entity;

use App\Repository\HeroSlideRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: HeroSlideRepository::class)]
#[ORM\HasLifecycleCallbacks]
class HeroSlide
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Nom fichier image stocké dans public/img/slides/
    #[ORM\Column(length: 255)]
    private string $image = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $subtitle = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $primaryLabel = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $primaryUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $secondaryLabel = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $secondaryUrl = null;

    #[ORM\Column]
    private bool $enabled = true;

    // Date d’ajout
    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    // ⭐ mise en avant (optionnel mais recommandé)
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $pinnedAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if (!isset($this->createdAt)) {
            $this->createdAt = new \DateTimeImmutable();
        }
    }

    public function getId(): ?int { return $this->id; }

    public function getImage(): string { return $this->image; }
    public function setImage(string $image): self { $this->image = $image; return $this; }

    public function getTitle(): ?string { return $this->title; }
    public function setTitle(?string $title): self { $this->title = $title; return $this; }

    public function getSubtitle(): ?string { return $this->subtitle; }
    public function setSubtitle(?string $subtitle): self { $this->subtitle = $subtitle; return $this; }

    public function getPrimaryLabel(): ?string { return $this->primaryLabel; }
    public function setPrimaryLabel(?string $primaryLabel): self { $this->primaryLabel = $primaryLabel; return $this; }

    public function getPrimaryUrl(): ?string { return $this->primaryUrl; }
    public function setPrimaryUrl(?string $primaryUrl): self { $this->primaryUrl = $primaryUrl; return $this; }

    public function getSecondaryLabel(): ?string { return $this->secondaryLabel; }
    public function setSecondaryLabel(?string $secondaryLabel): self { $this->secondaryLabel = $secondaryLabel; return $this; }

    public function getSecondaryUrl(): ?string { return $this->secondaryUrl; }
    public function setSecondaryUrl(?string $secondaryUrl): self { $this->secondaryUrl = $secondaryUrl; return $this; }

    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): self { $this->enabled = $enabled; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): self { $this->createdAt = $createdAt; return $this; }

    public function getPinnedAt(): ?\DateTimeImmutable { return $this->pinnedAt; }
    public function setPinnedAt(?\DateTimeImmutable $pinnedAt): self { $this->pinnedAt = $pinnedAt; return $this; }

    public function bump(): void
    {
        // ✅ "mise en avant" = mettre pinnedAt à maintenant
        $this->pinnedAt = new \DateTimeImmutable();
    }
}
