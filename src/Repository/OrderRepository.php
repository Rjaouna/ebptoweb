<?php

namespace App\Repository;

use App\Entity\Commande;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Commande::class);
    }

    public function countForUser(User $user, string $status = '', string $q = ''): int
    {
        $qb = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.user = :u')
            ->setParameter('u', $user);

        if ($status !== '') {
            $qb->andWhere('c.status = :st')->setParameter('st', $status);
        }

        if ($q !== '') {
            $qb->andWhere('c.reference LIKE :q')->setParameter('q', '%'.$q.'%');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return Commande[]
     */
    public function findForUser(User $user, int $limit, int $offset, string $status = '', string $q = ''): array
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.user = :u')
            ->setParameter('u', $user)
            ->orderBy('c.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        if ($status !== '') {
            $qb->andWhere('c.status = :st')->setParameter('st', $status);
        }

        if ($q !== '') {
            $qb->andWhere('c.reference LIKE :q')->setParameter('q', '%'.$q.'%');
        }

        return $qb->getQuery()->getResult();
    }
}
