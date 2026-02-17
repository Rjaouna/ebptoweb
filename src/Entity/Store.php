<?php

namespace App\Entity;

use App\Repository\StoreRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StoreRepository::class)]
#[ORM\Table(name: 'store')]
class Store
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Identité
    #[ORM\Column(length: 120)]
    private string $name = 'Hopic';

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $legalName = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $website = null;

    // Adresse
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $addressLine1 = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $addressLine2 = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $postalCode = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $region = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $country = null;

    // Légal (optionnel)
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $ice = null;        // Maroc
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $vatNumber = null;  // TVA / VAT
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $rc = null;         // registre commerce
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $ifNumber = null;   // identifiant fiscal

    // Paramètres
    #[ORM\Column(length: 10)]
    private string $currency = 'MAD';

    #[ORM\Column(length: 20)]
    private string $locale = 'fr_FR';

    // Branding
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logoPath = null; // ex: img/store/logo.png

    // Meta
    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = trim($name); return $this; }

    public function getLegalName(): ?string { return $this->legalName; }
    public function setLegalName(?string $legalName): static { $this->legalName = $legalName ? trim($legalName) : null; return $this; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): static { $this->email = $email ? trim($email) : null; return $this; }

    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $phone): static { $this->phone = $phone ? trim($phone) : null; return $this; }

    public function getWebsite(): ?string { return $this->website; }
    public function setWebsite(?string $website): static { $this->website = $website ? trim($website) : null; return $this; }

    public function getAddressLine1(): ?string { return $this->addressLine1; }
    public function setAddressLine1(?string $v): static { $this->addressLine1 = $v ? trim($v) : null; return $this; }

    public function getAddressLine2(): ?string { return $this->addressLine2; }
    public function setAddressLine2(?string $v): static { $this->addressLine2 = $v ? trim($v) : null; return $this; }

    public function getPostalCode(): ?string { return $this->postalCode; }
    public function setPostalCode(?string $v): static { $this->postalCode = $v ? trim($v) : null; return $this; }

    public function getCity(): ?string { return $this->city; }
    public function setCity(?string $v): static { $this->city = $v ? trim($v) : null; return $this; }

    public function getRegion(): ?string { return $this->region; }
    public function setRegion(?string $v): static { $this->region = $v ? trim($v) : null; return $this; }

    public function getCountry(): ?string { return $this->country; }
    public function setCountry(?string $v): static { $this->country = $v ? trim($v) : null; return $this; }

    public function getIce(): ?string { return $this->ice; }
    public function setIce(?string $v): static { $this->ice = $v ? trim($v) : null; return $this; }

    public function getVatNumber(): ?string { return $this->vatNumber; }
    public function setVatNumber(?string $v): static { $this->vatNumber = $v ? trim($v) : null; return $this; }

    public function getRc(): ?string { return $this->rc; }
    public function setRc(?string $v): static { $this->rc = $v ? trim($v) : null; return $this; }

    public function getIfNumber(): ?string { return $this->ifNumber; }
    public function setIfNumber(?string $v): static { $this->ifNumber = $v ? trim($v) : null; return $this; }

    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $v): static { $this->currency = strtoupper(trim($v ?: 'MAD')); return $this; }

    public function getLocale(): string { return $this->locale; }
    public function setLocale(string $v): static { $this->locale = trim($v ?: 'fr_FR'); return $this; }

    public function getLogoPath(): ?string { return $this->logoPath; }
    public function setLogoPath(?string $v): static { $this->logoPath = $v ? ltrim($v, '/') : null; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function touch(): static { $this->updatedAt = new \DateTimeImmutable(); return $this; }

    public function toArray(): array
    {
        return [
            'id' => $this->getId(),
            'name' => $this->getName(),
            'legalName' => $this->getLegalName(),
            'email' => $this->getEmail(),
            'phone' => $this->getPhone(),
            'website' => $this->getWebsite(),
            'addressLine1' => $this->getAddressLine1(),
            'addressLine2' => $this->getAddressLine2(),
            'postalCode' => $this->getPostalCode(),
            'city' => $this->getCity(),
            'region' => $this->getRegion(),
            'country' => $this->getCountry(),
            'ice' => $this->getIce(),
            'vatNumber' => $this->getVatNumber(),
            'rc' => $this->getRc(),
            'ifNumber' => $this->getIfNumber(),
            'currency' => $this->getCurrency(),
            'locale' => $this->getLocale(),
            'logoPath' => $this->getLogoPath(),
        ];
    }
}
