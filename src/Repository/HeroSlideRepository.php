<?php

namespace App\Repository;

use App\Entity\HeroSlide;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class HeroSlideRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HeroSlide::class);
    }

    /** @return HeroSlide[] */
    public function findForHomepage(): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.enabled = 1')
            ->orderBy('s.pinnedAt', 'DESC')
            ->addOrderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
