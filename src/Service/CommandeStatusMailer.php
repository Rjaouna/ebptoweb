<?php

namespace App\Service;

use App\Entity\Commande;
use App\Repository\StoreRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;

final class CommandeStatusMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private StoreRepository $storeRepo,
        private LoggerInterface $logger,
        private string $fromEmail, // injecté via services.yaml
    ) {}

    public function sendStatusChanged(Commande $order, ?string $oldStatus, ?string $newStatus): void
    {
        $oldStatus = (string)($oldStatus ?? '');
        $newStatus = (string)($newStatus ?? '');

        if ($newStatus === '' || $newStatus === $oldStatus) {
            return; // rien à faire
        }

        $store = $this->storeRepo->getSingleton();
        $storeEmail = $store?->getEmail(); // email notifications store
        $clientEmail = $order->getUser()?->getEmail();

        $ref = $order->getReference() ?: ('CMD-' . $order->getId());
        $label = Commande::label($newStatus);

        // Contexte commun
        $ctx = [
            'store' => $store,
            'order' => $order,
            'ref' => $ref,
            'oldStatus' => $oldStatus,
            'newStatus' => $newStatus,
            'statusLabel' => $label,
        ];

        // 1) Email client
        if ($clientEmail) {
            try {
                $email = (new TemplatedEmail())
                    ->from($this->fromEmail)
                    ->to($clientEmail)
                    ->replyTo($storeEmail ?: $this->fromEmail)
                    ->subject("Votre commande {$ref} : {$label}")
                    ->htmlTemplate('emails/order_status_customer.html.twig')
                    ->context($ctx);

                $this->mailer->send($email);
            } catch (\Throwable $e) {
                $this->logger->error('Email client status failed', [
                    'orderId' => $order->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 2) Email Store (notifications)
        if ($storeEmail) {
            try {
                $email = (new TemplatedEmail())
                    ->from($this->fromEmail)
                    ->to($storeEmail)
                    ->replyTo($clientEmail ?: $this->fromEmail)
                    ->subject("[Store] Commande {$ref} → {$label}")
                    ->htmlTemplate('emails/order_status_store.html.twig')
                    ->context($ctx);

                $this->mailer->send($email);
            } catch (\Throwable $e) {
                $this->logger->error('Email store status failed', [
                    'orderId' => $order->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
