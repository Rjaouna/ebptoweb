<?php

namespace App\Controller\Admin;

use App\Entity\Commande;
use App\Repository\CommandeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route('/admin/orders')]
final class OrderAdminController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    #[Route('', name: 'admin_orders_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->render('admin/orders/index.html.twig', [
            'statuses' => Commande::STATUSES,
        ]);
    }

    #[Route('/{id}', name: 'admin_orders_show', requirements: ['id' => '\d+'], methods: ['GET'])]
public function show(Commande $order): Response
{
    $this->denyAccessUnlessGranted('ROLE_ADMIN');

    $st = (string)($order->getStatus() ?? Commande::STATUS_RESERVED);

    return $this->render('admin/orders/show.html.twig', [
        'order' => $order,
        'statusLabel' => $this->statusLabel($st),
        'allowedNext' => $this->allowedNext($st),
        'csrfStatus' => $this->container->get('security.csrf.token_manager')->getToken('admin_order_status')->getValue(),
    ]);
}


    #[Route('/ajax/list', name: 'admin_orders_ajax_list', methods: ['GET'])]
    public function ajaxList(Request $request, CommandeRepository $repo): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $page   = max(1, (int) $request->query->get('page', 1));
        $limit  = min(50, max(10, (int) $request->query->get('limit', 15)));
        $offset = ($page - 1) * $limit;

        $status = trim((string) $request->query->get('status', ''));
        $q      = trim((string) $request->query->get('q', ''));

        $qb = $repo->createQueryBuilder('c')
            ->leftJoin('c.user', 'u')->addSelect('u')
            ->orderBy('c.createdAt', 'DESC');

        if ($status !== '') {
            $qb->andWhere('c.status = :st')->setParameter('st', $status);
        }

        if ($q !== '') {
            // ✅ sans CAST => pas de 500 sur certaines DB
            if (ctype_digit($q)) {
                $qb->andWhere('(c.id = :id OR c.reference LIKE :q OR u.email LIKE :q)')
                   ->setParameter('id', (int) $q)
                   ->setParameter('q', '%' . $q . '%');
            } else {
                $qb->andWhere('(c.reference LIKE :q OR u.email LIKE :q)')
                   ->setParameter('q', '%' . $q . '%');
            }
        }

        $countQb = clone $qb;
        $total = (int) $countQb
            ->select('COUNT(DISTINCT c.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        $rows = $qb
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $orders = [];
        foreach ($rows as $o) {
            $st = (string)($o->getStatus() ?? Commande::STATUS_RESERVED);

            $orders[] = [
                'id' => $o->getId(),
                'reference' => $o->getReference() ?: ('CMD-' . $o->getId()),
                'createdAt' => $o->getCreatedAt()?->format('d/m/Y H:i'),
                'status' => $st,
                'statusLabel' => $this->statusLabel($st),
                'totalTtc' => $o->getTotalTtc(),
                'customer' => [
                    'email' => $o->getUser()?->getEmail(),
                    'id' => $o->getUser()?->getId(),
                ],
                'itemsCount' => $o->getCommandeLignes()->count(),
                'allowedNext' => $this->allowedNext($st),
            ];
        }

        return $this->json([
            'ok' => true,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => (int) max(1, ceil($total / $limit)),
            'orders' => $orders,
        ]);
    }

    #[Route('/ajax/{id}/status', name: 'admin_orders_ajax_status', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function ajaxChangeStatus(
        Commande $order,
        Request $request,
        CsrfTokenManagerInterface $csrf
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $token = (string)($request->request->get('_token') ?: $request->headers->get('X-CSRF-TOKEN'));
        if (!$csrf->isTokenValid(new CsrfToken('admin_order_status', $token))) {
            return $this->json(['ok' => false, 'message' => 'CSRF invalide.'], 403);
        }

        $next = trim((string) $request->request->get('status', ''));
        if ($next === '') {
            return $this->json(['ok' => false, 'message' => 'Statut manquant.'], 400);
        }
        if (!in_array($next, Commande::STATUSES, true)) {
            return $this->json(['ok' => false, 'message' => 'Statut invalide.'], 400);
        }

        $current = (string)($order->getStatus() ?? Commande::STATUS_RESERVED);
        $allowed = $this->allowedNext($current);

        // ✅ anti-saut / anti-retour
        if (!in_array($next, $allowed, true)) {
            return $this->json([
                'ok' => false,
                'message' => 'Transition refusée (pas de saut / pas de retour arrière).',
                'allowedNext' => $allowed,
            ], 422);
        }

        $order->setStatus($next);
        $order->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $this->json([
            'ok' => true,
            'status' => $order->getStatus(),
            'statusLabel' => $this->statusLabel((string)$order->getStatus()),
            'allowedNext' => $this->allowedNext((string)$order->getStatus()),
        ]);
    }

    // -----------------------
    // Helpers (workflow)
    // -----------------------
    private function statusLabel(string $status): string
    {
        return match ($status) {
            Commande::STATUS_RESERVED  => 'Reçue',
            Commande::STATUS_PREPARING => 'En préparation',
            Commande::STATUS_READY     => 'Terminée',
            Commande::STATUS_SHIPPED   => 'Expédiée',
            Commande::STATUS_DELIVERED => 'Livrée',
            Commande::STATUS_CANCELLED => 'Annulée',
            default => $status,
        };
    }

    private function allowedNext(string $status): array
    {
        return match ($status) {
            Commande::STATUS_RESERVED  => [Commande::STATUS_PREPARING, Commande::STATUS_CANCELLED],
            Commande::STATUS_PREPARING => [Commande::STATUS_READY, Commande::STATUS_CANCELLED],
            Commande::STATUS_READY     => [Commande::STATUS_SHIPPED],
            Commande::STATUS_SHIPPED   => [Commande::STATUS_DELIVERED],
            default => [],
        };
    }
}
