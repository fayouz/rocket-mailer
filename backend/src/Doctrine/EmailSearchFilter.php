<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\BackwardCompatibleFilterDescriptionTrait;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

/**
 * "q": case-insensitive search in the subject, the "From" address and all recipients (To, Cc, Bcc).
 */
final class EmailSearchFilter implements FilterInterface
{
    use BackwardCompatibleFilterDescriptionTrait;

    public function apply(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $value = $context['parameter']->getValue();
        if (!\is_string($value) || '' === trim($value)) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $param = $queryNameGenerator->generateParameterName('q');
        $fields = [
            "$alias.subject",
            "COALESCE($alias.fromAddress, '')",
            "JSON_TEXT($alias.to)",
            "JSON_TEXT($alias.cc)",
            "JSON_TEXT($alias.bcc)",
        ];

        $queryBuilder
            ->andWhere(implode(' OR ', array_map(static fn (string $field) => "LOWER($field) LIKE :$param ESCAPE '\\'", $fields)))
            ->setParameter($param, '%'.addcslashes(mb_strtolower(trim($value)), '%_\\').'%');
    }
}
