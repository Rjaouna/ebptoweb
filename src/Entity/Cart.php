<?php

namespace App\Entity;

use App\Repository\CartRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CartRepository::class)]
#[ORM\Table(name: 'cart')]
#[ORM\UniqueConstraint(name: 'uniq_cart_user', columns: ['user_id'])]
class Cart
{
	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	private ?int $id = null;

	// ⚠️ inversedBy doit correspondre à une propriété EXISTANTE dans User : $cart
	#[ORM\OneToOne(inversedBy: 'cart', targetEntity: User::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	private ?User $user = null;

	#[ORM\OneToMany(mappedBy: 'cart', targetEntity: CartItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
	private Collection $items;

	#[ORM\Column(type: 'datetime_immutable')]
	private \DateTimeImmutable $createdAt;

	#[ORM\Column(type: 'datetime_immutable')]
	private \DateTimeImmutable $updatedAt;

	public function __construct()
	{
		$this->items = new ArrayCollection();
		$now = new \DateTimeImmutable();
		$this->createdAt = $now;
		$this->updatedAt = $now;
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function getUser(): ?User
	{
		return $this->user;
	}
	public function setUser(User $user): self
	{
		$this->user = $user;
		return $this;
	}

	/** @return Collection<int, CartItem> */
	public function getItems(): Collection
	{
		return $this->items;
	}

	public function addItem(CartItem $item): self
	{
		if (!$this->items->contains($item)) {
			$this->items->add($item);
			$item->setCart($this);
		}
		return $this;
	}

	public function removeItem(CartItem $item): self
	{
		if ($this->items->removeElement($item)) {
			if ($item->getCart() === $this) {
				$item->setCart(null);
			}
		}
		return $this;
	}

	public function touch(): void
	{
		$this->updatedAt = new \DateTimeImmutable();
	}
}
