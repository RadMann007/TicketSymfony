<?php

namespace App\Tests\Repository;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private UserRepository $repository;
    private UserPasswordHasherInterface $passwordHasher;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->repository = $container->get(UserRepository::class);
        $this->passwordHasher = $container->get(UserPasswordHasherInterface::class);

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

    public function testCountAdminsOnlyCountsAdminAccounts(): void
    {
        self::assertSame(0, $this->repository->countAdmins());

        $this->createUser('admin1@test.com', ['ROLE_ADMIN']);
        $this->createUser('admin2@test.com', ['ROLE_ADMIN']);
        $this->createUser('user@test.com');

        self::assertSame(2, $this->repository->countAdmins());
    }
}
