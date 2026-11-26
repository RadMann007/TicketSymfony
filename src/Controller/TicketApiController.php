<?php

namespace App\Controller;

use App\Dto\TicketInput;
use App\Entity\Ticket;
use App\Entity\User;
use App\Repository\TicketRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\SerializerInterface;

final class TicketApiController extends AbstractController
{
    private const SERIALIZATION_GROUPS = ['groups' => 'getTickets'];

    #[Route('/api/tickets', name: 'allticketsApi', methods: ['GET'])]
    public function getAllTicket(TicketRepository $ticketRepository, SerializerInterface $serializer): JsonResponse
    {
        $user = $this->requireUser();

        // Un admin voit tous les tickets (TICKET_VIEW lui est toujours accordé),
        // les autres seulement ceux dont ils sont propriétaires ou assignés.
        $tickets = $ticketRepository->findVisibleTo($user, seeAll: $this->isGranted('ROLE_ADMIN'));

        return $this->jsonResponse($serializer, $tickets, Response::HTTP_OK);
    }

    #[Route('/api/tickets/{id}', name: 'detailTicketApi', methods: ['GET'])]
    public function getDetailTicket(Ticket $ticket, SerializerInterface $serializer): JsonResponse
    {
        $this->denyAccessUnlessGranted('TICKET_VIEW', $ticket);

        return $this->jsonResponse($serializer, $ticket, Response::HTTP_OK);
    }

    #[Route('/api/tickets/{id}', name: 'deleteTicketApi', methods: ['DELETE'])]
    public function deleteTicketApi(Ticket $ticket, EntityManagerInterface $em): JsonResponse
    {
        $this->denyAccessUnlessGranted('TICKET_DELETE', $ticket);

        $em->remove($ticket);
        $em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/tickets', name: 'createTicketApi', methods: ['POST'])]
    public function createTicketApi(
        #[MapRequestPayload(validationGroups: ['create'])] TicketInput $input,
        SerializerInterface $serializer,
        EntityManagerInterface $em,
        UrlGeneratorInterface $urlGenerator,
    ): JsonResponse {
        $user = $this->requireUser();

        $ticket = new Ticket();
        $ticket->setName((string) $input->name);
        $ticket->setComment($input->comment);
        $ticket->setIsDone(false);
        $ticket->setCreatedAt(new DateTimeImmutable());
        $ticket->setOwner($user);

        $this->applyOptionalFields($input, $ticket, $em);

        $em->persist($ticket);
        $em->flush();

        $location = $urlGenerator->generate('detailTicketApi', ['id' => $ticket->getId()], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->jsonResponse($serializer, $ticket, Response::HTTP_CREATED, ['Location' => $location]);
    }

    #[Route('/api/tickets/{id}', name: 'updateTicketApi', methods: ['PUT', 'PATCH'])]
    public function updateTicketApi(
        #[MapRequestPayload(validationGroups: ['update'])] TicketInput $input,
        Ticket $ticket,
        SerializerInterface $serializer,
        EntityManagerInterface $em,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('TICKET_EDIT', $ticket);

        if (null !== $input->name) {
            $ticket->setName($input->name);
        }

        if (null !== $input->comment) {
            $ticket->setComment($input->comment);
        }

        $this->applyOptionalFields($input, $ticket, $em);

        $em->flush();

        return $this->jsonResponse($serializer, $ticket, Response::HTTP_OK);
    }

    /**
     * Applique les champs optionnels communs à la création et à la mise à jour.
     */
    private function applyOptionalFields(TicketInput $input, Ticket $ticket, EntityManagerInterface $em): void
    {
        if (null !== $input->priority) {
            $ticket->setPriority($input->priority);
        }

        if (null !== $input->dueDate) {
            $ticket->setDueDate(new DateTimeImmutable($input->dueDate));
        }

        if (null !== $input->assignedToId) {
            // Seul un admin peut assigner un ticket.
            $this->denyAccessUnlessGranted('TICKET_ASSIGN', $ticket);

            $assignee = $em->getRepository(User::class)->find($input->assignedToId);
            if (!$assignee instanceof User) {
                throw $this->createNotFoundException('Utilisateur assigné introuvable.');
            }

            $ticket->setAssignedTo($assignee);
        }
    }

    private function requireUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function jsonResponse(SerializerInterface $serializer, mixed $data, int $status, array $headers = []): JsonResponse
    {
        return new JsonResponse(
            $serializer->serialize($data, 'json', self::SERIALIZATION_GROUPS),
            $status,
            $headers,
            true
        );
    }
}
