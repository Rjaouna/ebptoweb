<?php

namespace App\Doctrine\Listener;

use App\Entity\Commande;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

final class CommandeMailListener
{
    /** @var int[] */
    private array $createdIds = [];

    /** @var array<int, array{old:string,new:string}> */
    private array $statusChanges = [];

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly Environment $twig,
        private readonly string $fromEmail,
        private readonly string $fromName,
        private readonly string $storeEmail,
    ) {}

    public function postPersist(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Commande) return;

        $id = $entity->getId();
        if ($id) $this->createdIds[] = $id;
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Commande) return;

        if (!$args->hasChangedField('status')) return;

        $id = $entity->getId();
        if (!$id) return;

        $old = (string) $args->getOldValue('status');
        $new = (string) $args->getNewValue('status');

        $this->statusChanges[$id] = ['old' => $old, 'new' => $new];
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if (!$this->createdIds && !$this->statusChanges) return;

        $em = $args->getObjectManager();
        $repo = $em->getRepository(Commande::class);

        $created = $this->createdIds;
        $changes = $this->statusChanges;

        $this->createdIds = [];
        $this->statusChanges = [];

        foreach ($created as $id) {
            $order = $repo->find($id);
            if (!$order instanceof Commande) continue;

            try {
                $this->sendCreatedEmails($order);
            } catch (\Throwable $e) {
                $this->logger->error('Order created email failed', [
                    'orderId' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        foreach ($changes as $id => $chg) {
            $order = $repo->find($id);
            if (!$order instanceof Commande) continue;

            try {
                $this->sendStatusEmails($order, $chg['old'], $chg['new']);
            } catch (\Throwable $e) {
                $this->logger->error('Order status email failed', [
                    'orderId' => $id,
                    'old' => $chg['old'],
                    'new' => $chg['new'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function sendCreatedEmails(Commande $order): void
    {
        $ref = $order->getReference() ?: ('CMD-' . $order->getId());
        $subject = "Commande reçue : {$ref}";
        $from = new Address($this->fromEmail, $this->fromName);

        $customerEmail = $order->getUser()?->getEmail();

        // ✅ Variables communes templates
        $ctx = [
            'order' => $order,
            'ref' => $ref,
            'status' => $order->getStatus() ?: Commande::STATUS_RESERVED,
            'statusLabel' => Commande::label($order->getStatus() ?: Commande::STATUS_RESERVED),
            'oldStatus' => null,
            'oldStatusLabel' => null,
            'customerName' => $order->getCustomerName() ?: ($customerEmail ?: 'Client'),
            'itemsCount' => $order->getCommandeLignes()->count(),
            'totalTtc' => $order->getTotalTtc(),
            'currency' => 'MAD',
        ];

        // client
        if ($customerEmail) {
            $html = $this->twig->render('emails/order_created_customer.html.twig', $ctx);
            $this->mailer->send(
                (new Email())
                    ->from($from)
                    ->to($customerEmail)
                    ->subject($subject)
                    ->html($html)
            );
        }

        // store
        if ($this->storeEmail) {
            $html = $this->twig->render('emails/order_created_store.html.twig', $ctx);
            $this->mailer->send(
                (new Email())
                    ->from($from)
                    ->to($this->storeEmail)
                    ->subject("[STORE] {$subject}")
                    ->html($html)
            );
        }
    }

    private function sendStatusEmails(Commande $order, string $old, string $new): void
    {
        $ref = $order->getReference() ?: ('CMD-' . $order->getId());
        $subject = "Statut commande {$ref} : " . Commande::label($new);
        $from = new Address($this->fromEmail, $this->fromName);

        $customerEmail = $order->getUser()?->getEmail();

        $ctx = [
            'order' => $order,
            'ref' => $ref,
            'status' => $new,
            'statusLabel' => Commande::label($new),
            'oldStatus' => $old,
            'oldStatusLabel' => $old ? Commande::label($old) : null,
            'customerName' => $order->getCustomerName() ?: ($customerEmail ?: 'Client'),
            'itemsCount' => $order->getCommandeLignes()->count(),
            'totalTtc' => $order->getTotalTtc(),
            'currency' => 'MAD',
        ];

        // client
        if ($customerEmail) {
            $html = $this->twig->render('emails/order_status_customer.html.twig', $ctx);
            $this->mailer->send(
                (new Email())
                    ->from($from)
                    ->to($customerEmail)
                    ->subject($subject)
                    ->html($html)
            );
        }

        // store
        if ($this->storeEmail) {
            $html = $this->twig->render('emails/order_status_store.html.twig', $ctx);
            $this->mailer->send(
                (new Email())
                    ->from($from)
                    ->to($this->storeEmail)
                    ->subject("[STORE] {$subject}")
                    ->html($html)
            );
        }
    }
}
