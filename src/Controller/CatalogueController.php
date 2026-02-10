<?php

namespace App\Controller;

use App\Security\Encryptor;
use App\Service\CartService;
use App\Entity\FtpConnection;
use App\Repository\FtpConnectionRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class CatalogueController extends AbstractController
{
    #[Route('/catalogue', name: 'catalogue_index', methods: ['GET'])]
    public function index(CartService $cart): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $cartMap = $cart->asUidQtyMap($user);
        $cartCount = array_sum($cartMap);

        return $this->render('catalogue/index.html.twig', [
            '_cart' => $cartMap,
            '_cartCount' => $cartCount,
        ]);
    }

    #[Route('/catalogue/nouveautes', name: 'catalogue_new', methods: ['GET'])]
    public function nouveautes(CartService $cart): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $cartMap = $cart->asUidQtyMap($user);
        $cartCount = array_sum($cartMap);

        return $this->render('catalogue/new.html.twig', [
            '_cart' => $cartMap,
            '_cartCount' => $cartCount,
            'newDays' => 555,
        ]);
    }

    #[Route('/catalogue/csv', name: 'catalogue_csv', methods: ['GET'])]
    public function csv(FtpConnectionRepository $repo, Encryptor $encryptor): Response
    {
        /** @var FtpConnection|null $cfg */
        $cfg = $repo->findOneBy([], ['id' => 'DESC']);
        if (!$cfg) {
            return new Response("Aucune configuration FTP trouvée en base.", 404);
        }

        $host = trim($cfg->getHost());
        $user = trim($cfg->getUsername());
        $port = (int) $cfg->getPort();
        $remoteDir = trim($cfg->getRemoteDir()) ?: '/';
        $csvName = trim($cfg->getCsvName()) ?: 'items.csv';
        $timeoutSec = max(3, (int) ceil(((int)$cfg->getTimeoutMs()) / 1000));
        $secure = (bool) $cfg->isSecure();

        if ($host === '' || $user === '') {
            return new Response("Configuration FTP invalide (host/username vides).", 400);
        }

        $passEnc = $cfg->getPasswordEnc() ?? '';
        $pass = $passEnc !== '' ? $encryptor->decrypt($passEnc) : '';

        $response = new StreamedResponse(function () use (
            $secure, $host, $port, $timeoutSec, $user, $pass, $remoteDir, $csvName
        ) {
            $conn = $secure ? @ftp_ssl_connect($host, $port, $timeoutSec) : @ftp_connect($host, $port, $timeoutSec);
            if (!$conn) { throw new \RuntimeException("Impossible de se connecter au serveur FTP/FTPS."); }

            try {
                @ftp_set_option($conn, FTP_TIMEOUT_SEC, $timeoutSec);

                if (!@ftp_login($conn, $user, $pass)) {
                    throw new \RuntimeException("Login FTP incorrect (username/password).");
                }

                @ftp_pasv($conn, true);

                if ($remoteDir !== '' && $remoteDir !== '/') {
                    if (!@ftp_chdir($conn, $remoteDir)) {
                        throw new \RuntimeException("Dossier distant introuvable: " . $remoteDir);
                    }
                }

                $tmp = tempnam(sys_get_temp_dir(), 'csv_');
                if ($tmp === false) {
                    throw new \RuntimeException("Impossible de créer un fichier temporaire.");
                }

                if (!@ftp_get($conn, $tmp, $csvName, FTP_BINARY)) {
                    @unlink($tmp);
                    throw new \RuntimeException("CSV introuvable ou téléchargement impossible: " . $csvName);
                }

                $fh = fopen($tmp, 'rb');
                if (!$fh) {
                    @unlink($tmp);
                    throw new \RuntimeException("Impossible de lire le CSV téléchargé.");
                }

                while (!feof($fh)) { echo fread($fh, 8192); }

                fclose($fh);
                @unlink($tmp);
            } finally {
                @ftp_close($conn);
            }
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');

        return $response;
    }
}
