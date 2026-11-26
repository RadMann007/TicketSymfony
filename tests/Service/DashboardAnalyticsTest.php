<?php

namespace App\Tests\Service;

use App\Entity\Ticket;
use App\Entity\User;
use App\Enum\TicketPriority;
use App\Service\DashboardAnalytics;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

final class DashboardAnalyticsTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private DashboardAnalytics $analytics;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->createQuery('DELETE FROM App\Entity\Ticket')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();

        $this->analytics = new DashboardAnalytics(
            $this->entityManager->getRepository(Ticket::class),
            new MockClock('2026-11-25 10:00:00'),
        );
    }

    public function testSummarizeComputesResolutionDeadlineAndPriorityMetrics(): void
    {
        $user = $this->createUser('alice@test.com');

        // Résolu en 2 jours, dans les délais.
        $this->createTicket($user, 'Résolu dans les délais', new DateTimeImmutable('2026-10-06 09:00'), new DateTimeImmutable('2026-10-08 09:00'), new DateTimeImmutable('2026-10-08'));
        // Résolu en 1 jour, en retard d'un jour.
        $this->createTicket($user, 'Résolu en retard', new DateTimeImmutable('2026-11-24 09:00'), new DateTimeImmutable('2026-11-25 09:00'), new DateTimeImmutable('2026-11-24'));
        // Hors des 8 semaines affichées, mais compté dans le délai moyen.
        $this->createTicket($user, 'Ancien ticket', new DateTimeImmutable('2026-08-01 09:00'), new DateTimeImmutable('2026-08-02 09:00'), new DateTimeImmutable('2026-08-01'));

        $this->createTicket($user, 'Ouvert haute', new DateTimeImmutable('2026-11-20 09:00'), priority: TicketPriority::High);
        $this->createTicket($user, 'Ouvert normale', new DateTimeImmutable('2026-11-20 09:00'));
        $this->createTicket($user, 'Ouvert basse', new DateTimeImmutable('2026-11-20 09:00'), priority: TicketPriority::Low);

        $stats = $this->analytics->summarize($user, false);

        self::assertSame(1.3, $stats['averageResolutionDays']);
        self::assertSame(3, $stats['resolvedCount']);
        self::assertSame(33, $stats['onTimeRate']);
        self::assertSame(1, $stats['onTimeCount']);
        self::assertSame(3, $stats['dueDateCount']);

        self::assertCount(8, $stats['weeks']);
        self::assertSame('2026-10-05', $stats['weeks'][0]['start']->format('Y-m-d'));
        self::assertSame('2026-11-23', $stats['weeks'][7]['start']->format('Y-m-d'));

        $byWeek = [];
        foreach ($stats['weeks'] as $week) {
            $byWeek[$week['start']->format('Y-m-d')] = $week;
        }

        self::assertSame(1, $byWeek['2026-10-05']['created']);
        self::assertSame(1, $byWeek['2026-10-05']['resolved']);
        self::assertSame(1, $byWeek['2026-11-23']['created']);
        self::assertSame(1, $byWeek['2026-11-23']['resolved']);
        self::assertSame(3, $byWeek['2026-11-16']['created']);
        self::assertSame(0, $byWeek['2026-11-16']['resolved']);
        self::assertSame(3, $stats['maxWeekly']);

        self::assertSame(3, $stats['openTotal']);

        $openCounts = [];
        foreach ($stats['openByPriority'] as $item) {
            $openCounts[$item['priority']->value] = $item['count'];
        }
        self::assertSame(['low' => 1, 'normal' => 1, 'high' => 1], $openCounts);
    }

    public function testSummarizeRestrictsRegularUsersToTheirTickets(): void
    {
        $user = $this->createUser('alice@test.com');
        $other = $this->createUser('bob@test.com');

        $this->createTicket($user, 'Le mien', new DateTimeImmutable('2026-11-20 09:00'), new DateTimeImmutable('2026-11-22 09:00'));
        $this->createTicket($other, 'Celui de Bob', new DateTimeImmutable('2026-11-10 09:00'), new DateTimeImmutable('2026-11-20 09:00'));

        $personal = $this->analytics->summarize($user, false);
        self::assertSame(2.0, $personal['averageResolutionDays']);
        self::assertSame(1, $personal['resolvedCount']);

        // Un administrateur voit l'ensemble des tickets.
        $global = $this->analytics->summarize($user, true);
        self::assertSame(6.0, $global['averageResolutionDays']);
        self::assertSame(2, $global['resolvedCount']);
    }

    public function testSummarizeIncludesTicketsAssignedToUser(): void
    {
        $user = $this->createUser('alice@test.com');
        $owner = $this->createUser('bob@test.com');

        $this->createTicket(
            $owner,
            'Assigné à Alice',
            new DateTimeImmutable('2026-11-20 09:00'),
            new DateTimeImmutable('2026-11-21 09:00'),
            assignedTo: $user,
        );

        $stats = $this->analytics->summarize($user, false);

        self::assertSame(1, $stats['resolvedCount']);
        self::assertSame(1.0, $stats['averageResolutionDays']);
    }

    public function testSummarizeReturnsEmptyMetricsForUserWithoutTickets(): void
    {
        $user = $this->createUser('empty@test.com');

        $stats = $this->analytics->summarize($user, false);

        self::assertNull($stats['averageResolutionDays']);
        self::assertNull($stats['onTimeRate']);
        self::assertSame(0, $stats['resolvedCount']);
        self::assertSame(0, $stats['openTotal']);
        self::assertSame(1, $stats['maxWeekly']);
        self::assertCount(8, $stats['weeks']);

        foreach ($stats['weeks'] as $week) {
            self::assertSame(0, $week['created']);
            self::assertSame(0, $week['resolved']);
        }
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
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $completedAt = null,
        ?DateTimeImmutable $dueDate = null,
        TicketPriority $priority = TicketPriority::Normal,
        ?User $assignedTo = null,
    ): Ticket {
        $ticket = new Ticket();
        $ticket->setName($name);
        $ticket->setCreatedAt($createdAt);
        $ticket->setPriority($priority);
        $ticket->setDueDate($dueDate);
        $ticket->setIsDone(null !== $completedAt);

        if (null !== $completedAt) {
            $ticket->setCompletedAt($completedAt);
        }

        $ticket->setOwner($owner);
        $ticket->setAssignedTo($assignedTo);

        $this->entityManager->persist($ticket);
        $this->entityManager->flush();

        return $ticket;
    }
}
