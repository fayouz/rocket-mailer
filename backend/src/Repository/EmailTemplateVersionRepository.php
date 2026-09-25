<?php

namespace App\Repository;

use App\Entity\EmailTemplateVersion;
use Doctrine\ORM\Mapping\ClassMetadata;
use Gedmo\Loggable\Entity\Repository\LogEntryRepository;

/**
 * @extends LogEntryRepository<EmailTemplateVersion>
 */
class EmailTemplateVersionRepository extends LogEntryRepository
{
    /**
     * Restoring a relation (e.g. the layout): Gedmo builds a reference from the raw logged identifier, which
     * breaks typed identifiers (UUID). find() converts it, and gives null when the target was deleted since.
     */
    protected function mapValue(ClassMetadata $objectMeta, $field, &$value)
    {
        if (!$objectMeta->isSingleValuedAssociation($field)) {
            return;
        }

        $mapping = $objectMeta->getAssociationMapping($field);
        $targetEntity = $mapping->targetEntity ?? $mapping['targetEntity'];
        $value = $value ? $this->getEntityManager()->find($targetEntity, $value) : null;
    }
}
