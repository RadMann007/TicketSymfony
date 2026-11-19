<?php

namespace App\Controller;

use App\Entity\Attachment;
use App\Entity\Comment;
use App\Entity\Ticket;
use App\Entity\TicketEvent;
use App\Entity\User;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\KernelInterface;
use App\Enum\TicketPriority;
use App\Form\CommentType;
use App\Form\TicketType;
use App\Repository\TicketRepository;
use App\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TicketController extends AbstractController
{
    public function __construct(
        private readonly KernelInterface $kernel,
    ) {
    }

    #[Route('/', name: 'ticket.index', methods: ['GET'])]
    public function index(TicketRepository $ticketRepository, Request $req, PaginatorInterface $paginator, UserRepository $userRepository): Response
    {
        $filters = $this->readFilters($req, $userRepository);

        $pagination = $paginator->paginate(
            $ticketRepository->findVisibleTo(
                $this->getUser(),
                $filters['q'],
                $filters['priority'],
                $filters['overdue'],
                $filters['sort'],
                $filters['isDone'],
                $filters['assignee'],
                $filters['unassigned'],
                $filters['owner'],
                $filters['createdFrom'],
                $filters['createdTo'],
            ),
            $req->query->getInt('page', 1),
            10
        );

        $isAdmin = $this->isGranted('ROLE_ADMIN');

        return $this->render('ticket/index.html.twig', [
            'pagination' => $pagination,
            'q' => $filters['q'],
            'priority' => $filters['priority'],
            'overdue' => $filters['overdue'],
            'sort' => $filters['sort'],
            'status' => $filters['status'],
            'assignee' => $filters['assigneeId'],
            'unassigned' => $filters['unassigned'],
            'owner' => $filters['ownerId'],
            'createdFrom' => $filters['createdFrom'],
            'createdTo' => $filters['createdTo'],
            'users' => $isAdmin ? $userRepository->findBy([], ['email' => 'ASC']) : [],
            'isAdmin' => $isAdmin,
            'sortOptions' => [
                TicketRepository::SORT_RECENT => 'Plus récents',
                TicketRepository::SORT_OLDEST => 'Plus anciens',
                TicketRepository::SORT_PRIORITY => 'Priorité',
                TicketRepository::SORT_DUE => 'Échéance',
            ],
        ]);
    }

    #[Route('/ticket/new', name: 'ticket.new', methods: ['GET', 'POST'])]
    public function createTicket(EntityManagerInterface $em, Request $req): Response
    {
        $ticket = new Ticket();
        $ticket->setCreatedAt(new DateTimeImmutable());
        $ticket->setIsDone(false);
        $ticket->setOwner($this->getUser());

        $form = $this->createForm(TicketType::class, $ticket, [
            'allow_assignment' => $this->isGranted('ROLE_ADMIN'),
        ]);
        $form->handleRequest($req);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($ticket);
            $em->flush();

            $user = $this->getUser();
            if ($user instanceof User) {
                $this->recordEvent($em, $ticket, $user, TicketEvent::TYPE_CREATED, null, null, $ticket->getName());
                $em->flush();
            }

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

        /** @var User|null $user */
        $user = $this->getUser();

        $oldName = $ticket->getName();
        $oldComment = $ticket->getComment();
        $oldPriority = $ticket->getPriority()->value;
        $oldDueDate = $ticket->getDueDate()?->format('Y-m-d');
        $oldStatus = $ticket->isDone() ? '1' : '0';
        $oldAssignee = $ticket->getAssignedTo()?->getId();

        $form = $this->createForm(TicketType::class, $ticket, [
            'allow_assignment' => $this->isGranted('TICKET_ASSIGN', $ticket),
        ]);
        $form->handleRequest($req);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($ticket->getName() !== $oldName) {
                $this->recordEvent($em, $ticket, $user, TicketEvent::TYPE_UPDATED, 'name', $oldName, $ticket->getName());
            }
            if ($ticket->getComment() !== $oldComment) {
                $this->recordEvent($em, $ticket, $user, TicketEvent::TYPE_UPDATED, 'comment', $oldComment, $ticket->getComment());
            }
            if ($ticket->getPriority()->value !== $oldPriority) {
                $this->recordEvent($em, $ticket, $user, TicketEvent::TYPE_PRIORITY_CHANGED, 'priority', $oldPriority, $ticket->getPriority()->value);
            }
            $newDueDate = $ticket->getDueDate()?->format('Y-m-d');
            if ($newDueDate !== $oldDueDate) {
                $this->recordEvent($em, $ticket, $user, TicketEvent::TYPE_DUE_DATE_CHANGED, 'due_date', $oldDueDate, $newDueDate);
            }
            $newStatus = $ticket->isDone() ? '1' : '0';
            if ($newStatus !== $oldStatus) {
                $this->recordEvent($em, $ticket, $user, TicketEvent::TYPE_STATUS_CHANGED, 'status', $oldStatus, $newStatus);
            }
            $newAssignee = $ticket->getAssignedTo()?->getId();
            if ($newAssignee !== $oldAssignee) {
                $this->recordEvent($em, $ticket, $user, TicketEvent::TYPE_ASSIGNED, 'assigned_to', (string) $oldAssignee, (string) $newAssignee);
            }

            $em->flush();
            $this->addFlash('success', 'Le ticket a bien été modifié !');

            return $this->redirectToRoute('ticket.edit', ['id' => $ticket->getId()], Response::HTTP_SEE_OTHER);
        }

        $commentForm = $this->createForm(CommentType::class, new Comment(), [
            'action' => $this->generateUrl('ticket.comment', ['id' => $ticket->getId()]),
        ]);

        return $this->render('ticket/edit.html.twig', [
            'ticket' => $ticket,
            'form' => $form,
            'commentForm' => $commentForm,
        ]);
    }

    #[Route('/ticket/{id}/comment', name: 'ticket.comment', methods: ['POST'])]
    public function addComment(Ticket $ticket, Request $req, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('TICKET_VIEW', $ticket);

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $comment = new Comment();
        $comment->setTicket($ticket);
        $comment->setAuthor($user);

        $form = $this->createForm(CommentType::class, $comment, [
            'action' => $this->generateUrl('ticket.comment', ['id' => $ticket->getId()]),
        ]);
        $form->handleRequest($req);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($comment);
            $user = $this->getUser();
            if ($user instanceof User) {
                $this->recordEvent(
                    $em,
                    $ticket,
                    $user,
                    TicketEvent::TYPE_COMMENT_ADDED,
                    'comment',
                    null,
                    $comment->getContent(),
                );
            }
            $em->flush();
            $this->addFlash('success', 'Commentaire ajouté.');
        } else {
            $this->addFlash('error', 'Le commentaire ne peut pas être vide.');
        }

        return $this->redirectToRoute('ticket.edit', ['id' => $ticket->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/ticket/{id}/attachment', name: 'ticket.attachment.upload', methods: ['POST'])]
    public function uploadAttachment(Ticket $ticket, Request $req, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('TICKET_EDIT', $ticket);

        if (!$this->isCsrfTokenValid('attachment' . $ticket->getId(), $req->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('ticket.edit', ['id' => $ticket->getId()], Response::HTTP_SEE_OTHER);
        }

        $uploadedFile = $req->files->get('attachment');
        if (!$uploadedFile instanceof UploadedFile || !$uploadedFile->isValid()) {
            $this->addFlash('error', 'Fichier invalide.');

            return $this->redirectToRoute('ticket.edit', ['id' => $ticket->getId()], Response::HTTP_SEE_OTHER);
        }

        if ($uploadedFile->getSize() > 5 * 1024 * 1024) {
            $this->addFlash('error', 'Fichier trop volumineux (max 5 Mo).');

            return $this->redirectToRoute('ticket.edit', ['id' => $ticket->getId()], Response::HTTP_SEE_OTHER);
        }

        $mimeType = (string) $uploadedFile->getMimeType();
        $size = (int) $uploadedFile->getSize();

        $allowedMimes = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'application/pdf', 'text/plain', 'application/zip', 'application/x-zip-compressed',
        ];
        if (!in_array($mimeType, $allowedMimes, true)) {
            $this->addFlash('error', 'Type de fichier non autorisé.');

            return $this->redirectToRoute('ticket.edit', ['id' => $ticket->getId()], Response::HTTP_SEE_OTHER);
        }

        $uploadDir = $this->kernel->getProjectDir() . '/var/uploads/attachments';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        $storedName = bin2hex(random_bytes(16)) . '.' . ($uploadedFile->guessExtension() ?: 'bin');
        $uploadedFile->move($uploadDir, $storedName);

        $attachment = new Attachment();
        $attachment->setTicket($ticket);
        $user = $this->getUser();
        if ($user instanceof User) {
            $attachment->setUploadedBy($user);
        }
        $attachment->setStoredName($storedName);
        $attachment->setOriginalName($uploadedFile->getClientOriginalName());
        $attachment->setMimeType($mimeType);
        $attachment->setSize($size);

        $em->persist($attachment);

        if ($user instanceof User) {
            $this->recordEvent($em, $ticket, $user, TicketEvent::TYPE_ATTACHMENT_ADDED, 'attachment', null, $attachment->getOriginalName());
        }
        $em->flush();

        $this->addFlash('success', 'Pièce jointe ajoutée.');

        return $this->redirectToRoute('ticket.edit', ['id' => $ticket->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/ticket/attachment/{id}/download', name: 'ticket.attachment.download', methods: ['GET'])]
    public function downloadAttachment(Attachment $attachment): Response
    {
        $ticket = $attachment->getTicket();
        if (!$ticket instanceof Ticket) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted('TICKET_VIEW', $ticket);

        $path = $this->kernel->getProjectDir() . '/var/uploads/attachments/' . $attachment->getStoredName();
        if (!is_file($path)) {
            throw $this->createNotFoundException();
        }

        return $this->file($path, $attachment->getOriginalName(), \Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_ATTACHMENT);
    }

    #[Route('/ticket/attachment/{id}/delete', name: 'ticket.attachment.delete', methods: ['POST'])]
    public function deleteAttachment(Attachment $attachment, Request $req, EntityManagerInterface $em): Response
    {
        $ticket = $attachment->getTicket();
        if (!$ticket instanceof Ticket) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted('TICKET_EDIT', $ticket);

        if ($this->isCsrfTokenValid('attachment_delete' . $attachment->getId(), $req->request->get('_token'))) {
            $path = $this->kernel->getProjectDir() . '/var/uploads/attachments/' . $attachment->getStoredName();
            if (is_file($path)) {
                unlink($path);
            }

            $user = $this->getUser();
            if ($user instanceof User) {
                $this->recordEvent($em, $ticket, $user, TicketEvent::TYPE_ATTACHMENT_REMOVED, 'attachment', $attachment->getOriginalName(), null);
            }

            $em->remove($attachment);
            $em->flush();

            $this->addFlash('success', 'Pièce jointe supprimée.');
        }

        return $this->redirectToRoute('ticket.edit', ['id' => $ticket->getId()], Response::HTTP_SEE_OTHER);
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
            $oldStatus = $ticket->isDone() ? '1' : '0';
            $ticket->setIsDone(!$ticket->isDone());
            $user = $this->getUser();
            if ($user instanceof User) {
                $this->recordEvent(
                    $em,
                    $ticket,
                    $user,
                    TicketEvent::TYPE_STATUS_CHANGED,
                    'status',
                    $oldStatus,
                    $ticket->isDone() ? '1' : '0',
                );
            }
            $em->flush();
        }

        // redirect en conservant les filtres actifs
        $params = [];
        $q = $req->request->get('_q');
        if (is_string($q) && '' !== $q) {
            $params['q'] = $q;
        }

        $priority = $req->request->get('_priority');
        if (is_string($priority) && null !== TicketPriority::tryFrom($priority)) {
            $params['priority'] = $priority;
        }

        if ($req->request->getBoolean('_overdue')) {
            $params['overdue'] = 1;
        }

        $sort = (string) $req->request->get('_tri');
        if (TicketRepository::SORT_RECENT !== $sort && in_array($sort, TicketRepository::SORTS, true)) {
            $params['tri'] = $sort;
        }

        return $this->redirectToRoute('ticket.index', $params, Response::HTTP_SEE_OTHER);
    }

    #[Route('/ticket/export', name: 'ticket.export', methods: ['GET'])]
    public function exportTickets(TicketRepository $ticketRepository, Request $req, UserRepository $userRepository): Response
    {
        $filters = $this->readFilters($req, $userRepository);
        $user = $this->getUser();

        $tickets = $ticketRepository->findVisibleTo(
            $user,
            $filters['q'],
            $filters['priority'],
            $filters['overdue'],
            $filters['sort'],
            $filters['isDone'],
            $filters['assignee'],
            $filters['unassigned'],
            $filters['owner'],
            $filters['createdFrom'],
            $filters['createdTo'],
        );

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['ID', 'Nom', 'Statut', 'Commentaire', 'Créé le', 'Priorité', 'Échéance'], ';', '"', '');

        foreach ($tickets as $ticket) {
            fputcsv($handle, [
                $ticket->getId(),
                $this->escapeSpreadsheetFormula($ticket->getName()),
                $ticket->isDone() ? 'Terminé' : 'En cours',
                $this->escapeSpreadsheetFormula($ticket->getComment()),
                $ticket->getCreatedAt()?->format('d/m/Y H:i'),
                $ticket->getPriority()->label(),
                $ticket->getDueDate()?->format('d/m/Y'),
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

    /**
     * Neutralises CSV/formula injection: a value starting with =, +, -, @ or a
     * control character would be evaluated as a formula by spreadsheet apps.
     */
    private function escapeSpreadsheetFormula(?string $value): string
    {
        $value = (string) $value;

        if ($value !== '' && str_contains("=+-@\t\r", $value[0])) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * @return array{q: ?string, priority: ?TicketPriority, overdue: bool, sort: string, status: string, isDone: ?bool, assignee: ?User, unassigned: bool, assigneeId: ?int, owner: ?User, ownerId: ?int, createdFrom: ?DateTimeImmutable, createdTo: ?DateTimeImmutable}
     */
    private function readFilters(Request $req, UserRepository $userRepository): array
    {
        $priority = $req->query->get('priority');
        $sort = (string) $req->query->get('tri', TicketRepository::SORT_RECENT);
        $status = (string) $req->query->get('status', 'all');

        $isDone = match ($status) {
            'open' => false,
            'done' => true,
            default => null,
        };

        $assigneeId = $req->query->get('assignee');
        $unassigned = 'none' === $assigneeId;
        $assignee = null;
        if (is_string($assigneeId) && ctype_digit($assigneeId)) {
            $assignee = $userRepository->find((int) $assigneeId);
        }

        $ownerId = $req->query->get('owner');
        $owner = null;
        if (is_string($ownerId) && ctype_digit($ownerId)) {
            $owner = $userRepository->find((int) $ownerId);
        }

        $createdFrom = $this->parseDate($req->query->get('from'));
        $createdTo = $this->parseDate($req->query->get('to'));

        return [
            'q' => $req->query->get('q'),
            'priority' => is_string($priority) ? TicketPriority::tryFrom($priority) : null,
            'overdue' => $req->query->getBoolean('overdue'),
            'sort' => in_array($sort, TicketRepository::SORTS, true) ? $sort : TicketRepository::SORT_RECENT,
            'status' => in_array($status, ['all', 'open', 'done'], true) ? $status : 'all',
            'isDone' => $isDone,
            'assignee' => $assignee,
            'unassigned' => $unassigned,
            'assigneeId' => is_string($assigneeId) && ctype_digit($assigneeId) ? (int) $assigneeId : ('none' === $assigneeId ? 'none' : null),
            'owner' => $owner,
            'ownerId' => is_string($ownerId) && ctype_digit($ownerId) ? (int) $ownerId : null,
            'createdFrom' => $createdFrom,
            'createdTo' => $createdTo,
        ];
    }

    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function recordEvent(
        EntityManagerInterface $em,
        Ticket $ticket,
        ?User $actor,
        string $type,
        ?string $field = null,
        ?string $oldValue = null,
        ?string $newValue = null,
    ): void {
        if (!$actor instanceof User) {
            return;
        }

        $event = new TicketEvent();
        $event->setTicket($ticket);
        $event->setActor($actor);
        $event->setType($type);
        $event->setField($field);
        $event->setOldValue($oldValue);
        $event->setNewValue($newValue);

        $em->persist($event);
    }
}
