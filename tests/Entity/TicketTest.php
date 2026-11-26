<?php

namespace App\Tests\Entity;

use App\Entity\Ticket;
use PHPUnit\Framework\TestCase;

final class TicketTest extends TestCase
{
    public function testCompletingTicketSetsCompletedAtOnceAndClearsItWhenReopened(): void
    {
        $ticket = new Ticket();

        $ticket->setIsDone(true);
        $completedAt = $ticket->getCompletedAt();

        self::assertNotNull($completedAt);

        // Repasser par « terminé » ne change pas la date de clôture.
        $ticket->setIsDone(true);
        self::assertSame($completedAt, $ticket->getCompletedAt());

        $ticket->setIsDone(false);
        self::assertNull($ticket->getCompletedAt());
    }
}
