<?php

namespace App\Dto;

use App\Entity\User;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Données du formulaire de profil, recopiées sur User uniquement si tout est valide.
 * Évite de corrompre l'utilisateur stocké dans la session (et donc son identifiant).
 */
final class ProfileData
{
    #[Assert\NotBlank(message: 'L\'email ne peut pas être vide.')]
    #[Assert\Email(message: 'L\'email n\'est pas valide.')]
    #[Assert\Length(max: 180, maxMessage: 'L\'email ne peut pas dépasser {{ limit }} caractères.')]
    public string $email = '';

    /**
     * Validé uniquement via le groupe "current_password" (voir ProfileType).
     */
    #[UserPassword(
        message: 'Veuillez saisir votre mot de passe actuel pour modifier l\'email ou le mot de passe.',
        groups: ['current_password'],
    )]
    public ?string $currentPassword = null;

    public ?string $plainPassword = null;

    public static function fromUser(User $user): self
    {
        $data = new self();
        $data->email = (string) $user->getEmail();

        return $data;
    }

    #[Assert\Callback]
    public function validatePlainPassword(ExecutionContextInterface $context): void
    {
        if (null === $this->plainPassword || '' === $this->plainPassword) {
            return;
        }

        if (mb_strlen($this->plainPassword) < 6) {
            $context->buildViolation('Le mot de passe doit faire au moins {{ limit }} caractères.')
                ->setParameter('{{ limit }}', '6')
                ->atPath('plainPassword')
                ->addViolation();
        }
    }
}
