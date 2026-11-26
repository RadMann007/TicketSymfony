<?php

namespace App\Service;

use App\Entity\Ticket;
use App\Entity\User;
use App\Enum\TicketPriority;
use App\Repository\TicketRepository;
use DateTimeImmutable;
use Symfony\Component\Clock\ClockInterface;

/**
 * Calcule les indicateurs du tableau de bord à partir des tickets visibles.
 */
final readonly class DashboardAnalytics
{
    private const WEEK_COUNT = 8;

    public function __construct(
        private TicketRepository $tickets,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{
     *     averageResolutionDays: ?float,
     *     resolvedCount: int,
     *     onTimeRate: ?int,
     *     onTimeCount: int,
     *     dueDateCount: int,
     *     maxWeekly: int,
     *     weeks: list<array{start: DateTimeImmutable, created: int, resolved: int}>,
     *     openTotal: int,
     *     openByPriority: list<array{priority: TicketPriority, count: int, share: int}>
     * }
     */
    public function summarize(User $user, bool $seeAll): array
    {
        $today = $this->clock->now()->setTime(0, 0);
        $weeks = $this->emptyWeeks($today);

        $durations = [];
        $onTimeCount = 0;
        $dueDateCount = 0;
        $openTotal = 0;
        $openCounts = array_fill_keys(
            array_map(static fn (TicketPriority $priority): string => $priority->value, TicketPriority::cases()),
            0,
        );

        foreach ($this->tickets->findForAnalytics($user, $seeAll) as $ticket) {
            $createdAt = $ticket->getCreatedAt();
            $completedAt = $ticket->getCompletedAt();

            if (null !== $createdAt) {
                $this->incrementWeek($weeks, 'created', $createdAt);
            }

            if (null !== $completedAt) {
                $this->incrementWeek($weeks, 'resolved', $completedAt);

                if (null !== $createdAt) {
                    $durations[] = ($completedAt->getTimestamp() - $createdAt->getTimestamp()) / 86400;
                }

                $dueDate = $ticket->getDueDate();
                if (null !== $dueDate) {
                    ++$dueDateCount;

                    // Une échéance au jour J est respectée si la clôture a lieu ce jour-là.
                    if ($completedAt < $dueDate->modify('+1 day')) {
                        ++$onTimeCount;
                    }
                }
            }

            if (true !== $ticket->isDone()) {
                ++$openCounts[$ticket->getPriority()->value];
                ++$openTotal;
            }
        }

        $maxWeekly = 1;
        foreach ($weeks as $week) {
            $maxWeekly = max($maxWeekly, $week['created'], $week['resolved']);
        }

        $openByPriority = [];
        foreach (TicketPriority::cases() as $priority) {
            $count = $openCounts[$priority->value];
            $openByPriority[] = [
                'priority' => $priority,
                'count' => $count,
                'share' => $openTotal > 0 ? (int) round($count / $openTotal * 100) : 0,
            ];
        }

        return [
            'averageResolutionDays' => [] === $durations ? null : round(array_sum($durations) / \count($durations), 1),
            'resolvedCount' => \count($durations),
            'onTimeRate' => $dueDateCount > 0 ? (int) round($onTimeCount / $dueDateCount * 100) : null,
            'onTimeCount' => $onTimeCount,
            'dueDateCount' => $dueDateCount,
            'maxWeekly' => $maxWeekly,
            'weeks' => array_values($weeks),
            'openTotal' => $openTotal,
            'openByPriority' => $openByPriority,
        ];
    }

    /**
     * @return array<string, array{start: DateTimeImmutable, created: int, resolved: int}>
     */
    private function emptyWeeks(DateTimeImmutable $today): array
    {
        $currentWeek = $this->weekStart($today);
        $weeks = [];

        for ($offset = self::WEEK_COUNT - 1; $offset >= 0; --$offset) {
            $start = $currentWeek->modify(sprintf('-%d weeks', $offset));
            $weeks[$start->format('Y-m-d')] = [
                'start' => $start,
                'created' => 0,
                'resolved' => 0,
            ];
        }

        return $weeks;
    }

    /**
     * @param array<string, array{start: DateTimeImmutable, created: int, resolved: int}> $weeks
     */
    private function incrementWeek(array &$weeks, string $key, DateTimeImmutable $date): void
    {
        $weekKey = $this->weekStart($date)->format('Y-m-d');

        if (isset($weeks[$weekKey])) {
            ++$weeks[$weekKey][$key];
        }
    }

    private function weekStart(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->setTime(0, 0)->modify('monday this week');
    }
}
