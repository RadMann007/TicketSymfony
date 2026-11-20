<?php

namespace App\EventListener;

use App\Entity\Attachment;
use App\Entity\Comment;
use App\Entity\Ticket;
use App\Entity\TicketEvent;
use App\Entity\User;
use App\Enum\TicketPriority;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Alimente l'historique des tickets depuis le changeset Doctrine.
 *
 * Toutes les modifications passent par l'EntityManager (web, API, commandes,
 * fixtures…), donc aucun contrôleur ne doit créer les événements à la main.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final readonly class TicketHistoryListener
{
    /**
     * Champs Doctrine suivis => [type d'événement, libellé du champ].
     */
    private const TRACKED_FIELDS = [
        'name' => [TicketEvent::TYPE_UPDATED, 'name'],
        'comment' => [TicketEvent::TYPE_UPDATED, 'comment'],
        'priority' => [TicketEvent::TYPE_PRIORITY_CHANGED, 'priority'],
        'dueDate' => [TicketEvent::TYPE_DUE_DATE_CHANGED, 'due_date'],
        'isDone' => [TicketEvent::TYPE_STATUS_CHANGED, 'status'],
        'assignedTo' => [TicketEvent::TYPE_ASSIGNED, 'assigned_to'],
    ];

    public function __construct(
        private Security $security,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();

        if (!$em instanceof EntityManagerInterface) {
            return;
        }

        $uow = $em->getUnitOfWork();
        $deletions = $uow->getScheduledEntityDeletions();
        $actor = $this->security->getUser();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Ticket) {
                $this->record($em, $uow, $entity, $actor, TicketEvent::TYPE_CREATED, null, null, $entity->getName());

                continue;
            }

            if ($entity instanceof Comment) {
                $ticket = $entity->getTicket();
                if (!$ticket instanceof Ticket || in_array($ticket, $deletions, true)) {
                    continue;
                }

                $this->record($em, $uow, $ticket, $actor, TicketEvent::TYPE_COMMENT_ADDED, 'comment', null, $entity->getContent());

                continue;
            }

            if ($entity instanceof Attachment) {
                $ticket = $entity->getTicket();
                if (!$ticket instanceof Ticket || in_array($ticket, $deletions, true)) {
                    continue;
                }

                $this->record($em, $uow, $ticket, $actor, TicketEvent::TYPE_ATTACHMENT_ADDED, 'attachment', null, $entity->getOriginalName());
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof Ticket || in_array($entity, $deletions, true)) {
                continue;
            }

            foreach ($uow->getEntityChangeSet($entity) as $field => [$oldValue, $newValue]) {
                if (!isset(self::TRACKED_FIELDS[$field])) {
                    continue;
                }

                [$type, $label] = self::TRACKED_FIELDS[$field];

                $this->record(
                    $em,
                    $uow,
                    $entity,
                    $actor,
                    $type,
                    $label,
                    $this->formatValue($field, $oldValue),
                    $this->formatValue($field, $newValue),
                );
            }
        }

        foreach ($deletions as $entity) {
            if (!$entity instanceof Attachment) {
                continue;
            }

            $ticket = $entity->getTicket();
            if (!$ticket instanceof Ticket || in_array($ticket, $deletions, true)) {
                continue;
            }

            $this->record($em, $uow, $ticket, $actor, TicketEvent::TYPE_ATTACHMENT_REMOVED, 'attachment', $entity->getOriginalName(), null);
        }
    }

    private function record(
        EntityManagerInterface $em,
        UnitOfWork $uow,
        Ticket $ticket,
        ?UserInterface $actor,
        string $type,
        ?string $field = null,
        ?string $oldValue = null,
        ?string $newValue = null,
    ): void {
        $event = new TicketEvent();
        $event->setTicket($ticket);
        $event->setType($type);
        $event->setField($field);
        $event->setOldValue($oldValue);
        $event->setNewValue($newValue);

        if ($actor instanceof User) {
            $event->setActor($actor);
        }

        $em->persist($event);
        // Un simple persist() dans onFlush ne suffit pas : il faut calculer le
        // changeset pour que Doctrine planifie l'INSERT dans ce même flush.
        $uow->computeChangeSet($em->getClassMetadata(TicketEvent::class), $event);
    }

    private function formatValue(string $field, mixed $value): ?string
    {
        return match ($field) {
            'priority' => match (true) {
                $value instanceof TicketPriority => $value->label(),
                is_string($value) => TicketPriority::tryFrom($value)?->label(),
                default => null,
            },
            'dueDate' => $value instanceof \DateTimeInterface ? $value->format('d/m/Y') : 'Aucune',
            'isDone' => is_bool($value) ? TicketEvent::statusLabel($value) : null,
            'assignedTo' => $value instanceof User ? $value->getEmail() : 'Non assigné',
            'name', 'comment' => is_string($value) ? $value : null,
            default => null,
        };
    }
}
