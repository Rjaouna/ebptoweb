<?php

namespace App\Controller;

use App\Repository\HeroSlideRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(HeroSlideRepository $heroSlideRepository): Response
    {
        $slides = $heroSlideRepository->findForHomepage();

        return $this->render('home/index.html.twig', [
            'slides' => $slides,
        ]);
    }
}
