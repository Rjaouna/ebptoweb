<?php

namespace App\Controller\Admin;

use App\Entity\HeroSlide;
use App\Form\HeroSlideType;
use App\Repository\HeroSlideRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/hero-slides')]
class HeroSlideAdminController extends AbstractController
{
    #[Route('/', name: 'admin_hero_slide_index', methods: ['GET'])]
    public function index(HeroSlideRepository $repo): Response
    {
        return $this->render('admin/hero_slide/index.html.twig', [
            'slides' => $repo->findBy([], ['pinnedAt' => 'DESC', 'createdAt' => 'DESC']),
        ]);
    }

    #[Route('/new', name: 'admin_hero_slide_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, SluggerInterface $slugger): Response
    {
        $slide = new HeroSlide();
        $form = $this->createForm(HeroSlideType::class, $slide);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            /** @var UploadedFile|null $file */
            $file = $form->get('imageFile')->getData();
            if ($file) {
                $filename = $this->storeSlideImage($file, $slugger);
                $slide->setImage($filename);
            } else {
                $this->addFlash('danger', 'Image obligatoire.');
                return $this->redirectToRoute('admin_hero_slide_new');
            }

            $em->persist($slide);
            $em->flush();

            return $this->redirectToRoute('admin_hero_slide_index');
        }

        return $this->render('admin/hero_slide/form.html.twig', [
            'form' => $form,
            'title' => 'Ajouter un slide',
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_hero_slide_edit', methods: ['GET', 'POST'])]
    public function edit(
        HeroSlide $slide,
        Request $request,
        EntityManagerInterface $em,
        SluggerInterface $slugger
    ): Response {
        $form = $this->createForm(HeroSlideType::class, $slide);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            /** @var UploadedFile|null $file */
            $file = $form->get('imageFile')->getData();
            if ($file) {
                $filename = $this->storeSlideImage($file, $slugger);
                $slide->setImage($filename);
            }

            $em->flush();
            return $this->redirectToRoute('admin_hero_slide_index');
        }

        return $this->render('admin/hero_slide/form.html.twig', [
            'form' => $form,
            'title' => 'Modifier le slide',
            'slide' => $slide,
        ]);
    }

    #[Route('/{id}/bump', name: 'admin_hero_slide_bump', methods: ['POST'])]
    public function bump(HeroSlide $slide, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('bump_slide_'.$slide->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $slide->bump();
        // si tu veux VRAIMENT changer createdAt au lieu de pinnedAt :
        // $slide->setCreatedAt(new \DateTimeImmutable());

        $em->flush();

        return $this->redirectToRoute('admin_hero_slide_index');
    }

    #[Route('/{id}/delete', name: 'admin_hero_slide_delete', methods: ['POST'])]
    public function delete(HeroSlide $slide, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('delete_slide_'.$slide->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $em->remove($slide);
        $em->flush();

        return $this->redirectToRoute('admin_hero_slide_index');
    }

    private function storeSlideImage(UploadedFile $file, SluggerInterface $slugger): string
    {
        $original = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safe = $slugger->slug($original)->lower();
        $ext = $file->guessExtension() ?: 'jpg';
        $filename = $safe.'-'.uniqid().'.'.$ext;

        $targetDir = $this->getParameter('kernel.project_dir') . '/public/img/slides';
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0775, true);
        }

        $file->move($targetDir, $filename);
        return $filename;
    }
}
