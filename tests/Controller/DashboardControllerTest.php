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

    private function createTicket(
        User $owner,
        string $name,
        bool $isDone = false,
        ?DateTimeImmutable $dueDate = null,
        ?DateTimeImmutable $createdAt = null,
        ?DateTimeImmutable $completedAt = null,
    ): Ticket {
        $ticket = new Ticket();
        $ticket->setName($name);
        $ticket->setCreatedAt($createdAt ?? new DateTimeImmutable());
        $ticket->setIsDone($isDone);

        if (null !== $completedAt) {
            $ticket->setCompletedAt($completedAt);
        }

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
        self::assertSelectorTextContains('#analytics', 'Aucun ticket terminé pour le moment.');
        self::assertSelectorTextContains('#analytics', 'Aucun ticket en cours.');
    }

    public function testDashboardShowsResolutionAnalytics(): void
    {
        $user = $this->createUser('alice@test.com');
        $now = new DateTimeImmutable();

        $this->createTicket(
            $user,
            'Terminé en quatre jours',
            true,
            dueDate: $now->modify('-5 days'),
            createdAt: $now->modify('-10 days'),
            completedAt: $now->modify('-6 days'),
        );

        $this->client->loginUser($user);
        $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#analytics', 'Temps moyen de résolution');
        self::assertSelectorTextContains('#analytics', '4,0 jours');
        self::assertSelectorTextContains('#analytics', '100%');
        self::assertSelectorTextContains('#analytics', 'Sur 1 ticket terminé');
    }

    public function testDashboardAnalyticsAreScopedToVisibleTickets(): void
    {
        $user = $this->createUser('alice@test.com');
        $other = $this->createUser('bob@test.com');
        $now = new DateTimeImmutable();

        $this->createTicket($user, 'Le mien', true, createdAt: $now->modify('-4 days'), completedAt: $now->modify('-2 days'));
        $this->createTicket($other, 'Celui de Bob', true, createdAt: $now->modify('-40 days'), completedAt: $now->modify('-10 days'));

        $this->client->loginUser($user);
        $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#analytics', '2,0 jours');
        self::assertSelectorTextNotContains('#analytics', '30,0 jours');
    }

    public function testAdminSeesGlobalAnalytics(): void
    {
        $admin = $this->createUser('admin@test.com', ['ROLE_ADMIN']);
        $other = $this->createUser('bob@test.com');
        $now = new DateTimeImmutable();

        $this->createTicket($admin, 'Ticket admin', true, createdAt: $now->modify('-3 days'), completedAt: $now->modify('-2 days'));
        $this->createTicket($other, 'Ticket de Bob', true, createdAt: $now->modify('-9 days'), completedAt: $now->modify('-1 day'));

        $this->client->loginUser($admin);
        $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#analytics', "Sur l'ensemble des tickets");
        self::assertSelectorTextContains('#analytics', '4,5 jours');
        self::assertSelectorTextContains('#analytics', 'Sur 2 tickets terminés');
    }
}
