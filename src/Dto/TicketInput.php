<?php

namespace App\Dto;

use App\Enum\TicketPriority;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps JSON accepté par l'API pour créer ou modifier un ticket.
 *
 * Les groupes "create" et "update" distinguent les champs obligatoires à la
 * création des champs optionnels d'une mise à jour partielle.
 */
final class TicketInput
{
    #[Assert\NotBlank(groups: ['create'], message: 'Le nom du ticket est obligatoire.')]
    #[Assert\Length(max: 255, groups: ['create', 'update'], maxMessage: 'Le nom ne peut pas dépasser {{ limit }} caractères.')]
    public ?string $name = null;

    #[Assert\Length(max: 5000, groups: ['create', 'update'], maxMessage: 'Le commentaire ne peut pas dépasser {{ limit }} caractères.')]
    public ?string $comment = null;

    public ?TicketPriority $priority = null;

    #[Assert\Date(groups: ['create', 'update'], message: 'L\'échéance doit être une date au format AAAA-MM-JJ.')]
    public ?string $dueDate = null;

    #[Assert\Positive(groups: ['create', 'update'], message: 'L\'identifiant de l\'assigné doit être positif.')]
    public ?int $assignedToId = null;
}
