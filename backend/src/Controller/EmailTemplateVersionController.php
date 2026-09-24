<?php

namespace App\Controller;

use App\Entity\EmailTemplate;
use App\Entity\EmailTemplateVersion;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Gedmo\Loggable\Entity\Repository\LogEntryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Template history, recorded by Gedmo Loggable (#[Gedmo\Versioned] fields of EmailTemplate).
 */
#[Route('/api/email_templates/{id}/versions', requirements: ['id' => Requirement::UUID])]
final class EmailTemplateVersionController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('', name: 'api_email_template_versions', methods: ['GET'])]
    public function list(EmailTemplate $template): JsonResponse
    {
        $this->denyUnlessCanRead($template);

        $versions = array_map(static fn (EmailTemplateVersion $entry) => [
            'version' => $entry->getVersion(),
            'action' => $entry->getAction(),
            'loggedAt' => $entry->getLoggedAt()->format(\DATE_ATOM),
            'username' => $entry->getUsername(),
            'changedFields' => array_keys($entry->getData() ?? []),
        ], $this->logs()->getLogEntries($template));

        return $this->json($versions);
    }

    #[Route('/{version}/restore', name: 'api_email_template_version_restore', requirements: ['version' => '\d+'], methods: ['POST'])]
    public function restore(EmailTemplate $template, int $version): JsonResponse
    {
        if ($this->isGranted(Roles::EMBED) || (!$this->isGranted(Roles::ADMIN) && $template->getOwner() !== $this->getUser())) {
            throw $this->createAccessDeniedException();
        }

        try {
            $this->logs()->revert($template, $version);
        } catch (\Gedmo\Exception\UnexpectedValueException $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }
        $this->em->flush();

        return $this->json($template, context: ['groups' => ['template:list', 'template:read', 'user:summary', 'tracking']]);
    }

    private function denyUnlessCanRead(EmailTemplate $template): void
    {
        if (!$this->isGranted(Roles::ADMIN) && !$template->isShared() && $template->getOwner() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
    }

    /** @return LogEntryRepository<EmailTemplateVersion> */
    private function logs(): LogEntryRepository
    {
        /** @var LogEntryRepository<EmailTemplateVersion> $repository */
        $repository = $this->em->getRepository(EmailTemplateVersion::class);

        return $repository;
    }
}
