<?php

namespace App\Controller;

use App\Attachment\AttachmentStorage;
use App\Attachment\AttachmentUploader;
use App\Entity\Attachment;
use App\Security\ActorContext;
use App\Security\Roles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Attribute\MapUploadedFile;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Validator\Constraints as Assert;

final class AttachmentController extends AbstractController
{
    /** Multipart upload, field "file". The attachment is linked to an email when that email is sent. */
    #[Route('/api/attachments', name: 'api_attachment_upload', methods: ['POST'])]
    public function upload(
        #[MapUploadedFile([new Assert\NotNull()])] UploadedFile $file,
        AttachmentUploader $uploader,
        ActorContext $actor,
    ): JsonResponse {
        $attachment = $uploader->upload($file, $actor->requireUser());

        return $this->json($attachment, Response::HTTP_CREATED, context: ['groups' => ['attachment:read', 'tracking']]);
    }

    #[Route('/api/attachments/{id}/download', name: 'api_attachment_download', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function download(Attachment $attachment, AttachmentStorage $storage): BinaryFileResponse
    {
        if (!$this->isGranted(Roles::ADMIN) && $attachment->getOwner() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $path = $storage->path($attachment);
        if (!is_file($path)) {
            throw $this->createNotFoundException('Attachment file is missing.');
        }

        // Always a download, never rendered by the browser on the API origin.
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $attachment->getFilename(),
            AttachmentUploader::sanitizeFilename(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $attachment->getFilename()) ?: 'fichier'),
        );

        return $response;
    }
}
