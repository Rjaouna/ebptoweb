<?php

namespace App\Controller\Admin;

use App\Entity\Store;
use App\Repository\StoreRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route('/admin/store')]
final class StoreAdminController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private CsrfTokenManagerInterface $csrf
    ) {}

    #[Route('', name: 'admin_store_index', methods: ['GET'])]
    public function index(StoreRepository $repo): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        // Singleton: si absent => create 1 ligne
        $store = $repo->getSingleton();
        if (!$store) {
            $store = new Store();
            $this->em->persist($store);
            $this->em->flush();
        }

        return $this->render('admin/store/index.html.twig', [
            'storeId'  => $store->getId(),
            'csrfSave' => $this->csrf->getToken('admin_store_save')->getValue(),
            'csrfLogo' => $this->csrf->getToken('admin_store_logo')->getValue(),
        ]);
    }

    #[Route('/ajax/get', name: 'admin_store_ajax_get', methods: ['GET'])]
    public function ajaxGet(StoreRepository $repo): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $store = $repo->getSingleton();
        if (!$store) {
            $store = new Store();
            $this->em->persist($store);
            $this->em->flush();
        }

        return $this->json(['ok' => true, 'store' => $store->toArray()]);
    }

    #[Route('/ajax/save', name: 'admin_store_ajax_save', methods: ['POST'])]
    public function ajaxSave(Request $request, StoreRepository $repo): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $token = (string)($request->request->get('_token') ?: $request->headers->get('X-CSRF-TOKEN'));
        if (!$this->csrf->isTokenValid(new CsrfToken('admin_store_save', $token))) {
            return $this->json(['ok' => false, 'message' => 'CSRF invalide.'], 403);
        }

        $store = $repo->getSingleton();
        if (!$store) {
            $store = new Store();
            $this->em->persist($store);
        }

        // Champs (safe)
        $store->setName((string) $request->request->get('name', $store->getName()));

        $store->setLegalName($this->nullableStr($request->request->get('legalName')));
        $store->setEmail($this->nullableStr($request->request->get('email')));
        $store->setPhone($this->nullableStr($request->request->get('phone')));
        $store->setWebsite($this->nullableStr($request->request->get('website')));

        $store->setAddressLine1($this->nullableStr($request->request->get('addressLine1')));
        $store->setAddressLine2($this->nullableStr($request->request->get('addressLine2')));
        $store->setPostalCode($this->nullableStr($request->request->get('postalCode')));
        $store->setCity($this->nullableStr($request->request->get('city')));
        $store->setRegion($this->nullableStr($request->request->get('region')));
        $store->setCountry($this->nullableStr($request->request->get('country')));

        $store->setIce($this->nullableStr($request->request->get('ice')));
        $store->setVatNumber($this->nullableStr($request->request->get('vatNumber')));
        $store->setRc($this->nullableStr($request->request->get('rc')));
        $store->setIfNumber($this->nullableStr($request->request->get('ifNumber')));

        $store->setCurrency((string) $request->request->get('currency', $store->getCurrency()));
        $store->setLocale((string) $request->request->get('locale', $store->getLocale()));

        $store->touch();
        $this->em->flush();

        return $this->json(['ok' => true, 'store' => $store->toArray()]);
    }

    #[Route('/ajax/logo', name: 'admin_store_ajax_logo', methods: ['POST'])]
    public function ajaxLogo(Request $request, StoreRepository $repo): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $token = (string)($request->request->get('_token') ?: $request->headers->get('X-CSRF-TOKEN'));
        if (!$this->csrf->isTokenValid(new CsrfToken('admin_store_logo', $token))) {
            return $this->json(['ok' => false, 'message' => 'CSRF invalide.'], 403);
        }

        /** @var UploadedFile|null $file */
        $file = $request->files->get('logo');
        if (!$file) {
            return $this->json(['ok' => false, 'message' => 'Fichier manquant.'], 400);
        }

        $mime = (string) $file->getMimeType();
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'], true)) {
            return $this->json(['ok' => false, 'message' => 'Format logo non autorisé (png/jpg/webp/svg).'], 400);
        }

        $store = $repo->getSingleton();
        if (!$store) {
            $store = new Store();
            $this->em->persist($store);
            $this->em->flush();
        }

        $publicDir = $this->getParameter('kernel.project_dir') . '/public';
        $destDir = $publicDir . '/img/store';
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0775, true);
        }

        $ext = strtolower($file->guessExtension() ?: 'png');
        $filename = 'logo.' . $ext;

        $file->move($destDir, $filename);

        $store->setLogoPath('img/store/' . $filename)->touch();
        $this->em->flush();

        return $this->json([
            'ok' => true,
            'logoPath' => $store->getLogoPath(),
            'store' => $store->toArray(),
        ]);
    }

    private function nullableStr(mixed $v): ?string
    {
        if ($v === null) return null;
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }
}
