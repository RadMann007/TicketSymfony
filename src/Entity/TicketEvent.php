<?php

namespace App\Entity;

use App\Repository\TicketEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TicketEventRepository::class)]
#[ORM\Table(name: 'ticket_event')]
class TicketEvent
{
    public const TYPE_CREATED = 'created';
    public const TYPE_UPDATED = 'updated';
    public const TYPE_STATUS_CHANGED = 'status_changed';
    public const TYPE_ASSIGNED = 'assigned';
    public const TYPE_PRIORITY_CHANGED = 'priority_changed';
    public const TYPE_DUE_DATE_CHANGED = 'due_date_changed';
    public const TYPE_COMMENT_ADDED = 'comment_added';
    public const TYPE_ATTACHMENT_ADDED = 'attachment_added';
    public const TYPE_ATTACHMENT_REMOVED = 'attachment_removed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'events')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Ticket $ticket = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    #[ORM\Column(length: 40)]
    private string $type = self::TYPE_UPDATED;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $field = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $oldValue = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $newValue = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTicket(): ?Ticket
    {
        return $this->ticket;
    }

    public function setTicket(?Ticket $ticket): static
    {
        $this->ticket = $ticket;

        return $this;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function setActor(?User $actor): static
    {
        $this->actor = $actor;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getField(): ?string
    {
        return $this->field;
    }

    public function setField(?string $field): static
    {
        $this->field = $field;

        return $this;
    }

    public function getOldValue(): ?string
    {
        return $this->oldValue;
    }

    public function setOldValue(?string $oldValue): static
    {
        $this->oldValue = $oldValue;

        return $this;
    }

    public function getNewValue(): ?string
    {
        return $this->newValue;
    }

    public function setNewValue(?string $newValue): static
    {
        $this->newValue = $newValue;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function label(): string
    {
        return match ($this->type) {
            self::TYPE_CREATED => 'Ticket créé',
            self::TYPE_STATUS_CHANGED => 'Statut modifié',
            self::TYPE_ASSIGNED => 'Assignation modifiée',
            self::TYPE_PRIORITY_CHANGED => 'Priorité modifiée',
            self::TYPE_DUE_DATE_CHANGED => 'Échéance modifiée',
            self::TYPE_COMMENT_ADDED => 'Commentaire ajouté',
            self::TYPE_ATTACHMENT_ADDED => 'Pièce jointe ajoutée',
            self::TYPE_ATTACHMENT_REMOVED => 'Pièce jointe supprimée',
            default => 'Ticket modifié',
        };
    }

    public static function statusLabel(bool $isDone): string
    {
        return $isDone ? 'Terminé' : 'En cours';
    }
}
