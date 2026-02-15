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


	public function findInCartByUser(\App\Entity\User $user): array
{
    return $this->createQueryBuilder('ci')
        ->join('ci.cart', 'c')
        ->andWhere('c.user = :user')
        ->andWhere('ci.status = :st')
        ->setParameter('user', $user)
        ->setParameter('st', \App\Entity\CartItem::STATUS_IN_CART)
        ->orderBy('ci.id', 'ASC')
        ->getQuery()
        ->getResult();
}
public function findOneByCartUidAndStatus(\App\Entity\Cart $cart, string $uid, string $status): ?\App\Entity\CartItem
{
    return $this->createQueryBuilder('ci')
        ->andWhere('ci.cart = :cart')
        ->andWhere('ci.uid = :uid')
        ->andWhere('ci.status = :status')
        ->setParameter('cart', $cart)
        ->setParameter('uid', $uid)
        ->setParameter('status', $status)
        ->setMaxResults(1)
        ->getQuery()
        ->getOneOrNullResult();
}

}
