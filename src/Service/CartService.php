<?php

namespace App\Service;

use App\Entity\Cart;
use App\Entity\CartItem;
use App\Entity\User;
use App\Repository\CartItemRepository;
use App\Repository\CartRepository;
use Doctrine\ORM\EntityManagerInterface;

class CartService
{
	public function __construct(
		private EntityManagerInterface $em,
		private CartRepository $cartRepo,
		private CartItemRepository $itemRepo
	) {}

	public function getOrCreate(User $user): Cart
	{
		$cart = $this->cartRepo->findOneByUser($user);
		if ($cart) return $cart;

		$cart = new Cart();
		$cart->setUser($user);

		$this->em->persist($cart);
		$this->em->flush();

		return $cart;
	}

	public function add(User $user, string $uid, int $qty): void
	{
		$qty = max(1, min(999, $qty));
		$cart = $this->getOrCreate($user);

		$item = $this->itemRepo->findOneByCartAndUid($cart, $uid);
		if (!$item) {
			$item = new CartItem($uid, $qty);
			$cart->addItem($item);
			$this->em->persist($item);
		} else {
			$item->setQuantity($item->getQuantity() + $qty);
		}

		$cart->touch();
		$this->em->flush();
	}

	public function setQty(User $user, string $uid, int $qty): void
	{
		$qty = max(0, min(999, $qty));
		$cart = $this->getOrCreate($user);

		$item = $this->itemRepo->findOneByCartAndUid($cart, $uid);

		if ($qty === 0) {
			if ($item) {
				$cart->removeItem($item);
				$this->em->remove($item);
				$cart->touch();
				$this->em->flush();
			}
			return;
		}

		if (!$item) {
			$item = new CartItem($uid, $qty);
			$cart->addItem($item);
			$this->em->persist($item);
		} else {
			$item->setQuantity($qty);
		}

		$cart->touch();
		$this->em->flush();
	}

	public function remove(User $user, string $uid): void
	{
		$cart = $this->getOrCreate($user);
		$item = $this->itemRepo->findOneByCartAndUid($cart, $uid);
		if (!$item) return;

		$cart->removeItem($item);
		$this->em->remove($item);
		$cart->touch();
		$this->em->flush();
	}

	public function clear(User $user): void
	{
		$cart = $this->getOrCreate($user);
		foreach ($cart->getItems() as $item) {
			$this->em->remove($item);
		}
		$cart->touch();
		$this->em->flush();
	}

	/** @return array<string,int> uid => qty */
	public function asUidQtyMap(User $user): array
	{
		$cart = $this->cartRepo->findOneByUser($user);
		if (!$cart) return [];

		$map = [];
		foreach ($cart->getItems() as $it) {
			$map[$it->getUid()] = $it->getQuantity();
		}
		return $map;
	}

	public function totalQty(User $user): int
	{
		$cart = $this->cartRepo->findOneByUser($user);
		if (!$cart) return 0;

		$n = 0;
		foreach ($cart->getItems() as $it) {
			$n += $it->getQuantity();
		}
		return $n;
	}
}
