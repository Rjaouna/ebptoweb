<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class ContactController extends AbstractController
{
    #[Route('/contact', name: 'app_contact', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('contact/index.html.twig');
    }

    #[Route('/contact/send', name: 'app_contact_send', methods: ['POST'])]
    public function send(
        Request $request,
        MailerInterface $mailer,
        CsrfTokenManagerInterface $csrf
    ): JsonResponse {
        // 1) CSRF
        $token = (string) $request->request->get('_token', '');
        if (!$csrf->isTokenValid(new CsrfToken('contact_form', $token))) {
            return $this->json(['ok' => false, 'message' => 'Token invalide. Rafraîchis la page.'], 419);
        }

        // 2) Honeypot (anti-spam)
        $hp = trim((string) $request->request->get('website', ''));
        if ($hp !== '') {
            // On ne dit pas "spam" pour ne pas aider les bots
            return $this->json(['ok' => true, 'message' => 'Message reçu.'], 200);
        }

        // 3) Data
        $name = trim((string) $request->request->get('name', ''));
        $email = trim((string) $request->request->get('email', ''));
        $message = trim((string) $request->request->get('message', ''));
        $services = $request->request->all('services'); // array

        // 4) Validation simple
        if ($name === '' || mb_strlen($name) < 2) {
            return $this->json(['ok' => false, 'field' => 'name', 'message' => 'Nom complet obligatoire.'], 422);
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['ok' => false, 'field' => 'email', 'message' => 'Email invalide.'], 422);
        }

        if (!is_array($services) || count($services) < 1) {
            return $this->json(['ok' => false, 'field' => 'services', 'message' => 'Choisis au moins un service.'], 422);
        }

        if ($message === '' || mb_strlen($message) < 10) {
            return $this->json(['ok' => false, 'field' => 'message', 'message' => 'Message trop court (min 10 caractères).'], 422);
        }

        // 5) Email
        // ⚠️ Mets un "from" de ton domaine si possible (sinon certains serveurs bloquent).
        $from = 'no-reply@hopic.ma'; // adapte si besoin
        $to = 'jaouna.ridouane@gmail.com';

        $serviceLabel = implode(', ', array_map('strval', $services));

        $mail = (new Email())
            ->from($from)
            ->replyTo($email)
            ->to($to)
            ->subject('Contact HOPIC — ' . $serviceLabel)
            ->text(
                "Nouveau message depuis le site\n\n" .
                "Nom: {$name}\n" .
                "Email: {$email}\n" .
                "Service(s): {$serviceLabel}\n\n" .
                "Message:\n{$message}\n"
            );

        try {
            $mailer->send($mail);
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'message' => "Erreur d'envoi email. Vérifie MAILER_DSN."], 500);
        }

        return $this->json(['ok' => true, 'message' => 'Message envoyé. Merci !']);
    }
}
