<?php

namespace App\Controller\Prepa;

use App\Entity\Commande;
use App\Entity\CommandeLigne;
use App\Repository\CommandeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route('/prepa/picking')]
final class PickingController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CsrfTokenManagerInterface $csrf
    ) {}

    #[Route('', name: 'prepa_picking_index', methods: ['GET'])]
    public function index(Request $request, CommandeRepository $repo): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PREPA');

        $q = trim((string) $request->query->get('q', ''));

        $qb = $repo->createQueryBuilder('c')
            ->leftJoin('c.user', 'u')->addSelect('u')
            ->where('c.status = :st')->setParameter('st', Commande::STATUS_PREPARING)
            ->orderBy('c.updatedAt', 'ASC');

        if ($q !== '') {
            $qb->andWhere('c.reference LIKE :q OR u.email LIKE :q OR c.customerName LIKE :q')
               ->setParameter('q', '%' . $q . '%');
        }

        $orders = $qb->getQuery()->getResult();

        return $this->render('prepa/picking/index.html.twig', [
            'orders' => $orders,
            'q' => $q,

            // ✅ PAS de constant() Twig → on passe ce qu’on veut afficher
            'statusPreparing' => Commande::STATUS_PREPARING,
            'statusLabels' => array_combine(
                Commande::STATUSES,
                array_map([Commande::class, 'label'], Commande::STATUSES)
            ),
        ]);
    }

    #[Route('/{id}', name: 'prepa_picking_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Commande $order, SessionInterface $session): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PREPA');

        if ($order->getStatus() !== Commande::STATUS_PREPARING) {
            $this->addFlash('warning', 'Cette commande n’est pas en préparation.');
            return $this->redirectToRoute('prepa_picking_index');
        }

        $orderId = (int) $order->getId();
        $all = (array) $session->get('prepa_picks', []);
        $picks = (array) ($all[$orderId] ?? []);

        // VM lignes + état manquants
        $linesVm = [];
        $missingLines = 0;
        $missingQtyTotal = 0;
        $doneLines = 0;

        foreach ($order->getCommandeLignes() as $l) {
            $lineId = (int) $l->getId();
            $need = (int) ($l->getQuantity() ?? 0);
            $picked = (int) ($picks[$lineId] ?? 0);

            $missing = max(0, $need - $picked);
            if ($need > 0 && $picked > 0) $doneLines++;
            if ($missing > 0) { $missingLines++; $missingQtyTotal += $missing; }

            $linesVm[] = [
                'id' => $lineId,
                'name' => $l->getName() ?: ('Article #' . $lineId),
                'ref' => $l->getUid(), // ta "référence article" (si tu l’utilises comme ça)
                'need' => $need,
                'picked' => $picked,
                'missing' => $missing,
            ];
        }

        return $this->render('prepa/picking/show.html.twig', [
            'order' => $order,
            'lines' => $linesVm,
            'missingLines' => $missingLines,
            'missingQtyTotal' => $missingQtyTotal,
            'doneLines' => $doneLines,
            'totalLines' => count($linesVm),

            'csrfLine' => $this->csrf->getToken('prepa_pick_line')->getValue(),
            'csrfFinalize' => $this->csrf->getToken('prepa_pick_finalize')->getValue(),

            'statusLabels' => array_combine(
                Commande::STATUSES,
                array_map([Commande::class, 'label'], Commande::STATUSES)
            ),
        ]);
    }

    #[Route('/{id}/line/{lineId}', name: 'prepa_picking_ajax_line', requirements: ['id' => '\d+', 'lineId' => '\d+'], methods: ['POST'])]
    public function ajaxPickLine(
        Commande $order,
        int $lineId,
        Request $request,
        SessionInterface $session
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_PREPA');

        if ($order->getStatus() !== Commande::STATUS_PREPARING) {
            return $this->json(['ok' => false, 'message' => 'Commande pas en préparation.'], 409);
        }

        $token = (string)($request->request->get('_token') ?: $request->headers->get('X-CSRF-TOKEN'));
        if (!$this->csrf->isTokenValid(new CsrfToken('prepa_pick_line', $token))) {
            return $this->json(['ok' => false, 'message' => 'CSRF invalide.'], 403);
        }

        $qty = (int) $request->request->get('qty', 0);
        $qty = max(0, min(9999, $qty));

        /** @var CommandeLigne|null $line */
        $line = null;
        foreach ($order->getCommandeLignes() as $l) {
            if ((int)$l->getId() === (int)$lineId) { $line = $l; break; }
        }
        if (!$line) {
            return $this->json(['ok' => false, 'message' => 'Ligne introuvable.'], 404);
        }

        $orderId = (int) $order->getId();
        $all = (array) $session->get('prepa_picks', []);
        $picks = (array) ($all[$orderId] ?? []);

        $picks[(int)$lineId] = $qty;
        $all[$orderId] = $picks;
        $session->set('prepa_picks', $all);

        // recalc manque
        $missingLines = 0; $missingQtyTotal = 0;
        foreach ($order->getCommandeLignes() as $l2) {
            $need = (int) ($l2->getQuantity() ?? 0);
            $picked = (int) ($picks[(int)$l2->getId()] ?? 0);
            $missing = max(0, $need - $picked);
            if ($missing > 0) { $missingLines++; $missingQtyTotal += $missing; }
        }

        return $this->json([
            'ok' => true,
            'lineId' => (int)$lineId,
            'picked' => $qty,
            'missingLines' => $missingLines,
            'missingQtyTotal' => $missingQtyTotal,
        ]);
    }

    #[Route('/{id}/finalize', name: 'prepa_picking_ajax_finalize', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function ajaxFinalize(
        Commande $order,
        Request $request,
        SessionInterface $session
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_PREPA');

        if ($order->getStatus() !== Commande::STATUS_PREPARING) {
            return $this->json(['ok' => false, 'message' => 'Commande pas en préparation.'], 409);
        }

        $token = (string)($request->request->get('_token') ?: $request->headers->get('X-CSRF-TOKEN'));
        if (!$this->csrf->isTokenValid(new CsrfToken('prepa_pick_finalize', $token))) {
            return $this->json(['ok' => false, 'message' => 'CSRF invalide.'], 403);
        }

        $force = (bool) $request->request->get('force', false);

        $orderId = (int) $order->getId();
        $all = (array) $session->get('prepa_picks', []);
        $picks = (array) ($all[$orderId] ?? []);

        $missing = [];
        foreach ($order->getCommandeLignes() as $l) {
            $need = (int) ($l->getQuantity() ?? 0);
            $picked = (int) ($picks[(int)$l->getId()] ?? 0);
            $m = max(0, $need - $picked);
            if ($m > 0) {
                $missing[] = [
                    'lineId' => (int)$l->getId(),
                    'ref' => $l->getUid(),
                    'name' => $l->getName(),
                    'missing' => $m,
                ];
            }
        }

        if (!$force && count($missing) > 0) {
            return $this->json([
                'ok' => false,
                'code' => 'MISSING_ITEMS',
                'message' => 'Des articles sont manquants. Confirme la clôture.',
                'missing' => $missing,
            ], 409);
        }

        // ✅ On passe au statut suivant: READY
        $order->setStatus(Commande::STATUS_READY);
        $order->setUpdatedAt(new \DateTimeImmutable());

        // ✅ si manque: on trace dans note (persistant)
        if (count($missing) > 0) {
            $parts = [];
            foreach ($missing as $m) {
                $label = $m['ref'] ?: ('#' . $m['lineId']);
                $parts[] = $label . ' x' . $m['missing'];
            }
            $note = trim((string) $order->getNote());
            $note .= ($note ? "\n" : "") . '[PICKING] Manque: ' . implode(', ', $parts);
            $order->setNote($note);
        }

        $this->em->flush();

        // ✅ purge session picks pour cette commande
        unset($all[$orderId]);
        $session->set('prepa_picks', $all);

        return $this->json([
            'ok' => true,
            'status' => $order->getStatus(),
            'statusLabel' => Commande::label((string)$order->getStatus()),
            'missingCount' => count($missing),
        ]);
    }
}
