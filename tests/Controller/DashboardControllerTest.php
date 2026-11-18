<?php

namespace App\Tests\Controller;

use App\Entity\Ticket;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class DashboardControllerTest extends WebTestCase
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

    private function createUser(string $email, array $roles = []): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setPassword($this->passwordHasher->hashPassword($user, 'password'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createTicket(User $owner, string $name, bool $isDone = false, ?DateTimeImmutable $dueDate = null): Ticket
    {
        $ticket = new Ticket();
        $ticket->setName($name);
        $ticket->setIsDone($isDone);
        $ticket->setCreatedAt(new DateTimeImmutable());
        $ticket->setOwner($owner);
        $ticket->setDueDate($dueDate);

        $this->entityManager->persist($ticket);
        $this->entityManager->flush();

        return $ticket;
    }

    public function testDashboardShowsOverdueAlert(): void
    {
        $user = $this->createUser('alice@test.com');

        $this->createTicket($user, 'Ticket en retard', false, new DateTimeImmutable('-2 days'));
        $this->createTicket($user, 'Retard déjà réglé', true, new DateTimeImmutable('-2 days'));

        $this->client->loginUser($user);
        $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '1 ticket en retard');
        self::assertSelectorTextContains('body', 'Voir les tickets en retard');
    }

    public function testDashboardRedirectsToLoginWhenUnauthenticated(): void
    {
        $this->client->request('GET', '/dashboard');

        self::assertResponseRedirects('/login');
    }

    public function testDashboardShowsOnlyPersonalStatsToRegularUser(): void
    {
        $user = $this->createUser('alice@test.com');
        $other = $this->createUser('bob@test.com');

        $this->createTicket($user, 'Mon ticket ouvert');
        $this->createTicket($user, 'Mon ticket terminé', true);
        $this->createTicket($other, 'Le ticket de Bob');

        $this->client->loginUser($user);
        $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Bonjour, Alice');
        self::assertSelectorTextContains('body', 'Mon ticket ouvert');
        self::assertSelectorTextContains('body', 'Mon ticket terminé');

        // Les données globales et les emails des autres comptes restent réservés aux admins.
        self::assertSelectorTextNotContains('body', 'Activité globale');
        self::assertSelectorTextNotContains('body', 'Tickets par utilisateur');
        self::assertSelectorTextNotContains('body', 'bob@test.com');
    }

    public function testDashboardShowsGlobalStatsToAdmin(): void
    {
        $admin = $this->createUser('admin@test.com', ['ROLE_ADMIN']);
        $other = $this->createUser('bob@test.com');

        $this->createTicket($admin, 'Ticket admin');
        $this->createTicket($other, 'Le ticket de Bob');

        $this->client->loginUser($admin);
        $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Activité globale');
        self::assertSelectorTextContains('body', 'Tickets par utilisateur');
        self::assertSelectorTextContains('body', 'bob@test.com');
    }

    public function testDashboardHandlesUserWithoutTickets(): void
    {
        $user = $this->createUser('empty@test.com');

        $this->client->loginUser($user);
        $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Aucun ticket pour le moment');
    }
}
