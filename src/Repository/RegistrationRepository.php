<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Registration;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Registration>
 */
class RegistrationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Registration::class);
    }

    public function findByCadastralReference(string $cadastralReference): ?Registration
    {
        return $this->findOneBy(['cadastralReference' => $cadastralReference]);
    }

    public function findByUnsubscribeToken(string $token): ?Registration
    {
        return $this->findOneBy(['unsubscribeToken' => $token]);
    }

    /**
     * @return Registration[]
     */
    public function findAllOrderedByCreatedAt(): array
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
