<?php

namespace App\Tests\Controller;

use App\Entity\Attachment;
use App\Entity\Comment;
use App\Entity\Ticket;
use App\Entity\TicketEvent;
use App\Entity\User;
use App\Enum\TicketPriority;
use App\Repository\AttachmentRepository;
use App\Repository\TicketEventRepository;
use App\Repository\TicketRepository;
use App\Service\AttachmentStorage;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class TicketControllerTest extends WebTestCase
{
    private $client;
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->entityManager->createQuery('DELETE FROM App\Entity\Ticket')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    private function createUser(string $email = 'user@test.com', string $password = 'password', array $roles = []): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createTicket(
        User $owner,
        string $name = 'Ticket test',
        ?string $comment = null,
        bool $isDone = false,
        ?TicketPriority $priority = null,
        ?DateTimeImmutable $dueDate = null,
    ): Ticket {
        $ticket = new Ticket();
        $ticket->setName($name);
        $ticket->setIsDone($isDone);
        $ticket->setCreatedAt(new DateTimeImmutable());
        $ticket->setOwner($owner);
        $ticket->setComment($comment);

        if (null !== $priority) {
            $ticket->setPriority($priority);
        }

        $ticket->setDueDate($dueDate);

        $this->entityManager->persist($ticket);
        $this->entityManager->flush();

        return $ticket;
    }

    public function testIndexRedirectsToLoginWhenUnauthenticated(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/login');
    }

    public function testIndexIsSuccessfulForAuthenticatedUser(): void
    {
        $user = $this->createUser();
        $this->client->loginUser($user);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
    }

    public function testIndexShowsOnlyCurrentUserTickets(): void
    {
        $user = $this->createUser('user@test.com');
        $other = $this->createUser('other@test.com');

        $this->createTicket($user, 'My ticket');
        $this->createTicket($other, 'Other ticket');

        $this->client->loginUser($user);
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'My ticket');
        self::assertSelectorTextNotContains('body', 'Other ticket');
    }

    public function testIndexSearchFiltersTickets(): void
    {
        $user = $this->createUser();
        $this->createTicket($user, 'Printer broken');
        $this->createTicket($user, 'Network issue');

        $this->client->loginUser($user);
        $this->client->request('GET', '/', ['q' => 'Printer']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Printer broken');
        self::assertSelectorTextNotContains('body', 'Network issue');
    }

    public function testIndexFiltersByPriority(): void
    {
        $user = $this->createUser();
        $this->createTicket($user, 'Ticket urgent', null, false, TicketPriority::High);
        $this->createTicket($user, 'Ticket secondaire', null, false, TicketPriority::Low);

        $this->client->loginUser($user);
        $this->client->request('GET', '/', ['priority' => 'high']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Ticket urgent');
        self::assertSelectorTextNotContains('body', 'Ticket secondaire');
    }

    public function testIndexFiltersOverdueTickets(): void
    {
        $user = $this->createUser();
        $this->createTicket($user, 'Ticket en retard', null, false, null, new DateTimeImmutable('-3 days'));
        $this->createTicket($user, 'Ticket à venir', null, false, null, new DateTimeImmutable('+3 days'));
        $this->createTicket($user, 'Retard déjà réglé', null, true, null, new DateTimeImmutable('-3 days'));

        $this->client->loginUser($user);
        $this->client->request('GET', '/', ['overdue' => '1']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Ticket en retard');
        self::assertSelectorTextNotContains('body', 'Ticket à venir');
        self::assertSelectorTextNotContains('body', 'Retard déjà réglé');
    }

    public function testIndexSortsByPriority(): void
    {
        $user = $this->createUser();
        $this->createTicket($user, 'Priorité basse', null, false, TicketPriority::Low);
        $this->createTicket($user, 'Priorité haute', null, false, TicketPriority::High);

        $this->client->loginUser($user);
        $this->client->request('GET', '/', ['tri' => 'priority']);

        self::assertResponseIsSuccessful();

        $ordered = static::getContainer()->get(TicketRepository::class)
            ->findVisibleTo($user, null, null, false, TicketRepository::SORT_PRIORITY);
        self::assertSame(
            ['Priorité haute', 'Priorité basse'],
            array_map(static fn (Ticket $ticket): ?string => $ticket->getName(), $ordered)
        );

        $html = (string) $this->client->getResponse()->getContent();
        self::assertLessThan(
            mb_strpos($html, 'Priorité basse'),
            mb_strpos($html, 'Priorité haute'),
            'Le ticket de priorité haute doit apparaître avant celui de priorité basse.'
        );
    }

    public function testNewGetDisplaysForm(): void
    {
        $user = $this->createUser();
        $this->client->loginUser($user);

        $this->client->request('GET', '/ticket/new');

        self::assertResponseIsSuccessful();
    }

    public function testCreateTicketPersistsAndRedirects(): void
    {
        $user = $this->createUser();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/ticket/new');
        $form = $crawler->selectButton('Ajouter le ticket')->form();
        $form['ticket[name]'] = 'New ticket from test';
        $form['ticket[comment]'] = 'A comment';
        $form['ticket[isDone]'] = '1';
        $form['ticket[priority]']->select('high');
        $form['ticket[dueDate]'] = '2026-12-31';

        $this->client->submit($form);

        self::assertResponseRedirects('/');

        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Le ticket a bien été créé !');

        $ticketRepository = static::getContainer()->get(TicketRepository::class);
        $tickets = $ticketRepository->findByOwner($user);
        self::assertCount(1, $tickets);
        self::assertSame('New ticket from test', $tickets[0]->getName());
        self::assertTrue($tickets[0]->isDone());
        self::assertSame(TicketPriority::High, $tickets[0]->getPriority());
        self::assertSame('2026-12-31', $tickets[0]->getDueDate()?->format('Y-m-d'));
    }

    public function testEditGetDisplaysTicket(): void
    {
        $user = $this->createUser();
        $ticket = $this->createTicket($user, 'Editable ticket');

        $this->client->loginUser($user);
        $this->client->request('GET', '/ticket/' . $ticket->getId() . '/edit');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Editable ticket', (string) $this->client->getResponse()->getContent());
    }

    public function testEditPostUpdatesTicket(): void
    {
        $user = $this->createUser();
        $ticket = $this->createTicket($user, 'Old name');

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/ticket/' . $ticket->getId() . '/edit');
        $form = $crawler->selectButton('Enregistrer les modifications')->form();
        $form['ticket[name]'] = 'Updated name';
        $form['ticket[comment]'] = 'Updated comment';
        $form['ticket[priority]']->select('high');
        $form['ticket[dueDate]'] = '2026-12-24';

        $this->client->submit($form);

        self::assertResponseRedirects('/ticket/' . $ticket->getId() . '/edit');

        $ticketRepository = static::getContainer()->get(TicketRepository::class);
        $updatedTicket = $ticketRepository->find($ticket->getId());
        self::assertSame('Updated name', $updatedTicket->getName());
        self::assertSame('Updated comment', $updatedTicket->getComment());
        self::assertSame(TicketPriority::High, $updatedTicket->getPriority());
        self::assertSame('2026-12-24', $updatedTicket->getDueDate()?->format('Y-m-d'));
    }

    public function testHistoryRecordsHumanReadableValues(): void
    {
        $admin = $this->createUser('admin@test.com', 'password', ['ROLE_ADMIN']);
        $owner = $this->createUser('owner@test.com');
        $ticket = $this->createTicket($owner, 'Ticket avec historique');
        $ticketId = $ticket->getId();

        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/ticket/' . $ticketId . '/edit');
        $form = $crawler->selectButton('Enregistrer les modifications')->form();
        $form['ticket[priority]']->select('high');
        $form['ticket[dueDate]'] = '2027-01-15';
        $form['ticket[assignedTo]']->select((string) $owner->getId());
        $this->client->submit($form);

        self::assertResponseRedirects('/ticket/' . $ticketId . '/edit');

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/');
        $token = $crawler
            ->filter('form[action*="/ticket/' . $ticketId . '/toggle"] input[name="_token"]')
            ->attr('value');
        $this->client->request('POST', '/ticket/' . $ticketId . '/toggle', ['_token' => $token]);

        $events = static::getContainer()->get(TicketEventRepository::class)->findBy(['ticket' => $ticketId]);
        $byType = [];
        foreach ($events as $event) {
            $byType[$event->getType()] = $event;
        }

        self::assertSame('Normale', $byType[TicketEvent::TYPE_PRIORITY_CHANGED]->getOldValue());
        self::assertSame('Haute', $byType[TicketEvent::TYPE_PRIORITY_CHANGED]->getNewValue());

        self::assertSame('Aucune', $byType[TicketEvent::TYPE_DUE_DATE_CHANGED]->getOldValue());
        self::assertSame('15/01/2027', $byType[TicketEvent::TYPE_DUE_DATE_CHANGED]->getNewValue());

        self::assertSame('Non assigné', $byType[TicketEvent::TYPE_ASSIGNED]->getOldValue());
        self::assertSame('owner@test.com', $byType[TicketEvent::TYPE_ASSIGNED]->getNewValue());

        self::assertSame('En cours', $byType[TicketEvent::TYPE_STATUS_CHANGED]->getOldValue());
        self::assertSame('Terminé', $byType[TicketEvent::TYPE_STATUS_CHANGED]->getNewValue());
    }

    public function testDeleteWithValidCsrfDeletesTicket(): void
    {
        $user = $this->createUser();
        $ticket = $this->createTicket($user, 'To delete');

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/');
        $token = $crawler->filter('form[action*="/ticket/' . $ticket->getId() . '/delete"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/ticket/' . $ticket->getId() . '/delete', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/');

        $ticketRepository = static::getContainer()->get(TicketRepository::class);
        self::assertNull($ticketRepository->find($ticket->getId()));
    }

    public function testDeleteWithInvalidCsrfDoesNotDelete(): void
    {
        $user = $this->createUser();
        $ticket = $this->createTicket($user, 'Keep me');

        $this->client->loginUser($user);

        $this->client->request('POST', '/ticket/' . $ticket->getId() . '/delete', [
            '_token' => 'invalid-token',
        ]);

        self::assertResponseRedirects('/');

        $ticketRepository = static::getContainer()->get(TicketRepository::class);
        self::assertNotNull($ticketRepository->find($ticket->getId()));
    }

    public function testEditByNonOwnerIsForbidden(): void
    {
        $owner = $this->createUser('owner@test.com');
        $other = $this->createUser('other@test.com');
        $ticket = $this->createTicket($owner, 'Ticket privé');

        $this->client->loginUser($other);

        $this->client->request('GET', '/ticket/' . $ticket->getId() . '/edit');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/ticket/' . $ticket->getId() . '/edit', [
            'ticket' => [
                'name' => 'Ticket piraté',
                'comment' => 'Modification non autorisée.',
            ],
        ]);
        self::assertResponseStatusCodeSame(403);

        $this->entityManager->clear();
        $updated = $this->entityManager->getRepository(Ticket::class)->find($ticket->getId());
        self::assertSame('Ticket privé', $updated->getName());
    }

    public function testDeleteByNonOwnerIsForbidden(): void
    {
        $owner = $this->createUser('owner@test.com');
        $other = $this->createUser('other@test.com');
        $ticket = $this->createTicket($owner, 'Owner ticket');

        $this->client->loginUser($other);

        $this->client->request('POST', '/ticket/' . $ticket->getId() . '/delete', [
            '_token' => 'dummy',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testToggleWithValidCsrfTogglesStatus(): void
    {
        $user = $this->createUser();
        $ticket = $this->createTicket($user, 'Toggle me', null, false);

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/');
        $token = $crawler->filter('form[action*="/ticket/' . $ticket->getId() . '/toggle"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/ticket/' . $ticket->getId() . '/toggle', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/');

        $ticketRepository = static::getContainer()->get(TicketRepository::class);
        $updatedTicket = $ticketRepository->find($ticket->getId());
        self::assertTrue($updatedTicket->isDone());
    }

    public function testTogglePreservesSearchQuery(): void
    {
        $user = $this->createUser();
        $ticket = $this->createTicket($user, 'Toggle me', null, false);

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/');
        $token = $crawler->filter('form[action*="/ticket/' . $ticket->getId() . '/toggle"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/ticket/' . $ticket->getId() . '/toggle', [
            '_token' => $token,
            '_q' => 'search-term',
        ]);

        self::assertResponseRedirects('/?q=search-term');
    }

    public function testToggleByNonOwnerIsForbidden(): void
    {
        $owner = $this->createUser('owner@test.com');
        $other = $this->createUser('other@test.com');
        $ticket = $this->createTicket($owner, 'Owner ticket');

        $this->client->loginUser($other);

        $this->client->request('POST', '/ticket/' . $ticket->getId() . '/toggle', [
            '_token' => 'dummy',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanAssignTicket(): void
    {
        $admin = $this->createUser('admin@test.com', 'password', ['ROLE_ADMIN']);
        $owner = $this->createUser('owner@test.com');
        $assignee = $this->createUser('assignee@test.com');
        $assigneeId = $assignee->getId();
        $ticket = $this->createTicket($owner, 'Ticket à assigner');

        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/ticket/' . $ticket->getId() . '/edit');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('select[name="ticket[assignedTo]"]'));

        $form = $crawler->selectButton('Enregistrer les modifications')->form();
        $form['ticket[assignedTo]']->select((string) $assigneeId);
        $this->client->submit($form);

        self::assertResponseRedirects('/ticket/' . $ticket->getId() . '/edit');

        $this->entityManager->clear();
        $updated = $this->entityManager->getRepository(Ticket::class)->find($ticket->getId());
        self::assertNotNull($updated->getAssignedTo());
        self::assertSame($assigneeId, $updated->getAssignedTo()->getId());
    }

    public function testOwnerDoesNotSeeAssignmentField(): void
    {
        $owner = $this->createUser('owner@test.com');
        $ticket = $this->createTicket($owner, 'Ticket non assignable');

        $this->client->loginUser($owner);

        $crawler = $this->client->request('GET', '/ticket/' . $ticket->getId() . '/edit');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('select[name="ticket[assignedTo]"]'));
    }

    public function testAssigneeSeesTicketAndCanToggleIt(): void
    {
        $owner = $this->createUser('owner@test.com');
        $assignee = $this->createUser('assignee@test.com');
        $ticket = $this->createTicket($owner, 'Ticket assigné', null, false);
        $ticket->setAssignedTo($assignee);
        $this->entityManager->flush();

        $this->client->loginUser($assignee);

        $crawler = $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Ticket assigné');

        $token = $crawler->filter('form[action*="/ticket/' . $ticket->getId() . '/toggle"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/ticket/' . $ticket->getId() . '/toggle', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/');

        $this->entityManager->clear();
        $updated = $this->entityManager->getRepository(Ticket::class)->find($ticket->getId());
        self::assertTrue($updated->isDone());
    }

    public function testAssigneeCannotDeleteTicket(): void
    {
        $owner = $this->createUser('owner@test.com');
        $assignee = $this->createUser('assignee@test.com');
        $ticket = $this->createTicket($owner, 'Ticket assigné');
        $ticket->setAssignedTo($assignee);
        $this->entityManager->flush();

        $this->client->loginUser($assignee);

        $this->client->request('POST', '/ticket/' . $ticket->getId() . '/delete', [
            '_token' => 'dummy',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testOwnerCanAddComment(): void
    {
        $owner = $this->createUser();
        $ticket = $this->createTicket($owner, 'Ticket commenté');

        $this->client->loginUser($owner);

        $crawler = $this->client->request('GET', '/ticket/' . $ticket->getId() . '/edit');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Aucun commentaire pour le moment.');

        $form = $crawler->selectButton('Publier')->form();
        $form['comment[content]'] = 'Un premier commentaire.';
        $this->client->submit($form);

        self::assertResponseRedirects('/ticket/' . $ticket->getId() . '/edit');

        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Un premier commentaire.');
        self::assertSelectorTextContains('body', 'Commentaire ajouté.');
    }

    public function testNonOwnerCannotAddComment(): void
    {
        $owner = $this->createUser('owner@test.com');
        $other = $this->createUser('other@test.com');
        $ticket = $this->createTicket($owner, 'Ticket privé');

        $this->client->loginUser($other);

        $this->client->request('POST', '/ticket/' . $ticket->getId() . '/comment', [
            'comment' => ['content' => 'Je ne devrais pas pouvoir.'],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testEmptyCommentIsRejected(): void
    {
        $owner = $this->createUser();
        $ticket = $this->createTicket($owner, 'Ticket sans commentaire');

        $this->client->loginUser($owner);

        $crawler = $this->client->request('GET', '/ticket/' . $ticket->getId() . '/edit');
        $token = $crawler->filter('form[name="comment"] input[name="comment[_token]"]')->attr('value');

        $this->client->request('POST', '/ticket/' . $ticket->getId() . '/comment', [
            'comment' => [
                'content' => '',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/ticket/' . $ticket->getId() . '/edit');

        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Le commentaire ne peut pas être vide.');

        self::assertSame(0, $this->entityManager->getRepository(Comment::class)->count([]));
    }

    public function testExportNeutralizesSpreadsheetFormulas(): void
    {
        $owner = $this->createUser();
        $malicious = $this->createTicket($owner, "=cmd|' /C calc'!A0", '@SUM(1+1)');
        $normal = $this->createTicket($owner, 'Ticket normal', 'Commentaire normal');
        $dated = $this->createTicket(
            $owner,
            'Ticket planifié',
            null,
            false,
            TicketPriority::High,
            new DateTimeImmutable('2026-12-31'),
        );

        $this->client->loginUser($owner);
        $this->client->request('GET', '/ticket/export');

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/csv', (string) $this->client->getResponse()->headers->get('Content-Type'));

        $csv = (string) $this->client->getResponse()->getContent();

        $rows = [];
        foreach (preg_split('/\r\n|\n/', trim($csv)) as $i => $line) {
            if ($i === 0) {
                continue; // header
            }

            $row = str_getcsv($line, ';', '"', '');
            $rows[(int) $row[0]] = $row;
        }

        $maliciousRow = $rows[$malicious->getId()];
        self::assertSame("'=cmd|' /C calc'!A0", $maliciousRow[1]);
        self::assertSame("'@SUM(1+1)", $maliciousRow[3]);

        $normalRow = $rows[$normal->getId()];
        self::assertSame('Ticket normal', $normalRow[1]);
        self::assertSame('Commentaire normal', $normalRow[3]);
        self::assertSame('Normale', $normalRow[5]);
        self::assertSame('', $normalRow[6]);

        $datedRow = $rows[$dated->getId()];
        self::assertSame('Haute', $datedRow[5]);
        self::assertSame('31/12/2026', $datedRow[6]);
    }

    public function testUploadAndDeleteAttachment(): void
    {
        $user = $this->createUser();
        $ticket = $this->createTicket($user, 'Ticket avec pièce jointe');

        $this->client->loginUser($user);

        $tmp = sys_get_temp_dir() . '/note.txt';
        file_put_contents($tmp, 'hello world');

        $crawler = $this->client->request('GET', '/ticket/' . $ticket->getId() . '/edit');
        $form = $crawler->selectButton('Envoyer')->form();
        $form['attachment_upload[file]']->upload($tmp);
        $this->client->submit($form);

        self::assertResponseRedirects('/ticket/' . $ticket->getId() . '/edit');

        $attachmentRepository = static::getContainer()->get(AttachmentRepository::class);
        $attachments = $attachmentRepository->findBy(['ticket' => $ticket]);
        self::assertCount(1, $attachments);
        self::assertSame('note.txt', $attachments[0]->getOriginalName());

        $storage = static::getContainer()->get(AttachmentStorage::class);
        $storedPath = $storage->path($attachments[0]->getStoredName());
        self::assertFileExists($storedPath);

        $this->client->request('GET', '/ticket/attachment/' . $attachments[0]->getId() . '/download');
        self::assertResponseIsSuccessful();

        $crawler = $this->client->request('GET', '/ticket/' . $ticket->getId() . '/edit');
        $token = $crawler
            ->filter('form[action*="/ticket/attachment/' . $attachments[0]->getId() . '/delete"] input[name="_token"]')
            ->attr('value');

        $this->client->request('POST', '/ticket/attachment/' . $attachments[0]->getId() . '/delete', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/ticket/' . $ticket->getId() . '/edit');
        self::assertCount(0, $attachmentRepository->findBy(['ticket' => $ticket]));
        self::assertFileDoesNotExist($storedPath);
    }

    public function testDeletingTicketRemovesAttachmentFiles(): void
    {
        $user = $this->createUser();
        $ticket = $this->createTicket($user, 'Ticket à supprimer avec fichier');
        $ticketId = $ticket->getId();

        $this->client->loginUser($user);

        $tmp = sys_get_temp_dir() . '/facture.txt';
        file_put_contents($tmp, 'facture');

        $crawler = $this->client->request('GET', '/ticket/' . $ticketId . '/edit');
        $form = $crawler->selectButton('Envoyer')->form();
        $form['attachment_upload[file]']->upload($tmp);
        $this->client->submit($form);

        self::assertResponseRedirects('/ticket/' . $ticketId . '/edit');

        $attachmentRepository = static::getContainer()->get(AttachmentRepository::class);
        $attachment = $attachmentRepository->findOneBy(['ticket' => $ticket]);
        self::assertNotNull($attachment);

        $storage = static::getContainer()->get(AttachmentStorage::class);
        $storedPath = $storage->path($attachment->getStoredName());
        self::assertFileExists($storedPath);

        $crawler = $this->client->request('GET', '/');
        $token = $crawler
            ->filter('form[action*="/ticket/' . $ticketId . '/delete"] input[name="_token"]')
            ->attr('value');

        $this->client->request('POST', '/ticket/' . $ticketId . '/delete', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/');
        self::assertNull(static::getContainer()->get(TicketRepository::class)->find($ticketId));
        self::assertFileDoesNotExist($storedPath);
    }

    public function testAttachmentDownloadAndDeleteForbiddenForOtherUser(): void
    {
        $owner = $this->createUser('owner@test.com');
        $other = $this->createUser('other@test.com');
        $ticket = $this->createTicket($owner, 'Ticket avec pièce jointe');

        $tmp = sys_get_temp_dir() . '/note.txt';
        file_put_contents($tmp, 'hello world');

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/ticket/' . $ticket->getId() . '/edit');
        $form = $crawler->selectButton('Envoyer')->form();
        $form['attachment_upload[file]']->upload($tmp);
        $this->client->submit($form);
        self::assertResponseRedirects('/ticket/' . $ticket->getId() . '/edit');

        $attachment = static::getContainer()->get(AttachmentRepository::class)->findOneBy(['ticket' => $ticket]);
        self::assertNotNull($attachment);

        $this->client->loginUser($other);

        $this->client->request('GET', '/ticket/attachment/' . $attachment->getId() . '/download');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/ticket/attachment/' . $attachment->getId() . '/delete');
        self::assertResponseStatusCodeSame(403);
    }

    public function testUploadRejectsDisallowedFileType(): void
    {
        $user = $this->createUser();
        $ticket = $this->createTicket($user, 'Ticket avec upload refusé');

        $tmp = sys_get_temp_dir() . '/page.html';
        file_put_contents($tmp, '<script>alert(1)</script>');

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/ticket/' . $ticket->getId() . '/edit');
        $form = $crawler->selectButton('Envoyer')->form();
        $form['attachment_upload[file]']->upload($tmp);
        $this->client->submit($form);

        self::assertResponseRedirects('/ticket/' . $ticket->getId() . '/edit');

        $attachments = static::getContainer()->get(AttachmentRepository::class)->findBy(['ticket' => $ticket]);
        self::assertCount(0, $attachments);
    }

    public function testHistoryRecordsCommentEvent(): void
    {
        $user = $this->createUser();
        $ticket = $this->createTicket($user, 'Ticket historique commentaire');

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/ticket/' . $ticket->getId() . '/edit');
        $form = $crawler->selectButton('Publier')->form();
        $form['comment[content]'] = 'Premier commentaire.';
        $this->client->submit($form);

        self::assertResponseRedirects('/ticket/' . $ticket->getId() . '/edit');

        $events = static::getContainer()->get(TicketEventRepository::class)->findBy(['ticket' => $ticket->getId()]);
        $types = array_map(static fn (TicketEvent $e): string => $e->getType(), $events);
        self::assertContains(TicketEvent::TYPE_COMMENT_ADDED, $types);

        $commentEvent = null;
        foreach ($events as $event) {
            if (TicketEvent::TYPE_COMMENT_ADDED === $event->getType()) {
                $commentEvent = $event;
            }
        }
        self::assertNotNull($commentEvent);
        self::assertSame('Premier commentaire.', $commentEvent->getNewValue());
    }

    public function testIndexFiltersByStatus(): void
    {
        $user = $this->createUser();
        $this->createTicket($user, 'Ticket ouvert', null, false);
        $this->createTicket($user, 'Ticket terminé', null, true);

        $this->client->loginUser($user);
        $this->client->request('GET', '/', ['status' => 'done']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Ticket terminé');
        self::assertSelectorTextNotContains('body', 'Ticket ouvert');
    }

    public function testIndexFiltersUnassignedTickets(): void
    {
        $user = $this->createUser();
        $assignee = $this->createUser('assignee@test.com');
        $this->createTicket($user, 'Ticket non assigné');
        $assigned = $this->createTicket($user, 'Ticket assigné');
        $assigned->setAssignedTo($assignee);
        $this->entityManager->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/', ['assignee' => 'none']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Ticket non assigné');
        self::assertSelectorTextNotContains('body', 'Ticket assigné');
    }

    public function testIndexFiltersByCreatedDateRange(): void
    {
        $user = $this->createUser();
        $early = $this->createTicket($user, 'Ticket ancien');
        $early->setCreatedAt(new DateTimeImmutable('2026-01-10 12:00:00'));
        $late = $this->createTicket($user, 'Ticket récent');
        $late->setCreatedAt(new DateTimeImmutable('2026-06-15 12:00:00'));
        $this->entityManager->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/', ['from' => '2026-06-01', 'to' => '2026-06-30']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Ticket récent');
        self::assertSelectorTextNotContains('body', 'Ticket ancien');
    }

    public function testAdminCanFilterByOwner(): void
    {
        $admin = $this->createUser('admin@test.com', 'password', ['ROLE_ADMIN']);
        $ownerA = $this->createUser('ownerA@test.com');
        $ownerB = $this->createUser('ownerB@test.com');

        $this->createTicket($ownerA, 'Ticket de A');
        $this->createTicket($ownerB, 'Ticket de B');

        $this->client->loginUser($admin);
        $this->client->request('GET', '/', ['owner' => $ownerA->getId()]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Ticket de A');
        self::assertSelectorTextNotContains('body', 'Ticket de B');
    }
}
