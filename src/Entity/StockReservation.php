<?php

namespace App\Entity;

use App\Repository\StockReservationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StockReservationRepository::class)]
#[ORM\Table(name: 'stock_reservation')]
#[ORM\Index(name: 'idx_res_uid_status_exp', columns: ['uid', 'status', 'expires_at'])]
#[ORM\Index(name: 'idx_res_user_status', columns: ['user_id', 'status'])]
class StockReservation
{
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_RELEASED = 'released';
    public const STATUS_CONSUMED = 'consumed';

    public const STATUSES = [
        self::STATUS_RESERVED,
        self::STATUS_RELEASED,
        self::STATUS_CONSUMED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Commande::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Commande $commande = null;

    #[ORM\Column(type: 'string', length: 128)]
    private string $uid;

    #[ORM\Column(type: 'integer')]
    private int $quantity = 1;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_RESERVED;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    public function __construct(User $user, string $uid, int $quantity, \DateTimeImmutable $expiresAt)
    {
        $this->user = $user;
        $this->uid = $uid;
        $this->quantity = max(1, min(999, $quantity));
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $expiresAt;
        $this->status = self::STATUS_RESERVED;
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }
    public function getCommande(): ?Commande { return $this->commande; }
    public function setCommande(?Commande $commande): self { $this->commande = $commande; return $this; }

    public function getUid(): string { return $this->uid; }
    public function getQuantity(): int { return $this->quantity; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid reservation status');
        }
        $this->status = $status;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }

    public function isActive(\DateTimeImmutable $now = new \DateTimeImmutable()): bool
    {
        return $this->status === self::STATUS_RESERVED && $this->expiresAt > $now;
    }
}
