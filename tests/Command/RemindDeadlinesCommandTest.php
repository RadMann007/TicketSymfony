<?php

namespace App\Tests\Command;

use App\Entity\Ticket;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;

final class RemindDeadlinesCommandTest extends KernelTestCase
{
    use MailerAssertionsTrait;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $this->entityManager->createQuery('DELETE FROM App\Entity\Ticket')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testRemindsOwnerAndAssigneeOfOverdueAndTomorrowTickets(): void
    {
        $owner = $this->createUser('owner@test.com');
        $assignee = $this->createUser('assignee@test.com');

        $this->createTicket($owner, 'Ticket en retard', new DateTimeImmutable('today -3 days'), assignedTo: $assignee);
        $this->createTicket($owner, 'Ticket pour demain', new DateTimeImmutable('tomorrow'));

        $this->executeReminderCommand();

        // En retard : propriétaire puis assigné ; demain : propriétaire seul.
        self::assertEmailCount(3);

        $emails = self::getMailerMessages();
        $recipients = array_map(
            static fn (Email $email): string => $email->getTo()[0]->getAddress(),
            $emails
        );
        self::assertSame(['owner@test.com', 'assignee@test.com', 'owner@test.com'], $recipients);

        $subjects = array_map(static fn (Email $email): string => (string) $email->getSubject(), $emails);
        self::assertStringContainsString('Ticket en retard', $subjects[0]);
        self::assertStringContainsString('échéance dépassée de 3 jour(s)', $subjects[0]);
        self::assertStringContainsString('échéance dans 1 jour(s)', $subjects[2]);

        $overdueEmail = $emails[0];
        self::assertSame('no-reply@ticket.local', $overdueEmail->getFrom()[0]->getAddress());
        self::assertEmailHtmlBodyContains($overdueEmail, 'Ticket en retard');
        self::assertEmailHtmlBodyContains($overdueEmail, '3 jour(s)');
    }

    public function testDoesNotRemindFutureDoneOrDueTodayTickets(): void
    {
        $owner = $this->createUser('owner@test.com');

        $this->createTicket($owner, 'Dans trois jours', new DateTimeImmutable('today +3 days'));
        $this->createTicket($owner, 'Déjà terminé', new DateTimeImmutable('today -1 day'), isDone: true);
        $this->createTicket($owner, 'Échéance aujourd\'hui', new DateTimeImmutable('today'));
        $this->createTicket($owner, 'Sans échéance', null);

        $tester = $this->executeReminderCommand();

        self::assertEmailCount(0);
        self::assertStringContainsString('Aucun rappel', $tester->getDisplay());
    }

    public function testSendsOnlyOneEmailWhenAssigneeIsOwner(): void
    {
        $owner = $this->createUser('owner@test.com');

        $this->createTicket($owner, 'Ticket en retard', new DateTimeImmutable('today -1 day'), assignedTo: $owner);

        $this->executeReminderCommand();

        self::assertEmailCount(1);
    }

    private function createUser(string $email): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setPassword('password');

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createTicket(
        User $owner,
        string $name,
        ?DateTimeImmutable $dueDate,
        bool $isDone = false,
        ?User $assignedTo = null,
    ): Ticket {
        $ticket = new Ticket();
        $ticket->setName($name);
        $ticket->setCreatedAt(new DateTimeImmutable());
        $ticket->setIsDone($isDone);
        $ticket->setDueDate($dueDate);
        $ticket->setOwner($owner);
        $ticket->setAssignedTo($assignedTo);

        $this->entityManager->persist($ticket);
        $this->entityManager->flush();

        return $ticket;
    }

    private function executeReminderCommand(): CommandTester
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:tickets:remind-deadlines'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        return $tester;
    }
}
