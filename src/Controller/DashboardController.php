<?php

namespace App\Controller;

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

        $data = [
            'myTotal' => $ticketRepository->countByOwnerAndStatus($user),
            'myOpen' => $ticketRepository->countByOwnerAndStatus($user, false),
            'myDone' => $ticketRepository->countByOwnerAndStatus($user, true),
            'assignedTotal' => $ticketRepository->countAssignedTo($user),
            'assignedOpen' => $ticketRepository->countAssignedTo($user, false),
            'overdue' => $ticketRepository->countOverdueForUser($user),
            'latest' => $ticketRepository->findLatestForUser($user, 5),
        ];

        // Global figures expose every account: reserved to administrators.
        if ($this->isGranted('ROLE_ADMIN')) {
            $byOwner = $ticketRepository->countByOwner();
            usort($byOwner, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

            $data['globalTotal'] = $ticketRepository->countAll();
            $data['globalOpen'] = $ticketRepository->countByStatus(false);
            $data['globalDone'] = $ticketRepository->countByStatus(true);
            $data['byOwner'] = $byOwner;
        }

        return $this->render('dashboard/index.html.twig', $data);
    }
}
