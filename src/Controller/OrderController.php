<?php

namespace App\Controller;

use App\Entity\CartItem;
use App\Entity\Commande;
use App\Entity\CommandeLigne;
use App\Entity\StockReservation;
use App\Service\CartService;
use App\Repository\StockReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Attribute\Route;
use App\Repository\OrderRepository;
use Symfony\Component\HttpFoundation\Request;
use App\Repository\CommandeRepository;
use App\Repository\CommandeLigneRepository;
use Symfony\Component\HttpFoundation\Response;

final class OrderController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private StockReservationRepository $reservationRepo,
        #[Autowire('%catalogue_cache_csv%')]
        private string $catalogueCacheCsv,
    ) {}

    #[Route('/mes-commandes', name: 'orders_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        return $this->render('orders/index.html.twig');
    }

    #[Route('/ajax/mes-commandes', name: 'ajax_my_orders', methods: ['GET'])]
public function ajaxMyOrders(Request $request, CommandeRepository $repo): JsonResponse
{
    $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

    $user = $this->getUser();

    $page = max(1, (int) $request->query->get('page', 1));
    $limit = min(50, max(2, (int) $request->query->get('limit', 10)));
    $offset = ($page - 1) * $limit;

    $status = trim((string) $request->query->get('status', ''));
    $q = trim((string) $request->query->get('q', ''));

    $total = $repo->countForUser($user, $status, $q);
    $rows  = $repo->findForUser($user, $limit, $offset, $status, $q);

    $orders = [];
    foreach ($rows as $o) {

        // ✅ itemsCount robuste (selon ton mapping)
        $itemsCount = null;
        if (method_exists($o, 'getLignes')) {
            $itemsCount = $o->getLignes()?->count();
        } elseif (method_exists($o, 'getCommandeLignes')) {
            $itemsCount = $o->getCommandeLignes()?->count();
        }

        $orders[] = [
    'id' => $o->getId(),
    'reference' => method_exists($o, 'getReference') ? $o->getReference() : ('CMD-' . $o->getId()),
    'createdAt' => method_exists($o, 'getCreatedAt') && $o->getCreatedAt()
        ? $o->getCreatedAt()->format('d/m/Y H:i')
        : null,
    'status' => method_exists($o, 'getStatus') ? (string) $o->getStatus() : null,
    'totalTtc' => method_exists($o, 'getTotalTtc') ? (float) $o->getTotalTtc() : null,
    'itemsCount' => method_exists($o, 'getItems') ? $o->getItems()->count() : null,

    // ✅ lien détail
    'showUrl' => $this->generateUrl('orders_show', ['id' => $o->getId()]),
];

    }

    return $this->json([
        'ok' => true,
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'pages' => (int) ceil($total / $limit),
        'orders' => $orders,
    ]);
}
#[Route('/mes-commandes/{id}', name: 'orders_show', methods: ['GET'], requirements: ['id' => '\d+'])]
public function show(
    int $id,
    OrderRepository $repo,
    CommandeLigneRepository $ligneRepo
): Response {
    $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
    $user = $this->getUser();

    /** @var Commande|null $order */
    $order = $repo->findOneBy(['id' => $id, 'user' => $user]);
    if (!$order) {
        throw $this->createNotFoundException('Commande introuvable.');
    }

    // ✅ Charge lignes directement (indépendant du mapping OneToMany)
    $lignes = $ligneRepo->findBy(
        ['commande' => $order],
        ['id' => 'ASC']
    );

    return $this->render('orders/show.html.twig', [
        'order' => $order,
        'lignes' => $lignes,
    ]);
}


    #[Route('/order/confirm', name: 'order_confirm', methods: ['POST'])]
    public function confirm(CartService $cart): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        try {
            /** @var \App\Entity\User $user */
            $user = $this->getUser();

            // ✅ lignes panier uniquement (in_cart)
            $items = $cart->getItemsInCart($user);
            if (!$items) {
                return $this->json(['ok' => false, 'message' => 'Votre panier est vide.'], 400);
            }

            // ✅ regroupe par UID (si jamais tu as plusieurs lignes identiques)
            $grouped = []; // uid => ['qty'=>int, 'items'=>CartItem[]]
            foreach ($items as $it) {
                $uid = $it->getUid();
                if (!isset($grouped[$uid])) {
                    $grouped[$uid] = ['qty' => 0, 'items' => []];
                }
                $grouped[$uid]['qty'] += $it->getQuantity();
                $grouped[$uid]['items'][] = $it;
            }

            $uids = array_keys($grouped);

            // ✅ charge infos depuis le CSV cache (name, ht, ttc, stock)
            $catalog = $this->loadCatalogueByUids($uids, $this->catalogueCacheCsv);

            $now = new \DateTimeImmutable();
            $expiresAt = new \DateTimeImmutable('+20 minutes');

            // ✅ Commande
            $cmd = new Commande();
            $cmd->setUser($user);
            $cmd->setReference($this->makeReference14());
            $cmd->setStatus('reserved'); // statut commande (à toi de définir)
            $cmd->setCreatedAt($now);
            $cmd->setUpdatedAt($now);

            $this->em->persist($cmd);

            $totalHt = 0.0;
            $totalTtc = 0.0;

            foreach ($grouped as $uid => $g) {
                $qty = (int) $g['qty'];
                $info = $catalog[$uid] ?? [];

                $name = $info['name'] ?? $uid;
                $puTtc = isset($info['ttc']) && is_numeric($info['ttc']) ? (float) $info['ttc'] : 0.0;
                $puHt  = isset($info['ht'])  && is_numeric($info['ht'])  ? (float) $info['ht']  : 0.0;

                // ✅ contrôle stock (stock CSV - réservations actives)
                if (isset($info['stock']) && is_numeric($info['stock'])) {
                    $stock = (int) $info['stock'];

                    // réservé (actif) par d'autres (non expiré)
                    $reserved = (int) $this->reservationRepo->reservedQtyActive($uid);

                    $available = max(0, $stock - $reserved);
                    if ($qty > $available) {
                        return $this->json([
                            'ok' => false,
                            'message' => "Stock insuffisant pour $uid. Dispo: $available, demandé: $qty"
                        ], 400);
                    }
                }

                $lineHt  = $puHt * $qty;
                $lineTtc = $puTtc * $qty;

                $totalHt  += $lineHt;
                $totalTtc += $lineTtc;

                // ✅ Ligne de commande (1 ligne par UID)
                $l = new CommandeLigne();
                $l->setCommande($cmd);
                $l->setUid($uid);
                $l->setName($name);
                $l->setQuantity($qty);
                $l->setUnitPriceHt($puHt);
                $l->setUnitPriceTtc($puTtc);
                $l->setLineTotalHt($lineHt);
                $l->setLineTotalTtc($lineTtc);
                $this->em->persist($l);

                // ✅ Réservation de stock (IMPORTANT : ton entity a un constructeur)
                $r = new StockReservation($user, $uid, $qty, $expiresAt);
                $r->setCommande($cmd); // lie la commande
                // status par défaut = STATUS_RESERVED, donc pas obligatoire
                // $r->setStatus(StockReservation::STATUS_RESERVED);
                $this->em->persist($r);

                // ✅ on “sort” du panier => in_order
                foreach ($g['items'] as $srcItem) {
                    $srcItem->setStatus(CartItem::STATUS_IN_ORDER);
                }
            }

            $cmd->setTotalHt($totalHt);
            $cmd->setTotalTtc($totalTtc);

            $this->em->flush();

            return $this->json([
                'ok' => true,
                'reference' => $cmd->getReference(),
                'totalHt' => $cmd->getTotalHt(),
                'totalTtc' => $cmd->getTotalTtc(),
            ]);
        } catch (\Throwable $e) {
            return $this->json([
                'ok' => false,
                'message' => 'Erreur serveur: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function makeReference14(): string
    {
        $rand = strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        return 'CMD' . date('ymd') . $rand; // 14 chars
    }

    /**
     * Retour: [uid => ['name'=>string, 'ttc'=>float|null, 'ht'=>float|null, 'stock'=>int|null]]
     */
    private function loadCatalogueByUids(array $uids, string $csvPath): array
    {
        $uids = array_values(array_filter(array_unique($uids)));
        if (!$uids) return [];

        if (!is_file($csvPath)) {
            throw new \RuntimeException("CSV cache introuvable: " . $csvPath);
        }

        $wanted = array_fill_keys($uids, true);
        $out = [];

        $f = new \SplFileObject($csvPath);
        $f->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY);
        $f->setCsvControl(';');

        $headers = null;
        $idx = [];

        foreach ($f as $row) {
            if (!is_array($row) || $row === [null]) continue;

            if ($headers === null) {
                $headers = array_map(fn($h) => trim((string)$h), $row);
                foreach ($headers as $i => $h) {
                    $idx[$h] = $i;
                }
                continue;
            }

            $uid = $this->cell($row, $idx, ['UniqueId', 'Id', 'ID', 'id']);
            if (!$uid || !isset($wanted[$uid])) continue;

            $name  = $this->cell($row, $idx, ['DesComClear', 'Designation']) ?: $uid;
            $ttc   = $this->num($this->cell($row, $idx, ['SalePriceVatIncluded']));
            $ht    = $this->num($this->cell($row, $idx, ['SalePriceVatExcluded']));
            $stock = $this->num($this->cell($row, $idx, ['RealStock'])); // ✅ stock CSV

            $out[$uid] = [
                'name'  => $name,
                'ttc'   => $ttc,
                'ht'    => $ht,
                'stock' => is_numeric($stock) ? (int)$stock : null,
            ];

            unset($wanted[$uid]);
            if (!$wanted) break;
        }

        return $out;
    }

    private function cell(array $row, array $idx, array $keys): string
    {
        foreach ($keys as $k) {
            if (!isset($idx[$k])) continue;
            $v = trim((string)($row[$idx[$k]] ?? ''));
            if ($v !== '') return $v;
        }
        return '';
    }

    private function num(string $v): ?float
    {
        $v = trim($v);
        if ($v === '') return null;
        $v = str_replace([' ', "\u{00A0}"], '', $v);
        $v = str_replace(',', '.', $v);
        $v = preg_replace('/[^0-9.\-]/', '', $v);
        $n = (float)$v;
        return is_finite($n) ? $n : null;
    }
}
