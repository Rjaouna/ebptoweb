<?php

namespace App\Controller;

use App\Entity\Commande;
use App\Entity\CommandeLigne;
use App\Entity\CartItem;
use App\Repository\CartItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class CommandeController extends AbstractController
{
    #[Route('/order/confirm', name: 'order_confirm', methods: ['POST'])]
    public function confirm(
        Request $request,
        EntityManagerInterface $em,
        CartItemRepository $cartItemRepo
    ): JsonResponse {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        if (!$request->isXmlHttpRequest()) {
            return $this->json(['ok' => false, 'message' => 'Requête invalide.'], 400);
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        // 1) Récupérer les lignes panier (in_cart)
        $cartItems = $cartItemRepo->findInCartByUser($user);
        if (!$cartItems) {
            return $this->json(['ok' => false, 'message' => 'Votre panier est vide.'], 400);
        }

        // 2) Charger le CSV en mémoire (indexé par UID)
        $csvPath = $this->getParameter('kernel.project_dir') . '/var/catalogue_cache/items_cache.csv';
        if (!is_file($csvPath)) {
            return $this->json([
                'ok' => false,
                'message' => "CSV introuvable : $csvPath"
            ], 500);
        }

        $catalogueByUid = $this->loadCatalogueIndexByUid($csvPath);

        // 3) Transaction (sécurise si erreur au milieu)
        $em->beginTransaction();
        try {
            $now = new \DateTimeImmutable();

            $order = new Commande();
            $order->setUser($user);

            $reference = 'CMD-' . $now->format('Ymd-His') . '-' . random_int(100, 999);
            $order->setReference($reference);
            $order->setStatus('created');
            $order->setCreatedAt($now);
            $order->setUpdatedAt($now);

            $totalHt = 0.0;
            $totalTtc = 0.0;

            foreach ($cartItems as $ci) {
                $uid = $ci->getUid();
                $qty = $ci->getQuantity();

                $row = $catalogueByUid[$uid] ?? null;

                $name = $row['DesComClear'] ?? $row['Designation'] ?? $uid;

                // Prices du CSV (selon ton fichier)
                $unitHt  = $this->toFloat($row['SalePriceVatExcluded'] ?? null);
                $unitTtc = $this->toFloat($row['SalePriceVatIncluded'] ?? null);

                $lineHt  = (is_finite($unitHt)  ? $unitHt  * $qty : null);
                $lineTtc = (is_finite($unitTtc) ? $unitTtc * $qty : null);

                $ligne = new CommandeLigne();
                $ligne->setCommande($order);
                $ligne->setUid($uid);
                $ligne->setName($name);
                $ligne->setQuantity($qty);

                // Si prix manquant dans CSV => on laisse null
                $ligne->setUnitPriceHt(is_finite($unitHt) ? $unitHt : null);
                $ligne->setUnitPriceTtc(is_finite($unitTtc) ? $unitTtc : null);
                $ligne->setLineTotalHt($lineHt);
                $ligne->setLineTotalTtc($lineTtc);

                $order->addCommandeLigne($ligne);
                $em->persist($ligne);

                if (is_float($lineHt))  $totalHt  += $lineHt;
                if (is_float($lineTtc)) $totalTtc += $lineTtc;

                // 4) Basculer statut du panier => in_order
                $ci->setStatus(CartItem::STATUS_IN_ORDER);
            }

            $order->setTotalHt($totalHt);
            $order->setTotalTtc($totalTtc);

            $em->persist($order);
            $em->flush();
            $em->commit();

            return $this->json([
                'ok' => true,
                'message' => 'Commande créée.',
                'orderId' => $order->getId(),
                'reference' => $order->getReference(),
                'totalHt' => $order->getTotalHt(),
                'totalTtc' => $order->getTotalTtc(),
            ]);
        } catch (\Throwable $e) {
            $em->rollback();

            return $this->json([
                'ok' => false,
                'message' => 'Erreur lors de la création de commande.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Charge le CSV et renvoie un tableau [uid => rowAssoc]
     * - détecte ; ou , si besoin
     */
    private function loadCatalogueIndexByUid(string $csvPath): array
    {
        $rows = $this->readCsvAssoc($csvPath, ';');

        // fallback si on n’a pas de champs
        if (!$rows || (isset($rows[0]) && count($rows[0]) < 5)) {
            $rows = $this->readCsvAssoc($csvPath, ',');
        }

        $map = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;

            $uid = $r['UniqueId'] ?? $r['Id'] ?? $r['ID'] ?? $r['id'] ?? null;
            $uid = is_string($uid) ? trim($uid) : '';

            if ($uid !== '') {
                $map[$uid] = $r;
            }
        }
        return $map;
    }

    private function readCsvAssoc(string $path, string $delimiter): array
    {
        $file = new \SplFileObject($path, 'r');
        $file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY);
        $file->setCsvControl($delimiter);

        $header = null;
        $out = [];

        foreach ($file as $row) {
            if (!is_array($row)) continue;

            // ligne vide
            if (count($row) === 1 && trim((string)$row[0]) === '') continue;

            if ($header === null) {
                $header = array_map(fn($h) => trim((string)$h), $row);
                continue;
            }

            $assoc = [];
            foreach ($header as $i => $key) {
                if ($key === '') continue;
                $assoc[$key] = isset($row[$i]) ? trim((string)$row[$i]) : null;
            }
            $out[] = $assoc;
        }

        return $out;
    }

    private function toFloat($v): float
    {
        $s = trim((string)($v ?? ''));
        if ($s === '') return NAN;

        // 1 234,56 -> 1234.56
        $s = str_replace(["\u{00A0}", "\u{202F}", " "], "", $s);
        $s = str_replace(",", ".", $s);
        $s = preg_replace('/[^0-9.\-]/', '', $s);

        $n = (float)$s;
        return is_finite($n) ? $n : NAN;
    }
}
