<?php

namespace App\Repository;

use App\Entity\Cart;
use App\Entity\CartItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CartItemRepository extends ServiceEntityRepository
{
	public function __construct(ManagerRegistry $registry)
	{
		parent::__construct($registry, CartItem::class);
	}

	public function findOneByCartAndUid(Cart $cart, string $uid): ?CartItem
	{
		return $this->findOneBy(['cart' => $cart, 'uid' => $uid]);
	}
}
