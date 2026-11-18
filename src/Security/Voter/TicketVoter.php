<?php

namespace App\Security\Voter;

use App\Entity\Ticket;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class TicketVoter extends Voter
{
    public const VIEW = 'TICKET_VIEW';
    public const EDIT = 'TICKET_EDIT';
    public const DELETE = 'TICKET_DELETE';
    public const TOGGLE = 'TICKET_TOGGLE';
    public const ASSIGN = 'TICKET_ASSIGN';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE, self::TOGGLE, self::ASSIGN], true)
            && $subject instanceof Ticket;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /** @var Ticket $ticket */
        $ticket = $subject;

        // Admins can do everything, including assigning tickets.
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        // Only admins can change the assignee.
        if (self::ASSIGN === $attribute) {
            return false;
        }

        // The owner can do everything on their own ticket.
        if ($ticket->getOwner() === $user) {
            return true;
        }

        // The assignee can work on the ticket, but cannot delete it.
        if ($ticket->getAssignedTo() === $user) {
            return self::DELETE !== $attribute;
        }

        return false;
    }
}
