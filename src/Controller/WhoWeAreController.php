<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class WhoWeAreController extends AbstractController
{
    #[Route('/who/we/are', name: 'app_who_we_are')]
    public function index(): Response
    {
        return $this->render('who_we_are/index.html.twig', [
            'controller_name' => 'WhoWeAreController',
        ]);
    }
}
