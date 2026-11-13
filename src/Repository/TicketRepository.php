<?php

namespace App\Repository;

use App\Entity\Ticket;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Ticket>
 */
class TicketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ticket::class);
    }

    public function findByOwner(User $owner, ?string $q = null): array
    {
        $qb = $this->createQueryBuilder('t')
        ->andWhere('t.owner = :owner')
        ->setParameter('owner', $owner)
        ->orderBy('t.id', 'ASC');
        
        if($q !== null && trim($q) !== ''){
            $qb->andWhere('t.name LIKE :q OR t.comment LIKE :q')
            ->setParameter('q', '%' .$q . '%');
        }

        return $qb->getQuery()->getResult();
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
     * @return array<int, array{owner: User, total: int, open: int, done: int}>
     */
    public function countByOwner(): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('u.id AS ownerId, COUNT(t.id) AS total, SUM(CASE WHEN t.isDone = true THEN 1 ELSE 0 END) AS done')
            ->join('t.owner', 'u')
            ->groupBy('u.id')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $owner = $this->getEntityManager()->getRepository(User::class)->find($row['ownerId']);
            if (!$owner) {
                continue;
            }
            $total = (int) $row['total'];
            $done = (int) $row['done'];
            $result[] = [
                'owner' => $owner,
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
