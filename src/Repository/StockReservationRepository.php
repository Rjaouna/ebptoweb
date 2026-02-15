<?php

namespace App\Repository;

use App\Entity\StockReservation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class StockReservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StockReservation::class);
    }

    /** @return array<string,int> uid => qtyReservedActive */
    public function sumActiveByUid(array $uids, ?\DateTimeImmutable $now = null): array
    {
        if (!$uids) return [];
        $now ??= new \DateTimeImmutable();

        $rows = $this->createQueryBuilder('r')
            ->select('r.uid AS uid, SUM(r.quantity) AS qty')
            ->andWhere('r.uid IN (:uids)')
            ->andWhere('r.status = :status')
            ->andWhere('r.expiresAt > :now')
            ->setParameter('uids', $uids)
            ->setParameter('status', StockReservation::STATUS_RESERVED)
            ->setParameter('now', $now)
            ->groupBy('r.uid')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $out[(string)$row['uid']] = (int)$row['qty'];
        }
        return $out;
    }

    /** Libère les réservations expirées */
    public function releaseExpired(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();

        return $this->createQueryBuilder('r')
            ->update()
            ->set('r.status', ':released')
            ->andWhere('r.status = :reserved')
            ->andWhere('r.expiresAt <= :now')
            ->setParameter('released', StockReservation::STATUS_RELEASED)
            ->setParameter('reserved', StockReservation::STATUS_RESERVED)
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();
    }
public function reservedQtyActive(string $uid): int
{
    $now = new \DateTimeImmutable();

    $qb = $this->createQueryBuilder('r')
        ->select('COALESCE(SUM(r.quantity), 0)')
        ->andWhere('r.uid = :uid')
        ->andWhere('r.status = :st')
        ->andWhere('r.expiresAt > :now')
        ->setParameter('uid', $uid)
        ->setParameter('st', \App\Entity\StockReservation::STATUS_RESERVED)
        ->setParameter('now', $now);

    return (int) $qb->getQuery()->getSingleScalarResult();
}
public function reservedQtyActiveByUids(array $uids): array
{
    $now = new \DateTimeImmutable();

    $rows = $this->createQueryBuilder('r')
        ->select('r.uid AS uid, COALESCE(SUM(r.quantity), 0) AS qty')
        ->andWhere('r.uid IN (:uids)')
        ->andWhere('r.status = :st')
        ->andWhere('r.expiresAt > :now')
        ->groupBy('r.uid')
        ->setParameter('uids', $uids)
        ->setParameter('st', \App\Entity\StockReservation::STATUS_RESERVED)
        ->setParameter('now', $now)
        ->getQuery()
        ->getArrayResult();

    $out = [];
    foreach ($rows as $row) {
        $out[$row['uid']] = (int) $row['qty'];
    }
    return $out;
}


}
