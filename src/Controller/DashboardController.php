<?php

namespace App\Controller;

use App\Entity\Ticket;
use App\Repository\TicketRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/dashboard', name: 'dashboard.')]
#[IsGranted('ROLE_USER')]
final class DashboardController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(TicketRepository $ticketRepository): Response
    {
        $user = $this->getUser();

        return $this->render('dashboard/index.html.twig', [
            'myTotal' => $ticketRepository->countByOwnerAndStatus($user),
            'myOpen' => $ticketRepository->countByOwnerAndStatus($user, false),
            'myDone' => $ticketRepository->countByOwnerAndStatus($user, true),
            'latest' => $ticketRepository->findLatestByOwner($user, 5),
            'globalTotal' => $ticketRepository->countAll(),
            'globalOpen' => $ticketRepository->countByStatus(false),
            'globalDone' => $ticketRepository->countByStatus(true),
            'byOwner' => $ticketRepository->countByOwner(),
        ]);
    }
}
?>