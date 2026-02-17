<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class TestMailController extends AbstractController
{
    #[Route('/test-mail', name: 'app_test_mail', methods: ['GET'])]
    public function sendTestMail(MailerInterface $mailer): Response
    {
        $from = $_ENV['APP_MAIL_FROM'] ?? 'no-reply@hopic.ma';

        $email = (new Email())
            ->from($from)
            ->to('jaouna.ridouane@gmail.com')
            ->subject('Test Mail Symfony')
            ->text("Salut Ridouane,\n\nCeci est un mail de test envoyé depuis Symfony.\n\nOK ✅");

        try {
            $mailer->send($email);

            return new Response('✅ Mail envoyé à jaouna.ridouane@gmail.com');
        } catch (\Throwable $e) {
            return new Response('❌ Erreur envoi mail : ' . $e->getMessage(), 500);
        }
    }
}
