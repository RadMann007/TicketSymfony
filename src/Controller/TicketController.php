<?php

namespace App\Controller;

use App\Entity\Ticket;
use App\Form\TicketType;
use App\Repository\TicketRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TicketController extends AbstractController
{
    #[Route('/', name: 'ticket.index', methods: ['GET'])]
    public function index(TicketRepository $ticketRepository, Request $req, PaginatorInterface $paginator): Response
    {
        $q = $req->query->get('q');

        $pagination = $paginator->paginate(
            $ticketRepository->findByOwner($this->getUser(), $q),
            $req->query->getInt('page', 1),
            10
        );

        return $this->render('ticket/index.html.twig', [
            'pagination' => $pagination,
            'q' => $q,
        ]);
    }

    #[Route('/ticket/new', name: 'ticket.new', methods: ['GET', 'POST'])]
    public function createTicket(EntityManagerInterface $em, Request $req): Response
    {
        $ticket = new Ticket();
        $ticket->setCreatedAt(new DateTimeImmutable());
        $ticket->setIsDone(false);
        $ticket->setOwner($this->getUser());

        $form = $this->createForm(TicketType::class, $ticket);
        $form->handleRequest($req);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($ticket);
            $em->flush();
            $this->addFlash('success', 'Le ticket a bien été créé !');

            return $this->redirectToRoute('ticket.index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('ticket/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/ticket/{id}/edit', name: 'ticket.edit', methods: ['GET', 'POST'])]
    public function editTicket(Ticket $ticket, Request $req, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('TICKET_EDIT', $ticket);

        $form = $this->createForm(TicketType::class, $ticket);
        $form->handleRequest($req);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Le ticket a bien été modifié !');

            return $this->redirectToRoute('ticket.index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('ticket/edit.html.twig', [
            'ticket' => $ticket,
            'form' => $form,
        ]);
    }

    #[Route('/ticket/{id}/delete', name: 'ticket.delete', methods: ['POST'])]
    public function deleteTicket(Ticket $ticket, Request $req, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('TICKET_DELETE', $ticket);

        if ($this->isCsrfTokenValid('delete' . $ticket->getId(), $req->request->get('_token'))) {
                $em->remove($ticket);
                $em->flush();
                $this->addFlash('success', 'Le ticket a bien été supprimé !');
            }

        return $this->redirectToRoute('ticket.index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/ticket/{id}/toggle', name:'ticket.toggle', methods: ['POST'])]
    public function toggleTicket(Ticket $ticket, Request $req, EntityManagerInterface $em):Response
    {
        $this->denyAccessUnlessGranted('TICKET_TOGGLE', $ticket);

        // verifier le CSRF
        if($this->isCsrfTokenValid('toggle' . $ticket->getId(), $req->request->get('_token'))){
            $ticket->setIsDone(!$ticket->isDone());
            $em->flush();
        }

        // redirect
        $q = $req->request->get('_q');
        $params = $q ? ['q' => $q ] : [];

        return $this->redirectToRoute('ticket.index', $params, Response::HTTP_SEE_OTHER);
    }

    #[Route('/ticket/export', name: 'ticket.export', methods: ['GET'])]
    public function exportTickets(TicketRepository $ticketRepository, Request $req): Response
    {
        $q = $req->query->get('q');
        $tickets = $ticketRepository->findByOwner($this->getUser(), $q);

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['ID', 'Nom', 'Statut', 'Commentaire', 'Créé le'], ';', '"', '');

        foreach ($tickets as $ticket) {
            fputcsv($handle, [
                $ticket->getId(),
                $ticket->getName(),
                $ticket->isDone() ? 'Terminé' : 'En cours',
                $ticket->getComment(),
                $ticket->getCreatedAt()?->format('d/m/Y H:i'),
            ], ';', '"', '');
        }

        rewind($handle);
        $csv = "\xEF\xBB\xBF" . stream_get_contents($handle);
        fclose($handle);

        $response = new Response($csv);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="tickets.csv"');

        return $response;
    }
}
