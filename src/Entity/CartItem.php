<?php

namespace App\Entity;

use App\Repository\CartItemRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CartItemRepository::class)]
#[ORM\Table(name: 'cart_item')]
#[ORM\Index(name: 'idx_cart_uid', columns: ['cart_id', 'uid'])]
class CartItem
{
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

	#[ORM\Column(type: 'datetime_immutable')]
	private \DateTimeImmutable $createdAt;

	#[ORM\Column(type: 'datetime_immutable')]
	private \DateTimeImmutable $updatedAt;

	public function __construct(string $uid = '', int $quantity = 1)
	{
		$this->uid = $uid;
		$this->quantity = max(1, min(999, $quantity));
		$now = new \DateTimeImmutable();
		$this->createdAt = $now;
		$this->updatedAt = $now;
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function getCart(): ?Cart
	{
		return $this->cart;
	}
	public function setCart(?Cart $cart): self
	{
		$this->cart = $cart;
		return $this;
	}

	public function getUid(): string
	{
		return $this->uid;
	}
	public function setUid(string $uid): self
	{
		$this->uid = $uid;
		return $this;
	}

	public function getQuantity(): int
	{
		return $this->quantity;
	}
	public function setQuantity(int $quantity): self
	{
		$this->quantity = max(1, min(999, $quantity));
		$this->updatedAt = new \DateTimeImmutable();
		return $this;
	}
}
