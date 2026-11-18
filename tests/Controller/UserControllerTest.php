<?php

namespace App\Tests\Controller;

use App\Entity\Ticket;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserControllerTest extends WebTestCase
{
    private $client;
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->entityManager->createQuery('DELETE FROM App\Entity\Comment')->execute();
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

    private function storedRoles(int $userId): array
    {
        $json = $this->entityManager->getConnection()->fetchOne(
            'SELECT roles FROM user WHERE id = ?',
            [$userId]
        );

        return json_decode((string) $json, true, 512, \JSON_THROW_ON_ERROR);
    }

    public function testOnlyAdminRoleIsOffered(): void
    {
        $admin = $this->createUser('admin@test.com', ['ROLE_ADMIN']);
        $target = $this->createUser('target@test.com');

        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/admin/users/' . $target->getId() . '/edit');

        self::assertResponseIsSuccessful();

        $checkboxes = $crawler->filter('input[type="checkbox"][name="user_roles[roles][]"]');
        self::assertCount(1, $checkboxes);
        self::assertSame('ROLE_ADMIN', $checkboxes->attr('value'));
    }

    public function testGrantingAdminRoleStoresOnlyThatRole(): void
    {
        $admin = $this->createUser('admin@test.com', ['ROLE_ADMIN']);
        $target = $this->createUser('target@test.com');

        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/admin/users/' . $target->getId() . '/edit');
        $token = $crawler->filter('input[name="user_roles[_token]"]')->attr('value');

        $this->client->request('POST', '/admin/users/' . $target->getId() . '/edit', [
            'user_roles' => [
                'roles' => ['ROLE_ADMIN'],
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/admin/users');

        self::assertSame(['ROLE_ADMIN'], $this->storedRoles($target->getId()));
    }

    public function testRevokingAdminRoleDoesNotStoreRoleUser(): void
    {
        $admin = $this->createUser('admin@test.com', ['ROLE_ADMIN']);
        $target = $this->createUser('target@test.com', ['ROLE_ADMIN']);

        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/admin/users/' . $target->getId() . '/edit');
        $token = $crawler->filter('input[name="user_roles[_token]"]')->attr('value');

        $this->client->request('POST', '/admin/users/' . $target->getId() . '/edit', [
            'user_roles' => [
                'roles' => [],
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/admin/users');

        self::assertSame([], $this->storedRoles($target->getId()));

        $this->entityManager->clear();
        $updated = $this->entityManager->getRepository(User::class)->find($target->getId());
        self::assertSame(['ROLE_USER'], $updated->getRoles());
    }

    public function testAdminCannotDeleteTheirOwnAccount(): void
    {
        $admin = $this->createUser('admin@test.com', ['ROLE_ADMIN']);

        $this->client->loginUser($admin);

        $this->client->request('POST', '/admin/users/' . $admin->getId() . '/delete');

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->entityManager->getRepository(User::class)->find($admin->getId()));
    }

    public function testAdminCanDeleteAnotherAccount(): void
    {
        $admin = $this->createUser('admin@test.com', ['ROLE_ADMIN']);
        $target = $this->createUser('target@test.com');

        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/admin/users');
        $token = $crawler
            ->filter('form[action="/admin/users/' . $target->getId() . '/delete"] input[name="_token"]')
            ->attr('value');

        $this->client->request('POST', '/admin/users/' . $target->getId() . '/delete', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/admin/users');

        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(User::class)->find($target->getId()));
    }

    public function testNonAdminCannotManageAccounts(): void
    {
        $user = $this->createUser('user@test.com');
        $other = $this->createUser('other@test.com');

        $this->client->loginUser($user);

        $this->client->request('GET', '/admin/users');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/admin/users/' . $other->getId() . '/edit');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/admin/users/' . $other->getId() . '/delete');
        self::assertResponseStatusCodeSame(403);
    }

    public function testCannotDeleteUserOwningTickets(): void
    {
        $admin = $this->createUser('admin@test.com', ['ROLE_ADMIN']);
        $target = $this->createUser('target@test.com');

        $ticket = new Ticket();
        $ticket->setName('Ticket du compte cible');
        $ticket->setIsDone(false);
        $ticket->setCreatedAt(new DateTimeImmutable());
        $ticket->setOwner($target);
        $this->entityManager->persist($ticket);
        $this->entityManager->flush();

        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/admin/users');
        $token = $crawler
            ->filter('form[action="/admin/users/' . $target->getId() . '/delete"] input[name="_token"]')
            ->attr('value');

        $this->client->request('POST', '/admin/users/' . $target->getId() . '/delete', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/admin/users');

        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Impossible de supprimer un utilisateur qui possède des tickets');

        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->getRepository(User::class)->find($target->getId()));
    }

    public function testAdminCannotDemoteThemselves(): void
    {
        $admin = $this->createUser('admin@test.com', ['ROLE_ADMIN']);

        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/admin/users/' . $admin->getId() . '/edit');
        $token = $crawler->filter('input[name="user_roles[_token]"]')->attr('value');

        $this->client->request('POST', '/admin/users/' . $admin->getId() . '/edit', [
            'user_roles' => [
                'roles' => [],
                '_token' => $token,
            ],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Vous ne pouvez pas retirer votre propre rôle administrateur.');
        self::assertSame(['ROLE_ADMIN'], $this->storedRoles($admin->getId()));
    }
}
