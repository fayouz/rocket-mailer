<?php

namespace App\Repository;

use App\Entity\ApplicationSender;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Rocket\Core\Entity\Application;

/** @extends ServiceEntityRepository<ApplicationSender> */
class ApplicationSenderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApplicationSender::class);
    }

    /** The application's sender settings, or empty ones (not persisted) when nothing was saved yet. */
    public function forApplication(Application $application): ApplicationSender
    {
        return $this->find($application->getId()) ?? new ApplicationSender($application);
    }
}
