<?php

namespace App\Controller;

use App\Dto\ProfileData;
use App\Entity\User;
use App\Form\ProfileType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/profile', name: 'profile.')]
final class ProfileController extends AbstractController
{
    #[Route('', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $req,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        UserRepository $userRepository,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $data = ProfileData::fromUser($user);

        $form = $this->createForm(ProfileType::class, $data, [
            'original_email' => $user->getEmail(),
        ]);
        $form->handleRequest($req);

        if ($form->isSubmitted() && $form->isValid()) {
            $existingUser = $userRepository->findOneBy(['email' => $data->email]);

            if ($existingUser !== null && $existingUser->getId() !== $user->getId()) {
                $form->get('email')->addError(new FormError('Cet email est déjà utilisé par un autre compte.'));
            }

            if ($form->isValid()) {
                // Recopie sur l'entité seulement maintenant : la session reste saine en cas d'erreur.
                $user->setEmail($data->email);

                if ('' !== (string) $data->plainPassword) {
                    $user->setPassword($hasher->hashPassword($user, $data->plainPassword));
                }

                $em->flush();
                $this->addFlash('success', 'Profil mis à jour.');

                return $this->redirectToRoute('profile.edit');
            }
        }

        return $this->render('profile/edit.html.twig', [
            'form' => $form,
        ]);
    }
}
