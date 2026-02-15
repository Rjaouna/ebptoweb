<?php

namespace App\Entity;

use App\Repository\CartItemRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CartItemRepository::class)]
#[ORM\Table(name: 'cart_item')]
#[ORM\Index(name: 'idx_cart_uid', columns: ['cart_id', 'uid'])]
#[ORM\HasLifecycleCallbacks]
class CartItem
{
    public const STATUS_IN_CART  = 'in_cart';
    public const STATUS_IN_ORDER = 'in_order';
    public const STATUS_REMOVED  = 'removed';

    public const STATUSES = [
        self::STATUS_IN_CART,
        self::STATUS_IN_ORDER,
        self::STATUS_REMOVED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'items', targetEntity: Cart::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Cart $cart = null;

    #[ORM\Column(type: 'string', length: 128)]
    private string $uid;

    #[ORM\Column(type: 'integer')]
    private int $quantity = 1;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_IN_CART;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $uid = '', int $quantity = 1)
    {
        $this->uid = $uid;
        $this->quantity = max(1, min(999, $quantity));

        // ✅ optionnel (confort) : tu peux laisser, le lifecycle sécurise aussi
        $this->status = self::STATUS_IN_CART;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();

        // dates
        if (!isset($this->createdAt)) {
            $this->createdAt = $now;
        }
        $this->updatedAt = $now;

        // ✅ statut par défaut garanti à l’insertion
        if (empty($this->status)) {
            $this->status = self::STATUS_IN_CART;
        }
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getCart(): ?Cart { return $this->cart; }
    public function setCart(?Cart $cart): self { $this->cart = $cart; return $this; }

    public function getUid(): string { return $this->uid; }
    public function setUid(string $uid): self { $this->uid = $uid; return $this; }

    public function getQuantity(): int { return $this->quantity; }
    public function setQuantity(int $quantity): self
    {
        $this->quantity = max(1, min(999, $quantity));
        return $this;
    }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid CartItem status "%s".', $status));
        }
        $this->status = $status;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
