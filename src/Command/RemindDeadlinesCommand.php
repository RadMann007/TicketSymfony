<?php

namespace App\Command;

use App\Entity\Ticket;
use App\Entity\User;
use App\Repository\TicketRepository;
use DateTimeImmutable;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Envoie un rappel aux propriétaires et assignés des tickets en retard ou
 * dont l'échéance tombe demain.
 */
#[AsCommand(
    name: 'app:tickets:remind-deadlines',
    description: 'Envoie un rappel pour les tickets en retard ou dont l\'échéance est demain.',
)]
final class RemindDeadlinesCommand extends Command
{
    public function __construct(
        private readonly TicketRepository $tickets,
        private readonly MailerInterface $mailer,
        #[Autowire('%env(MAILER_FROM)%')]
        private readonly string $from,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $today = new DateTimeImmutable('today');

        $tickets = $this->tickets->findDeadlineReminders($today);

        if ([] === $tickets) {
            $io->success('Aucun rappel à envoyer.');

            return Command::SUCCESS;
        }

        $sent = 0;
        $rows = [];

        foreach ($tickets as $ticket) {
            $dueDate = $ticket->getDueDate();

            if (null === $dueDate) {
                continue;
            }

            $daysUntilDue = (int) $today->diff($dueDate)->format('%r%a');
            $recipients = $this->recipients($ticket);

            foreach ($recipients as $recipient) {
                $this->mailer->send($this->createEmail($ticket, $recipient, $daysUntilDue));
                ++$sent;
            }

            $rows[] = [
                sprintf('#%d %s', $ticket->getId(), (string) $ticket->getName()),
                $dueDate->format('d/m/Y'),
                $daysUntilDue < 0 ? sprintf('%d jour(s) de retard', -$daysUntilDue) : 'demain',
                implode(', ', array_map(static fn (User $user): string => (string) $user->getEmail(), $recipients)),
            ];
        }

        $io->table(['Ticket', 'Échéance', 'Délai', 'Destinataires'], $rows);
        $io->success(sprintf('%d rappel(s) envoyé(s) pour %d ticket(s).', $sent, \count($tickets)));

        return Command::SUCCESS;
    }

    /**
     * Propriétaire et assigné, sans doublon quand il s'agit de la même personne.
     *
     * @return list<User>
     */
    private function recipients(Ticket $ticket): array
    {
        $recipients = [];

        foreach ([$ticket->getOwner(), $ticket->getAssignedTo()] as $user) {
            if (!$user instanceof User || null === $user->getEmail()) {
                continue;
            }

            $recipients[$user->getEmail()] = $user;
        }

        return array_values($recipients);
    }

    private function createEmail(Ticket $ticket, User $recipient, int $daysUntilDue): TemplatedEmail
    {
        return (new TemplatedEmail())
            ->from(new Address($this->from, 'Suivi des tickets'))
            ->to(new Address((string) $recipient->getEmail()))
            ->subject(sprintf(
                'Rappel ticket #%d : %s (%s)',
                (int) $ticket->getId(),
                (string) $ticket->getName(),
                $this->deadlineLabel($daysUntilDue),
            ))
            ->htmlTemplate('email/deadline_reminder.html.twig')
            ->context([
                'ticket' => $ticket,
                'recipient' => $recipient,
                'daysUntilDue' => $daysUntilDue,
                'deadlineLabel' => $this->deadlineLabel($daysUntilDue),
            ]);
    }

    private function deadlineLabel(int $daysUntilDue): string
    {
        return match (true) {
            $daysUntilDue < 0 => sprintf('échéance dépassée de %d jour(s)', -$daysUntilDue),
            0 === $daysUntilDue => 'échéance aujourd\'hui',
            default => sprintf('échéance dans %d jour(s)', $daysUntilDue),
        };
    }
}
