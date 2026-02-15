<?php

namespace App\Controller;

use App\Repository\CartRepository;
use App\Repository\CartItemRepository;
use App\Repository\CommandeRepository;
use App\Repository\CommandeLigneRepository;
use App\Repository\StockReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class DevResetDbController extends AbstractController
{
    #[Route('/dev/reset-db', name: 'dev_reset_db', methods: ['POST'])]
    public function reset(
        EntityManagerInterface $em,
        StockReservationRepository $stockReservationRepo,
        CommandeLigneRepository $commandeLigneRepo,
        CartItemRepository $cartItemRepo,
        CommandeRepository $commandeRepo,
        CartRepository $cartRepo,
    ): JsonResponse {
        // DEV only
        if ($this->getParameter('kernel.environment') !== 'dev') {
            return $this->json(['ok' => false, 'message' => 'DEV only'], 404);
        }

        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        try {
            // ⚠️ ordre important (enfants -> parents)
            $n1 = $stockReservationRepo->createQueryBuilder('x')->delete()->getQuery()->execute();
            $n2 = $commandeLigneRepo->createQueryBuilder('x')->delete()->getQuery()->execute();
            $n3 = $cartItemRepo->createQueryBuilder('x')->delete()->getQuery()->execute();
            $n4 = $commandeRepo->createQueryBuilder('x')->delete()->getQuery()->execute();
            $n5 = $cartRepo->createQueryBuilder('x')->delete()->getQuery()->execute();

            // optionnel mais ok
            $em->clear();

            return $this->json([
                'ok' => true,
                'deleted' => [
                    'stock_reservation' => $n1,
                    'commande_ligne'    => $n2,
                    'cart_item'         => $n3,
                    'commande'          => $n4,
                    'cart'              => $n5,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
