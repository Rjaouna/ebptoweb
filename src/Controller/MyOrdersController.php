<?php

namespace App\Controller;

use App\Repository\CommandeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class MyOrdersController extends AbstractController
{
    #[Route('/ajax/my-orders', name: 'ajax_my_orders', methods: ['GET'])]
    public function myOrders(CommandeRepository $repo): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $user = $this->getUser();

        // ⚠️ Adapte ces champs selon ton entity Commande (createdAt, reference, totalTtc, statut...)
        $orders = $repo->createQueryBuilder('c')
            ->andWhere('c.user = :u')
            ->setParameter('u', $user)
            ->orderBy('c.id', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($orders as $o) {
            $out[] = [
                'id' => $o->getId(),
                'reference' => method_exists($o, 'getReference') ? $o->getReference() : ('CMD-'.$o->getId()),
                'createdAt' => method_exists($o, 'getCreatedAt') && $o->getCreatedAt()
                    ? $o->getCreatedAt()->format('Y-m-d H:i')
                    : null,
                'totalTtc' => method_exists($o, 'getTotalTtc') ? (float)$o->getTotalTtc() : null,
                'status' => method_exists($o, 'getStatus') ? (string)$o->getStatus() : null,
            ];
        }

        return $this->json([
            'ok' => true,
            'orders' => $out,
        ]);
    }
}
