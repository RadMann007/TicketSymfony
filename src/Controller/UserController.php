<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\UserRolesType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/users', name: 'admin.user.')]
final class UserController extends AbstractController
{
    private function denyUnlessAdmin(): ?Response
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            $this->addFlash('error', 'Accès réservé aux administrateurs.');

            return $this->redirectToRoute('ticket.index');
        }

        return null;
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(UserRepository $userRepository): Response
    {
        if ($response = $this->denyUnlessAdmin()) {
            return $response;
        }

        return $this->render('user/index.html.twig', [
            'users' => $userRepository->findAll(),
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(User $user, EntityManagerInterface $em, \Symfony\Component\HttpFoundation\Request $req): Response
    {
        if ($response = $this->denyUnlessAdmin()) {
            return $response;
        }

        if ($user === $this->getUser()) {
            $this->addFlash('error', 'Vous ne pouvez pas supprimer votre propre compte.');
            return $this->redirectToRoute('admin.user.index');
        }

        if ($this->isCsrfTokenValid('delete_user' . $user->getId(), $req->request->get('_token'))) {
            $em->remove($user);
            $em->flush();
            $this->addFlash('success', 'Utilisateur supprimé.');
        }

        return $this->redirectToRoute('admin.user.index');
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(User $user, \Symfony\Component\HttpFoundation\Request $req, EntityManagerInterface $em): Response
    {
        if ($response = $this->denyUnlessAdmin()) {
            return $response;
        }

        $this->denyAccessUnlessGranted('USER_EDIT', $user);

        $form = $this->createForm(UserRolesType::class, $user);
        $form->handleRequest($req);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Rôles mis à jour.');

            return $this->redirectToRoute('admin.user.index');
        }

        return $this->render('user/edit.html.twig', [
            'user' => $user,
            'form' => $form,
        ]);
    }
}
?>