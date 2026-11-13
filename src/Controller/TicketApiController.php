<?php

namespace App\Controller;

use App\Repository\TicketRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use App\Entity\Ticket;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

final class TicketApiController extends AbstractController
{
    #[Route('/api/tickets', name: 'allticketsApi', methods: ['GET'])]
    public function getAllTicket(TicketRepository $ticketRepository, SerializerInterface $serializer): JsonResponse
    {
        $ticketList = $ticketRepository->findAll();
        $jsonTicketList = $serializer->serialize($ticketList, 'json', ['groups' => 'getTickets']);

        return new JsonResponse($jsonTicketList, Response::HTTP_OK, [], true);
    }

    #[Route('/api/tickets/{id}', name: 'detailTicketApi', methods: ['GET'])]
    public function getDetailTicket(Ticket $ticket, SerializerInterface $serializer, TicketRepository $rep): JsonResponse
    {
        $jsonTicket = $serializer->serialize($ticket, 'json');
        return new JsonResponse($jsonTicket, Response::HTTP_OK, ['accept' => 'json'], true);
    }

    #[Route('/api/tickets/{id}', name: 'deleteTicketApi', methods: ['DELETE'])]
    public function deleteTicketApi(Ticket $ticket, EntityManagerInterface $em): JsonResponse
    {
        $em->remove($ticket);
        $em->flush()();

        return new JsonResponse([null, Response::HTTP_NO_CONTENT]);
    }

    #[Route('/api/tickets', name: 'createTicketApi', methods: ['POST'])]
    public function createTicketApi(Request $req, SerializerInterface $serializer, EntityManagerInterface $em, UrlGeneratorInterface $urlGenerator): JsonResponse
    {
        $ticket = $serializer->deserialize($req->getContent(), Ticket::class, 'json');
        $em->persist($ticket);
        $em->flush()();

        $jsonTicket = $serializer->serialize($ticket, 'json', ['groups' => 'getTickets']);
        $location = $urlGenerator->generate('detailTicketApi', ['id' => $ticket->getId()], UrlGeneratorInterface::ABSOLUTE_URL);

        return new JsonResponse($jsonTicket, Response::HTTP_CREATED, ['Location' => $location], true);
    }

    public function updateTicketApi(Request $req, SerializerInterface $serializer, Ticket $currentTicket, EntityManagerInterface $em): JsonResponse
    {
        $updateTicket = $serializer->deserialize($req->getContent(), Ticket::class, 'json', [AbstractNormalizer::OBJECT_TO_POPULATE => $currentTicket]);

        $em->persist($updateTicket);
        $em->flush();

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }
}
