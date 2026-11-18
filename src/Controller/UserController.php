<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\UserRolesType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/users', name: 'admin.user.')]
#[IsGranted('ROLE_ADMIN')]
final class UserController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(UserRepository $userRepository): Response
    {
        return $this->render('user/index.html.twig', [
            'users' => $userRepository->findAll(),
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(User $user, EntityManagerInterface $em, Request $req): Response
    {
        $this->denyAccessUnlessGranted('USER_DELETE', $user);

        if ($this->isCsrfTokenValid('delete_user' . $user->getId(), $req->request->get('_token'))) {
            if ($user->getTickets()->count() > 0) {
                $this->addFlash('error', 'Impossible de supprimer un utilisateur qui possède des tickets. Supprimez-les ou réaffectez-les d\'abord.');
            } else {
                $em->remove($user);
                $em->flush();
                $this->addFlash('success', 'Utilisateur supprimé.');
            }
        }

        return $this->redirectToRoute('admin.user.index');
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(User $user, Request $req, EntityManagerInterface $em, UserRepository $userRepository): Response
    {
        $this->denyAccessUnlessGranted('USER_EDIT', $user);

        // ROLE_USER est implicite : on ne garde que les rôles réellement stockés.
        $user->setRoles(array_values(array_intersect($user->getRoles(), ['ROLE_ADMIN'])));

        // Instantané avant soumission : le formulaire va modifier l'entité en mémoire.
        $wasAdmin = in_array('ROLE_ADMIN', $user->getRoles(), true);
        $adminCount = $userRepository->countAdmins();
        $currentUser = $this->getUser();

        $form = $this->createForm(UserRolesType::class, $user);
        $form->handleRequest($req);

        if ($form->isSubmitted() && $form->isValid()) {
            $removesAdminRole = $wasAdmin && !in_array('ROLE_ADMIN', $user->getRoles(), true);
            $error = null;

            if ($removesAdminRole && $currentUser instanceof User && $user->getId() === $currentUser->getId()) {
                $error = 'Vous ne pouvez pas retirer votre propre rôle administrateur.';
            } elseif ($removesAdminRole && $adminCount <= 1) {
                $error = 'Impossible de retirer le dernier administrateur de l\'application.';
            }

            if ($error !== null) {
                $this->addFlash('error', $error);

                // Réaffiche le formulaire avec le rôle encore coché.
                $user->setRoles(['ROLE_ADMIN']);
                $form = $this->createForm(UserRolesType::class, $user);
            } else {
                $em->flush();
                $this->addFlash('success', 'Rôles mis à jour.');

                return $this->redirectToRoute('admin.user.index');
            }
        }

        return $this->render('user/edit.html.twig', [
            'user' => $user,
            'form' => $form,
        ]);
    }
}
