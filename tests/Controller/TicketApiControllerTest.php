<?php

namespace App\Tests\Controller;

use App\Entity\Ticket;
use App\Entity\User;
use App\Enum\TicketPriority;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class TicketApiControllerTest extends WebTestCase
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

    private function createUser(string $email = 'api@test.com', array $roles = []): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setPassword($this->passwordHasher->hashPassword($user, 'password'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createTicket(User $owner, string $name = 'Ticket API'): Ticket
    {
        $ticket = new Ticket();
        $ticket->setName($name);
        $ticket->setIsDone(false);
        $ticket->setCreatedAt(new \DateTimeImmutable());
        $ticket->setOwner($owner);

        $this->entityManager->persist($ticket);
        $this->entityManager->flush();

        return $ticket;
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private function apiRequest(string $method, string $uri, User $user, ?array $data = null): void
    {
        $server = [
            'PHP_AUTH_USER' => (string) $user->getEmail(),
            'PHP_AUTH_PW' => 'password',
        ];

        if (null === $data) {
            $this->client->request($method, $uri, server: $server);

            return;
        }

        $this->client->jsonRequest($method, $uri, $data, $server);
    }

    public function testApiRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/tickets');

        self::assertResponseStatusCodeSame(401);
    }

    public function testListReturnsOnlyOwnTicketsForRegularUser(): void
    {
        $user = $this->createUser('user@test.com');
        $other = $this->createUser('other@test.com');
        $this->createTicket($user, 'Mon ticket');
        $this->createTicket($other, 'Ticket des autres');

        $this->apiRequest('GET', '/api/tickets', $user);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        $names = array_column($payload, 'name');
        self::assertContains('Mon ticket', $names);
        self::assertNotContains('Ticket des autres', $names);
    }

    public function testAdminSeesEveryTicket(): void
    {
        $admin = $this->createUser('admin@test.com', ['ROLE_ADMIN']);
        $other = $this->createUser('other@test.com');
        $this->createTicket($other, 'Ticket des autres');

        $this->apiRequest('GET', '/api/tickets', $admin);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertContains('Ticket des autres', array_column($payload, 'name'));
    }

    public function testDetailIsForbiddenForOtherUser(): void
    {
        $owner = $this->createUser('owner@test.com');
        $other = $this->createUser('other@test.com');
        $ticket = $this->createTicket($owner);

        $this->apiRequest('GET', '/api/tickets/' . $ticket->getId(), $other);

        self::assertResponseStatusCodeSame(403);
    }

    public function testDeleteIsForbiddenForOtherUser(): void
    {
        $owner = $this->createUser('owner@test.com');
        $other = $this->createUser('other@test.com');
        $ticket = $this->createTicket($owner);

        $this->apiRequest('DELETE', '/api/tickets/' . $ticket->getId(), $other);

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->entityManager->getRepository(Ticket::class)->find($ticket->getId()));
    }

    public function testCreateSetsOwnerDefaultsAndOptionalFields(): void
    {
        $user = $this->createUser('user@test.com');

        $this->apiRequest('POST', '/api/tickets', $user, [
            'name' => 'Ticket créé par API',
            'comment' => 'Depuis l\'API',
            'priority' => 'high',
            'dueDate' => '2026-12-31',
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertResponseHeaderSame('Location', 'http://localhost/api/tickets/' . $this->lastTicketId());

        $this->entityManager->clear();
        $ticket = $this->entityManager->getRepository(Ticket::class)->find($this->lastTicketId());
        self::assertNotNull($ticket);
        self::assertSame('Ticket créé par API', $ticket->getName());
        self::assertSame('Depuis l\'API', $ticket->getComment());
        self::assertSame(TicketPriority::High, $ticket->getPriority());
        self::assertSame('2026-12-31', $ticket->getDueDate()?->format('Y-m-d'));
        self::assertFalse($ticket->isDone());
        self::assertNotNull($ticket->getCreatedAt());
        self::assertSame('user@test.com', $ticket->getOwner()?->getEmail());
    }

    public function testCreateRequiresName(): void
    {
        $user = $this->createUser('user@test.com');

        $this->apiRequest('POST', '/api/tickets', $user, ['comment' => 'Sans nom']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->entityManager->getRepository(Ticket::class)->count([]));
    }

    public function testNonAdminCannotAssign(): void
    {
        $user = $this->createUser('user@test.com');
        $assignee = $this->createUser('assignee@test.com');

        $this->apiRequest('POST', '/api/tickets', $user, [
            'name' => 'Tentative d\'assignation',
            'assignedToId' => $assignee->getId(),
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->entityManager->getRepository(Ticket::class)->count([]));
    }

    public function testAdminCanAssign(): void
    {
        $admin = $this->createUser('admin@test.com', ['ROLE_ADMIN']);
        $assignee = $this->createUser('assignee@test.com');

        $this->apiRequest('POST', '/api/tickets', $admin, [
            'name' => 'Ticket assigné par API',
            'assignedToId' => $assignee->getId(),
        ]);

        self::assertResponseStatusCodeSame(201);

        $this->entityManager->clear();
        $ticket = $this->entityManager->getRepository(Ticket::class)->find($this->lastTicketId());
        self::assertSame('assignee@test.com', $ticket?->getAssignedTo()?->getEmail());
    }

    public function testUpdateChangesTicket(): void
    {
        $owner = $this->createUser('owner@test.com');
        $ticket = $this->createTicket($owner, 'Nom initial');
        $ticketId = $ticket->getId();

        $this->apiRequest('PUT', '/api/tickets/' . $ticketId, $owner, [
            'name' => 'Nom modifié',
            'priority' => 'low',
            'dueDate' => '2027-01-15',
        ]);

        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $updated = $this->entityManager->getRepository(Ticket::class)->find($ticketId);
        self::assertSame('Nom modifié', $updated?->getName());
        self::assertSame(TicketPriority::Low, $updated?->getPriority());
        self::assertSame('2027-01-15', $updated?->getDueDate()?->format('Y-m-d'));
    }

    private function lastTicketId(): int
    {
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        return (int) $payload['id'];
    }
}
