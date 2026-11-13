<?php

declare(strict_types=1);

namespace App\Bundle\AuthWebhook\Repository;

use App\Bundle\AuthWebhook\Entity\WebhookDelivery;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WebhookDelivery>
 */
class WebhookDeliveryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebhookDelivery::class);
    }

    /**
     * Delete delivery-log rows attempted before the cutoff, regardless of status. Used by the bundle's
     * WebhookDeliveryPruner (app:prune / FEATURE-147). The entity timestamps delivery via `attemptedAt`
     * (there is no `createdAt`). Returns rows removed.
     */
    public function deleteAttemptedBefore(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->getEntityManager()
            ->createQuery('DELETE FROM ' . WebhookDelivery::class . ' d WHERE d.attemptedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->execute();
    }

    /** Dry-run mirror of {@see deleteAttemptedBefore()} for app:prune (FEATURE-147). Mutates nothing. */
    public function countAttemptedBefore(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.attemptedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
