<?php

namespace App\Controller\Admin;

use App\Service\CsvCatalogueCache;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CsvSourceController extends AbstractController
{
    #[Route('/admin/catalogue/csv-source', name: 'admin_csv_source')]
    public function index(Request $request, CsvCatalogueCache $cache): Response
    {
        // si tu as la sécurité : $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $current = $cache->getSavedUrl();

        $form = $this->createFormBuilder(['csvUrl' => $current])
            ->add('csvUrl', TextType::class, [
                'label' => 'URL du fichier CSV',
                'required' => true,
                'attr' => ['placeholder' => 'https://exemple.com/items.csv'],
            ])
            ->getForm();

        $form->handleRequest($request);

        $message = null;
        $ok = null;

        if ($form->isSubmitted() && $form->isValid()) {
            $url = (string) $form->get('csvUrl')->getData();

            try {
                $cache->saveUrl($url);
                $cache->updateCacheFromUrl($url, 20);

                $ok = true;
                $message = 'URL enregistrée et CSV mis en cache avec succès.';
            } catch (\Throwable $e) {
                $ok = false;
                $message = 'Erreur: ' . $e->getMessage();
            }
        }

        return $this->render('admin/csv_source.html.twig', [
            'form' => $form->createView(),
            'ok' => $ok,
            'message' => $message,
            'cachePath' => $cache->getCachePath(),
        ]);
    }
}