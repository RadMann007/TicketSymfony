<?php

namespace App\Tests\Controller;

use App\Entity\Ticket;
use App\Entity\User;
use App\Repository\TicketRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
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

    private function createUser(string $email = 'user@test.com', string $password = 'password'): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createTicket(User $owner, string $name = 'Ticket test', ?string $comment = null, bool $isDone = false): Ticket
    {
        $ticket = new Ticket();
        $ticket->setName($name);
        $ticket->setIsDone($isDone);
        $ticket->setCreatedAt(new DateTimeImmutable());
        $ticket->setOwner($owner);
        $ticket->setComment($comment);

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

        $this->client->submit($form);

        self::assertResponseRedirects('/');

        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Le ticket a bien été créé !');

        $ticketRepository = static::getContainer()->get(TicketRepository::class);
        $tickets = $ticketRepository->findByOwner($user);
        self::assertCount(1, $tickets);
        self::assertSame('New ticket from test', $tickets[0]->getName());
        self::assertTrue($tickets[0]->isDone());
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

        $this->client->submit($form);

        self::assertResponseRedirects('/');

        $ticketRepository = static::getContainer()->get(TicketRepository::class);
        $updatedTicket = $ticketRepository->find($ticket->getId());
        self::assertSame('Updated name', $updatedTicket->getName());
        self::assertSame('Updated comment', $updatedTicket->getComment());
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
}
