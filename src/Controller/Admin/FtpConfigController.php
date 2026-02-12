<?php

namespace App\Controller\Admin;

use App\Entity\FtpConnection;
use App\Form\FtpConnectionType;
use App\Security\Encryptor;
use App\Service\FtpTester;
use App\Service\FtpCsvSyncer;
use App\Service\FtpFileChecker;
use App\Service\LocalFileChecker;
use App\Service\FtpCsvPreviewer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormErrorIterator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class FtpConfigController extends AbstractController
{
    #[Route('/admin/integrations/ftp', name: 'admin_ftp_config', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        EntityManagerInterface $em,
        Encryptor $encryptor,
    ): Response {
        // ✅ Une seule config globale
        $repo = $em->getRepository(FtpConnection::class);
        $cfg = $repo->findOneBy([]) ?? new FtpConnection();

        $form = $this->createForm(FtpConnectionType::class, $cfg);
        $form->handleRequest($request);

        // Debug propre : afficher les erreurs si invalide
        if ($form->isSubmitted() && !$form->isValid()) {
            $errors = [];
            /** @var FormErrorIterator $it */
            $it = $form->getErrors(true);
            foreach ($it as $error) {
                $errors[] = $error->getMessage();
            }
            if ($errors) {
                $this->addFlash('danger', 'Formulaire invalide: ' . implode(' | ', array_slice($errors, 0, 6)));
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPass = (string) $form->get('password')->getData();

            // Si password rempli, on le chiffre
            if ($plainPass !== '') {
                $cfg->setPasswordEnc($encryptor->encrypt($plainPass));
            }

            // persist seulement si nouveau
            if (null === $cfg->getId()) {
                $em->persist($cfg);
            }

            $em->flush();

            $this->addFlash('success', 'Configuration enregistrée.');
            return $this->redirectToRoute('admin_ftp_config');
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('admin/ftp_config.html.twig', [
            'form' => $form,
            'cfg' => $cfg,
        ], new Response('', $status));
    }

    /**
     * ✅ Test FTP + (si OK) télécharge le CSV dans public/articles/{csvName}
     */
   #[Route('/admin/integrations/ftp/test', name: 'admin_ftp_test', methods: ['POST'])]
public function test(
    Request $request,
    EntityManagerInterface $em,
    Encryptor $encryptor,
    FtpTester $tester,
    \App\Service\FtpCsvSyncer $syncer,
): JsonResponse {
    $repo = $em->getRepository(FtpConnection::class);
    $cfg = $repo->findOneBy([]); // peut être null

    try { $payload = $request->toArray(); } catch (\Throwable) { $payload = []; }

    $host      = $payload['host'] ?? ($cfg?->getHost() ?? '');
    $port      = $payload['port'] ?? ($cfg?->getPort() ?? 21);
    $username  = $payload['username'] ?? ($cfg?->getUsername() ?? '');
    $secure    = $payload['secure'] ?? ($cfg?->isSecure() ?? true);
    $remoteDir = $payload['remoteDir'] ?? ($cfg?->getRemoteDir() ?? '/');
    $timeoutMs = $payload['timeoutMs'] ?? ($cfg?->getTimeoutMs() ?? 20000);

    $csvName = $payload['csvName'] ?? ($cfg?->getCsvName() ?? 'items.csv');
    $csvName = trim((string)$csvName) ?: 'items.csv';

    $password = $payload['password'] ?? null;
    if (!$password && $cfg && $cfg->getPasswordEnc()) {
        $password = $encryptor->decrypt($cfg->getPasswordEnc());
    }
    $password = (string)($password ?? '');

    // 1) ✅ test
    $result = $tester->test([
        'host' => (string)$host,
        'port' => (int)$port,
        'username' => (string)$username,
        'password' => $password,
        'secure' => (bool)$secure,
        'remoteDir' => (string)$remoteDir,
        'timeoutMs' => (int)$timeoutMs,
    ]);

    // 2) ✅ sync si OK
    if (!empty($result['ok'])) {
        $projectDir = (string) $this->getParameter('kernel.project_dir');
        $cacheDir   = $projectDir . '/var/catalogue_cache';
        $localPath  = $cacheDir . '/items_cache.csv'; // ✅ fichier unique

        $dl = $syncer->download([
            'host' => (string)$host,
            'port' => (int)$port,
            'username' => (string)$username,
            'password' => $password,
            'secure' => (bool)$secure,
            'timeoutMs' => (int)$timeoutMs,
        ], (string)$remoteDir, (string)$csvName, $localPath);

        $result['sync'] = $dl;
    }

    // 3) ✅ save status
    if ($cfg) {
        $cfg->setLastTestOk((bool)($result['ok'] ?? false));
        $cfg->setLastTestAt(new \DateTimeImmutable());
        $cfg->setLastTestMessage((string)($result['message'] ?? ''));
        $em->flush();
    }

    return $this->json($result);
}

    #[Route('/admin/integrations/ftp/check-file', name: 'admin_ftp_check_file', methods: ['GET'])]
    public function checkFile(
        EntityManagerInterface $em,
        Encryptor $encryptor,
        FtpFileChecker $ftpChecker,
        LocalFileChecker $localChecker,
    ): JsonResponse {
        $cfg = $em->getRepository(FtpConnection::class)->findOneBy([]);
        if (!$cfg) {
            return $this->json(['ok' => false, 'message' => 'Config FTP introuvable'], 404);
        }

        $password = $cfg->getPasswordEnc() ? $encryptor->decrypt($cfg->getPasswordEnc()) : '';

        // ✅ exemple : ton fichier local (si tu veux vérifier public/articles)
        $localDir = $this->getParameter('kernel.project_dir') . '/public/articles';

        $local = $localChecker->exists($localDir, $cfg->getCsvName());

        $remote = $ftpChecker->existsRemote(
            [
                'host' => $cfg->getHost(),
                'port' => $cfg->getPort(),
                'username' => $cfg->getUsername(),
                'password' => $password,
                'secure' => $cfg->isSecure(),
                'timeoutMs' => $cfg->getTimeoutMs(),
            ],
            $cfg->getRemoteDir(),
            $cfg->getCsvName()
        );

        return $this->json([
            'ok' => true,
            'local' => $local,
            'remote' => $remote,
        ]);
    }

    #[Route('/admin/integrations/ftp/csv/preview', name: 'admin_ftp_csv_preview', methods: ['GET'])]
    public function csvPreview(
        EntityManagerInterface $em,
        FtpCsvPreviewer $previewer
    ): JsonResponse {
        $cfg = $em->getRepository(FtpConnection::class)->findOneBy([]);
        if (!$cfg) {
            return $this->json(['ok' => false, 'message' => 'Config FTP introuvable.'], 404);
        }

        // delimiter fixe ;
        $result = $previewer->preview($cfg, 5, ';');

        return $this->json($result, !empty($result['ok']) ? 200 : 422);
    }
}
