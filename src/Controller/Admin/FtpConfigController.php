<?php

namespace App\Controller\Admin;

use App\Entity\FtpConnection;
use App\Form\FtpConnectionType;
use App\Security\Encryptor;
use App\Service\FtpTester;
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
				$this->addFlash('danger', 'Formulaire invalide: ' . implode(' | ', array_slice($errors, 0, 4)));
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

	#[Route('/admin/integrations/ftp/test', name: 'admin_ftp_test', methods: ['POST'])]
	public function test(
		Request $request,
		EntityManagerInterface $em,
		Encryptor $encryptor,
		FtpTester $tester,
	): JsonResponse {
		$repo = $em->getRepository(FtpConnection::class);
		$cfg = $repo->findOneBy([]); // peut être null si jamais enregistré

		// payload JSON (si erreur parsing -> payload vide)
		try {
			$payload = $request->toArray();
		} catch (\Throwable) {
			$payload = [];
		}

		$host = $payload['host'] ?? ($cfg?->getHost() ?? '');
		$port = $payload['port'] ?? ($cfg?->getPort() ?? 21);
		$username = $payload['username'] ?? ($cfg?->getUsername() ?? '');
		$secure = $payload['secure'] ?? ($cfg?->isSecure() ?? true);
		$remoteDir = $payload['remoteDir'] ?? ($cfg?->getRemoteDir() ?? '/');
		$timeoutMs = $payload['timeoutMs'] ?? ($cfg?->getTimeoutMs() ?? 20000);

		// password: si envoyé, on le prend. sinon on prend celui stocké.
		$password = $payload['password'] ?? null;
		if (!$password && $cfg && $cfg->getPasswordEnc()) {
			$password = $encryptor->decrypt($cfg->getPasswordEnc());
		}
		$password = (string) ($password ?? '');

		$result = $tester->test([
			'host' => (string) $host,
			'port' => (int) $port,
			'username' => (string) $username,
			'password' => $password,
			'secure' => (bool) $secure,
			'remoteDir' => (string) $remoteDir,
			'timeoutMs' => (int) $timeoutMs,
		]);

		// Sauvegarder l’état du test si une config existe
		if ($cfg) {
			$cfg->setLastTestOk((bool) $result['ok']);
			$cfg->setLastTestAt(new \DateTimeImmutable());
			$cfg->setLastTestMessage((string) ($result['message'] ?? ''));
			$em->flush();
		}

		return $this->json($result);
	}
}
