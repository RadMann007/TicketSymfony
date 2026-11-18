<?php

namespace App\Repository;

use App\Entity\Ticket;
use App\Entity\User;
use App\Enum\TicketPriority;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Ticket>
 */
class TicketRepository extends ServiceEntityRepository
{
    public const SORT_RECENT = 'recent';
    public const SORT_OLDEST = 'oldest';
    public const SORT_PRIORITY = 'priority';
    public const SORT_DUE = 'due';

    /**
     * @var list<string>
     */
    public const SORTS = [self::SORT_RECENT, self::SORT_OLDEST, self::SORT_PRIORITY, self::SORT_DUE];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ticket::class);
    }

    public function findByOwner(User $owner, ?string $q = null): array
    {
        $qb = $this->createQueryBuilder('t')
        ->andWhere('t.owner = :owner')
        ->setParameter('owner', $owner);

        $this->applySearch($qb, $q);
        $this->applySort($qb, self::SORT_RECENT);

        return $qb->getQuery()->getResult();
    }

    /**
     * Tickets owned by or assigned to the given user.
     */
    public function findVisibleTo(
        User $user,
        ?string $q = null,
        ?TicketPriority $priority = null,
        bool $overdueOnly = false,
        string $sort = self::SORT_RECENT,
    ): array {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('(t.owner = :user OR t.assignedTo = :user)')
            ->setParameter('user', $user);

        $this->applySearch($qb, $q);

        if (null !== $priority) {
            $qb->andWhere('t.priority = :priority')
                ->setParameter('priority', $priority);
        }

        if ($overdueOnly) {
            $qb->andWhere('t.isDone = false')
                ->andWhere('t.dueDate IS NOT NULL')
                ->andWhere('t.dueDate < :today')
                ->setParameter('today', new DateTimeImmutable('today'));
        }

        $this->applySort($qb, $sort);

        return $qb->getQuery()->getResult();
    }

    private function applySearch(QueryBuilder $qb, ?string $q): void
    {
        if ($q !== null && trim($q) !== '') {
            $qb->andWhere('(t.name LIKE :q OR t.comment LIKE :q)')
                ->setParameter('q', '%' . $q . '%');
        }
    }

    private function applySort(QueryBuilder $qb, string $sort): void
    {
        match ($sort) {
            self::SORT_OLDEST => $qb->orderBy('t.createdAt', 'ASC'),
            self::SORT_PRIORITY => $qb
                ->addSelect("CASE t.priority WHEN 'high' THEN 3 WHEN 'normal' THEN 2 ELSE 1 END AS HIDDEN priorityWeight")
                ->orderBy('priorityWeight', 'DESC')
                ->addOrderBy('t.createdAt', 'DESC'),
            self::SORT_DUE => $qb
                ->addSelect('CASE WHEN t.dueDate IS NULL THEN 1 ELSE 0 END AS HIDDEN dueMissing')
                ->orderBy('dueMissing', 'ASC')
                ->addOrderBy('t.dueDate', 'ASC'),
            default => $qb->orderBy('t.createdAt', 'DESC'),
        };

        $qb->addOrderBy('t.id', 'DESC');
    }

    public function countByOwnerAndStatus(User $owner, ?bool $isDone = null): int
    {
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.owner = :owner')
            ->setParameter('owner', $owner);

        if ($isDone !== null) {
            $qb->andWhere('t.isDone = :isDone')
                ->setParameter('isDone', $isDone);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function findLatestByOwner(User $owner, int $limit = 5): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findLatestForUser(User $user, int $limit = 5): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('(t.owner = :user OR t.assignedTo = :user)')
            ->setParameter('user', $user)
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countAssignedTo(User $user, ?bool $isDone = null): int
    {
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.assignedTo = :user')
            ->setParameter('user', $user);

        if ($isDone !== null) {
            $qb->andWhere('t.isDone = :isDone')
                ->setParameter('isDone', $isDone);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Tickets visibles par l'utilisateur (possédés ou assignés) dont l'échéance est passée.
     */
    public function countOverdueForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('(t.owner = :user OR t.assignedTo = :user)')
            ->andWhere('t.isDone = false')
            ->andWhere('t.dueDate IS NOT NULL')
            ->andWhere('t.dueDate < :today')
            ->setParameter('user', $user)
            ->setParameter('today', new DateTimeImmutable('today'))
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByStatus(bool $isDone): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.isDone = :isDone')
            ->setParameter('isDone', $isDone)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array<int, array{ownerId: int, email: string, total: int, open: int, done: int}>
     */
    public function countByOwner(): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select(
                'u.id AS ownerId',
                'u.email AS email',
                'COUNT(t.id) AS total',
                'SUM(CASE WHEN t.isDone = true THEN 1 ELSE 0 END) AS done'
            )
            ->join('t.owner', 'u')
            ->groupBy('u.id, u.email')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $total = (int) $row['total'];
            $done = (int) $row['done'];
            $result[] = [
                'ownerId' => (int) $row['ownerId'],
                'email' => (string) $row['email'],
                'total' => $total,
                'done' => $done,
                'open' => $total - $done,
            ];
        }

        return $result;
    }

//    /**
//     * @return Ticket[] Returns an array of Ticket objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('t')
//            ->andWhere('t.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('t.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?Ticket
//    {
//        return $this->createQueryBuilder('t')
//            ->andWhere('t.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}
