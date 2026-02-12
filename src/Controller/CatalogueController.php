<?php

namespace App\Controller;

use App\Entity\FtpConnection;
use App\Repository\FtpConnectionRepository;
use App\Service\CartService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

class CatalogueController extends AbstractController
{
    /**
     * ✅ Domaine images (tu peux changer)
     * IMPORTANT: pas de "/" à la fin
     */
    private string $catalogueDomain = 'https://catalogue.3skpartsauto.com';

    private function buildImagesBase(): string
    {
        return rtrim($this->catalogueDomain, '/') . '/export_images';
    }

    /**
     * ✅ Chemin du CSV local (same-origin => pas de CORS)
     * Adapte si tu stockes le fichier ailleurs.
     */
    private function getCsvLocalPath(?FtpConnection $cfg): string
    {
        // Exemple actuel: cache local
        return $this->getParameter('kernel.project_dir') . '/var/catalogue_cache/items_cache.csv';

        // Exemple si tu veux plutôt: public/articles/xxx.csv
        // $name = $cfg?->getCsvName() ?: 'items.csv';
        // return $this->getParameter('kernel.project_dir') . '/public/articles/' . $name;
    }

    /**
     * ✅ Parse dates qui existent dans ton CSV:
     * - "Thu Jan 22 2026 13:01:28 GMT+0100 (UTC+01:00)"
     * - "2026-02-10 20:48:06"
     */
    private function parseAnyDate(?string $val): ?\DateTimeImmutable
    {
        $s = trim((string) $val);
        $s = trim($s, "\"'");

        if ($s === '') return null;

        // enlève le suffixe " (UTC+01:00)" etc.
        $s = preg_replace('/\s*\(.*\)\s*$/', '', $s);

        // Format CSV: "Thu Jan 22 2026 13:01:28 GMT+0100"
        if (str_contains($s, 'GMT')) {
            $dt = \DateTimeImmutable::createFromFormat('D M d Y H:i:s \G\M\TO', $s);
            if ($dt instanceof \DateTimeImmutable) return $dt;
        }

        // Format export: "2026-02-10 20:48:06"
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $s);
        if ($dt instanceof \DateTimeImmutable) return $dt;

        // fallback strtotime
        $ts = strtotime($s);
        return ($ts !== false) ? (new \DateTimeImmutable())->setTimestamp($ts) : null;
    }

    private function streamCsvFile(string $path): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($path) {
            $fh = fopen($path, 'rb');
            if (!$fh) return;
            while (!feof($fh)) {
                echo fread($fh, 8192);
            }
            fclose($fh);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/catalogue', name: 'catalogue_index', methods: ['GET'])]
    public function index(FtpConnectionRepository $repo, CartService $cart): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        $user = $this->getUser();

        $cfg = $repo->findOneBy([], ['id' => 'DESC']);

        $cartMap = $cart->asUidQtyMap($user);
        $cartCount = array_sum($cartMap);

        return $this->render('catalogue/index.html.twig', [
            'pageMode'   => 'all',
            'pageTitle'  => 'Catalogue',
            'newDays'    => 5,

            // ✅ CSV local same-origin
            'csvUrl'     => $this->generateUrl('catalogue_csv'),
            'imagesBase' => $this->buildImagesBase(),

            '_cart'      => $cartMap,
            '_cartCount' => $cartCount,

            'csvLocalExists' => file_exists($this->getCsvLocalPath($cfg)),
        ]);
    }

    #[Route('/catalogue/nouveautes', name: 'catalogue_new', methods: ['GET'])]
    public function nouveautes(FtpConnectionRepository $repo, CartService $cart): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        $user = $this->getUser();

        $cfg = $repo->findOneBy([], ['id' => 'DESC']);

        $cartMap = $cart->asUidQtyMap($user);
        $cartCount = array_sum($cartMap);

        // ✅ Par défaut, on filtre sur sysModifiedDate (souvent plus logique que sysCreatedDate)
        // Tu peux changer en sysCreatedDate si tu veux.
        $days = 5;
        $field = 'sysModifiedDate';

        $csvUrl = $this->generateUrl('catalogue_csv_new') . '?days=' . $days . '&field=' . urlencode($field);

        return $this->render('catalogue/new.html.twig', [
            'pageMode'   => 'new',
            'pageTitle'  => 'Nouveautés',
            'newDays'    => $days,

            'csvUrl'     => $csvUrl,
            'imagesBase' => $this->buildImagesBase(),

            '_cart'      => $cartMap,
            '_cartCount' => $cartCount,

            'csvLocalExists' => file_exists($this->getCsvLocalPath($cfg)),
        ]);
    }

    /**
     * ✅ CSV complet (local)
     */
    #[Route('/catalogue/csv', name: 'catalogue_csv', methods: ['GET'])]
    public function csv(FtpConnectionRepository $repo): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $cfg = $repo->findOneBy([], ['id' => 'DESC']);
        if (!$cfg) return new Response("Aucune configuration FTP trouvée en base.", 404);

        $path = $this->getCsvLocalPath($cfg);
        if (!is_file($path)) return new Response("CSV local introuvable.", 404);

        return $this->streamCsvFile($path);
    }

    /**
     * ✅ CSV filtré nouveautés
     * /catalogue/csv/new?days=5&field=sysModifiedDate
     * fields possibles: sysCreatedDate | sysModifiedDate | ExportStartedAt
     */
    #[Route('/catalogue/csv/new', name: 'catalogue_csv_new', methods: ['GET'])]
    public function csvNew(FtpConnectionRepository $repo): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $cfg = $repo->findOneBy([], ['id' => 'DESC']);
        if (!$cfg) return new Response("Aucune configuration FTP trouvée en base.", 404);

        $path = $this->getCsvLocalPath($cfg);
        if (!is_file($path)) return new Response("CSV local introuvable.", 404);

        $days  = max(1, (int) ($_GET['days'] ?? 5));
        $field = strtolower(trim((string) ($_GET['field'] ?? 'sysModifiedDate')));

        // sécurité: whitelist des champs autorisés
        $allowed = ['syscreateddate', 'sysmodifieddate', 'exportstartedat'];
        if (!in_array($field, $allowed, true)) {
            $field = 'sysmodifieddate';
        }

        $cutoff = new \DateTimeImmutable("-{$days} days");

        $response = new StreamedResponse(function () use ($path, $cutoff, $field) {
            $in = new \SplFileObject($path, 'r');
            $in->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY);
            $in->setCsvControl(';', '"', '\\');

            $out = fopen('php://output', 'wb');
            if (!$out) return;

            $header = $in->fgetcsv();
            if (!is_array($header) || count($header) < 2) {
                fclose($out);
                return;
            }

            // index colonne cible (case-insensitive)
            $idx = null;
            foreach ($header as $i => $col) {
                $name = strtolower(trim((string) $col));
                if ($name === $field) { $idx = $i; break; }
            }

            // Toujours renvoyer le header
            fputcsv($out, $header, ';');

            if ($idx === null) {
                fclose($out);
                return;
            }

            while (!$in->eof()) {
                $row = $in->fgetcsv();
                if (!is_array($row) || (count($row) === 1 && $row[0] === null)) continue;

                $dt = $this->parseAnyDate($row[$idx] ?? null);
                if (!$dt) continue;

                if ($dt >= $cutoff) {
                    fputcsv($out, $row, ';');
                }
            }

            fclose($out);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
