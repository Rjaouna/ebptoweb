<?php

namespace App\Doctrine;

use App\Entity\Commande;
use App\Service\CommandeStatusMailer;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

final class CommandeStatusDoctrineSubscriber implements EventSubscriber
{
    /** @var array<int, array{0: Commande, 1: string|null, 2: string|null}> */
    private array $queue = [];

    public function __construct(private CommandeStatusMailer $mailer) {}

    public function getSubscribedEvents(): array
    {
        return [
            Events::preUpdate,
            Events::postUpdate,
            Events::postPersist, // optionnel: si tu veux aussi envoyer lors de création
        ];
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Commande) return;

        if (!$args->hasChangedField('status')) return;

        $old = $args->getOldValue('status');
        $new = $args->getNewValue('status');

        $this->queue[spl_object_id($entity)] = [$entity, $old, $new];
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Commande) return;

        $key = spl_object_id($entity);
        if (!isset($this->queue[$key])) return;

        [$order, $old, $new] = $this->queue[$key];
        unset($this->queue[$key]);

        $this->mailer->sendStatusChanged($order, $old, $new);
    }

    public function postPersist(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Commande) return;

        // Si tu veux envoyer le statut initial dès création
        $status = (string)($entity->getStatus() ?? '');
        if ($status !== '') {
            $this->mailer->sendStatusChanged($entity, null, $status);
        }
    }
}
