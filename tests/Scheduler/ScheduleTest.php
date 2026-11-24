<?php

namespace App\Tests\Scheduler;

use App\Schedule;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Trigger\CronExpressionTrigger;

final class ScheduleTest extends KernelTestCase
{
    public function testReminderCommandIsScheduledEveryDayAtEight(): void
    {
        self::bootKernel();

        $schedule = static::getContainer()->get(Schedule::class)->getSchedule();
        $recurringMessages = $schedule->getRecurringMessages();

        self::assertCount(1, $recurringMessages);

        $recurring = $recurringMessages[0];
        $trigger = $recurring->getTrigger();

        self::assertInstanceOf(CronExpressionTrigger::class, $trigger);
        self::assertSame('0 8 * * *', (string) $trigger);

        $context = new MessageContext('default', 'test', $trigger, new DateTimeImmutable());

        $messages = [];
        foreach ($recurring->getMessages($context) as $message) {
            $messages[] = $message;
        }

        self::assertCount(1, $messages);
        self::assertInstanceOf(RunCommandMessage::class, $messages[0]);
        self::assertSame('app:tickets:remind-deadlines', $messages[0]->input);
    }
}
