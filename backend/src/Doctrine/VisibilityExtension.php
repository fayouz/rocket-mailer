<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Email;
use App\Entity\EmailTemplate;
use App\Security\ActorContext;
use App\Security\Roles;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Non-admins only list their own emails, and their own or shared templates.
 */
final class VisibilityExtension implements QueryCollectionExtensionInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly ActorContext $actor,
    ) {
    }

    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        if (!\in_array($resourceClass, [Email::class, EmailTemplate::class], true) || $this->security->isGranted(Roles::ADMIN)) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $user = $this->actor->getUser();
        if (null === $user) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $param = $queryNameGenerator->generateParameterName('current_user');
        $queryBuilder->setParameter($param, $user);

        if (Email::class === $resourceClass) {
            $queryBuilder->andWhere(\sprintf('%s.sender = :%s', $alias, $param));
        } else {
            $queryBuilder->andWhere(\sprintf('%s.owner = :%s OR %s.shared = true', $alias, $param, $alias));
        }
    }
}
