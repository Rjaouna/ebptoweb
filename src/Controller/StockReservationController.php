<?php

namespace App\Controller;

use App\Entity\StockReservation;
use App\Repository\StockReservationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class StockReservationController extends AbstractController
{
    #[Route('/stock/reserved', name: 'stock_reserved', methods: ['GET'])]
    public function reserved(Request $request, StockReservationRepository $repo): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $now = new \DateTimeImmutable();

        // ✅ optionnel: ?uids=uid1,uid2,uid3
        $uidsParam = trim((string) $request->query->get('uids', ''));
        $uids = [];

        if ($uidsParam !== '') {
            $uids = array_values(array_filter(array_map(
                static fn(string $s) => trim($s),
                explode(',', $uidsParam)
            )));

            // évite les énormes listes (sécurité)
            if (count($uids) > 500) {
                $uids = array_slice($uids, 0, 500);
            }
        }

        $qb = $repo->createQueryBuilder('r')
            ->select('r.uid AS uid, SUM(r.quantity) AS qty')
            ->andWhere('r.status = :status')
            ->andWhere('r.expiresAt > :now')
            ->setParameter('status', StockReservation::STATUS_RESERVED)
            ->setParameter('now', $now)
            ->groupBy('r.uid');

        if (!empty($uids)) {
            $qb->andWhere('r.uid IN (:uids)')
               ->setParameter('uids', $uids);
        }

        $rows = $qb->getQuery()->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $uid = (string) ($row['uid'] ?? '');
            if ($uid === '') continue;
            $map[$uid] = (int) ($row['qty'] ?? 0);
        }

        return $this->json([
            'ok' => true,
            'reserved' => $map,
            'now' => $now->format(DATE_ATOM),
        ]);
    }
}
