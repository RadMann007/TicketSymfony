<?php

namespace App\Tests\EventListener;

use App\Entity\Ticket;
use App\Entity\TicketEvent;
use App\Entity\User;
use App\Enum\TicketPriority;
use App\Repository\TicketEventRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TicketHistoryListenerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    public function testRecordsChangesMadeOutsideAController(): void
    {
        $owner = new User();
        $owner->setEmail('history@test.com');
        $owner->setPassword('irrelevant');

        $this->entityManager->persist($owner);
        $this->entityManager->flush();

        $ticket = new Ticket();
        $ticket->setName('Ticket initial');
        $ticket->setIsDone(false);
        $ticket->setCreatedAt(new DateTimeImmutable());
        $ticket->setOwner($owner);

        $this->entityManager->persist($ticket);
        $this->entityManager->flush();

        // Modification directe via l'EntityManager, sans passer par un contrôleur.
        $ticket->setName('Ticket renommé');
        $ticket->setPriority(TicketPriority::High);
        $ticket->setDueDate(new DateTimeImmutable('2027-03-01'));
        $ticket->setIsDone(true);
        $this->entityManager->flush();

        $events = static::getContainer()->get(TicketEventRepository::class)->findBy(['ticket' => $ticket->getId()]);
        self::assertCount(5, $events);

        $byType = [];
        foreach ($events as $event) {
            $byType[$event->getType()] = $event;
            self::assertNull($event->getActor(), 'Hors requête HTTP, l\'acteur est inconnu.');
        }

        self::assertSame('Ticket initial', $byType[TicketEvent::TYPE_CREATED]->getNewValue());

        self::assertSame('Ticket initial', $byType[TicketEvent::TYPE_UPDATED]->getOldValue());
        self::assertSame('Ticket renommé', $byType[TicketEvent::TYPE_UPDATED]->getNewValue());

        self::assertSame('Normale', $byType[TicketEvent::TYPE_PRIORITY_CHANGED]->getOldValue());
        self::assertSame('Haute', $byType[TicketEvent::TYPE_PRIORITY_CHANGED]->getNewValue());

        self::assertSame('Aucune', $byType[TicketEvent::TYPE_DUE_DATE_CHANGED]->getOldValue());
        self::assertSame('01/03/2027', $byType[TicketEvent::TYPE_DUE_DATE_CHANGED]->getNewValue());

        self::assertSame('En cours', $byType[TicketEvent::TYPE_STATUS_CHANGED]->getOldValue());
        self::assertSame('Terminé', $byType[TicketEvent::TYPE_STATUS_CHANGED]->getNewValue());
    }

    private function purge(): void
    {
        $this->entityManager->createQuery('DELETE FROM App\Entity\Ticket')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }
}
