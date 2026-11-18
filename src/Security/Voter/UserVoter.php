<?php

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class UserVoter extends Voter
{
    public const EDIT = 'USER_EDIT';
    public const DELETE = 'USER_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::EDIT, self::DELETE], true)
            && $subject instanceof User;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $currentUser = $token->getUser();
        if (!$currentUser instanceof User) {
            return false;
        }

        // Only administrators manage user accounts.
        if (!in_array('ROLE_ADMIN', $currentUser->getRoles(), true)) {
            return false;
        }

        /** @var User $target */
        $target = $subject;

        // An admin cannot delete their own account.
        if (self::DELETE === $attribute) {
            return $target->getId() !== $currentUser->getId();
        }

        return true;
    }
}
