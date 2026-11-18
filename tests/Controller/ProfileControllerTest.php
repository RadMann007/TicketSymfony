<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ProfileControllerTest extends WebTestCase
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

    private function createUser(string $email = 'user@test.com', string $password = 'password'): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function getFormToken(): string
    {
        $crawler = $this->client->request('GET', '/profile');

        return $crawler->filter('input[name="profile[_token]"]')->attr('value');
    }

    /**
     * @param array{email?: string, currentPassword?: string, plainPassword?: string} $values
     */
    private function submitProfile(string $token, array $values): void
    {
        $plainPassword = $values['plainPassword'] ?? '';

        $this->client->request('POST', '/profile', [
            'profile' => [
                'email' => $values['email'] ?? 'user@test.com',
                'currentPassword' => $values['currentPassword'] ?? '',
                'plainPassword' => [
                    'first' => $plainPassword,
                    'second' => $plainPassword,
                ],
                '_token' => $token,
            ],
        ]);
    }

    private function assertStillLoggedIn(): void
    {
        $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
    }

    public function testCannotChangeEmailWithoutCurrentPassword(): void
    {
        $user = $this->createUser('alice@test.com');
        $this->client->loginUser($user);

        $this->submitProfile($this->getFormToken(), [
            'email' => 'nouvelle@test.com',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'mot de passe actuel');

        $this->entityManager->clear();
        self::assertSame(
            'alice@test.com',
            $this->entityManager->getRepository(User::class)->find($user->getId())->getEmail()
        );

        $this->assertStillLoggedIn();
    }

    public function testCanChangeEmailWithCurrentPassword(): void
    {
        $user = $this->createUser('alice@test.com');
        $this->client->loginUser($user);

        $this->submitProfile($this->getFormToken(), [
            'email' => 'nouvelle@test.com',
            'currentPassword' => 'password',
        ]);

        self::assertResponseRedirects('/profile');

        $this->entityManager->clear();
        self::assertSame(
            'nouvelle@test.com',
            $this->entityManager->getRepository(User::class)->find($user->getId())->getEmail()
        );

        $this->assertStillLoggedIn();
    }

    public function testCannotChangePasswordWithoutCurrentPassword(): void
    {
        $user = $this->createUser('alice@test.com');
        $oldHash = $user->getPassword();
        $this->client->loginUser($user);

        $this->submitProfile($this->getFormToken(), [
            'email' => 'alice@test.com',
            'plainPassword' => 'nouveaupassword',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'mot de passe actuel');

        $this->entityManager->clear();
        self::assertSame(
            $oldHash,
            $this->entityManager->getRepository(User::class)->find($user->getId())->getPassword()
        );
    }

    public function testCannotSaveEmptyEmail(): void
    {
        $user = $this->createUser('alice@test.com');
        $this->client->loginUser($user);

        $this->submitProfile($this->getFormToken(), [
            'email' => '',
            'currentPassword' => 'password',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'ne peut pas être vide');

        $this->entityManager->clear();
        self::assertSame(
            'alice@test.com',
            $this->entityManager->getRepository(User::class)->find($user->getId())->getEmail()
        );

        $this->assertStillLoggedIn();
    }

    public function testCannotTakeAnotherAccountEmail(): void
    {
        $user = $this->createUser('alice@test.com');
        $this->createUser('bob@test.com');
        $this->client->loginUser($user);

        $this->submitProfile($this->getFormToken(), [
            'email' => 'bob@test.com',
            'currentPassword' => 'password',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'déjà utilisé');

        $this->entityManager->clear();
        self::assertSame(
            'alice@test.com',
            $this->entityManager->getRepository(User::class)->find($user->getId())->getEmail()
        );

        $this->assertStillLoggedIn();
    }

    public function testCanChangePasswordWithCurrentPassword(): void
    {
        $user = $this->createUser('alice@test.com');
        $this->client->loginUser($user);

        $this->submitProfile($this->getFormToken(), [
            'email' => 'alice@test.com',
            'currentPassword' => 'password',
            'plainPassword' => 'nouveaupassword',
        ]);

        self::assertResponseRedirects('/profile');

        $this->entityManager->clear();
        $updated = $this->entityManager->getRepository(User::class)->find($user->getId());
        self::assertTrue($this->passwordHasher->isPasswordValid($updated, 'nouveaupassword'));
    }
}
