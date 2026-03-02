<?php

namespace App\Controller\Admin;

use App\Service\CsvCatalogueCache;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\File;

final class CsvSourceController extends AbstractController
{
    #[Route('/admin/catalogue/source-csv', name: 'admin_catalogue_source_csv')]
    public function index(Request $request, CsvCatalogueCache $cache): Response
    {
        // Si tu as la sécurité:
        // $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $currentUrl = $cache->getSavedUrl();
        $status = $cache->getStatus();

        $form = $this->createFormBuilder([
                'csvUrl' => $currentUrl,
            ])
            ->add('csvUrl', TextType::class, [
                'label' => 'Lien (URL) du CSV',
                'required' => false,
                'attr' => ['placeholder' => 'https://hopic.ma/.../items.csv'],
            ])
            ->add('csvFile', FileType::class, [
                'label' => 'Ou uploader le CSV (recommandé si prod bloque les connexions sortantes)',
                'required' => false,
                'mapped' => false,
                'constraints' => [
                    new File([
                        'maxSize' => '20M',
                        'mimeTypes' => [
                            'text/plain',
                            'text/csv',
                            'application/csv',
                            'application/vnd.ms-excel',
                            'application/octet-stream',
                        ],
                        'mimeTypesMessage' => 'Veuillez uploader un fichier CSV valide.',
                    ]),
                ],
            ])
            ->getForm();

        $form->handleRequest($request);

        $message = null;
        $ok = null;

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile|null $uploaded */
            $uploaded = $form->get('csvFile')->getData();
            $url = trim((string) $form->get('csvUrl')->getData());

            if (!$uploaded && $url === '') {
                $ok = false;
                $message = "Merci de renseigner une URL OU d’uploader un fichier CSV.";
            } else {
                try {
                    // Priorité à l’upload (100% fiable)
                    if ($uploaded) {
                        $cache->updateCacheFromUploadedFile($uploaded->getPathname());
                        $ok = true;
                        $message = "CSV uploadé et mis en cache avec succès.";
                    } else {
                        // Download depuis URL
                        $cache->updateCacheFromUrl($url, 20);
                        $cache->saveUrl($url);
                        $ok = true;
                        $message = "URL enregistrée + CSV téléchargé et mis en cache avec succès.";
                    }

                    $status = $cache->getStatus();
                } catch (\Throwable $e) {
                    $ok = false;
                    $message = "Erreur: " . $e->getMessage();
                    $status = $cache->getStatus();
                }
            }
        }

        return $this->render('admin/csv_source.html.twig', [
            'form' => $form->createView(),
            'ok' => $ok,
            'message' => $message,
            'status' => $status,
            'cachePath' => $cache->getCachePath(),
        ]);
    }
}