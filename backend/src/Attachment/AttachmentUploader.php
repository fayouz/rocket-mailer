<?php

namespace App\Attachment;

use App\Entity\Attachment;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class AttachmentUploader
{
    /** Extensions that mail servers commonly reject or that recipients should never be sent. */
    private const BLOCKED_EXTENSIONS = [
        'exe', 'com', 'bat', 'cmd', 'scr', 'pif', 'msi', 'msp', 'cpl', 'dll', 'jar',
        'js', 'jse', 'vbs', 'vbe', 'wsf', 'wsh', 'ps1', 'hta', 'lnk', 'reg', 'iso',
    ];

    public function __construct(
        private readonly AttachmentStorage $storage,
        private readonly EntityManagerInterface $em,
        #[Autowire(env: 'int:ATTACHMENT_MAX_SIZE')] private readonly int $maxSize,
    ) {
    }

    public function upload(UploadedFile $file, User $owner): Attachment
    {
        if (!$file->isValid()) {
            throw \in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)
                ? new HttpException(413, $this->tooLargeMessage())
                : new UnprocessableEntityHttpException($file->getErrorMessage());
        }

        $size = (int) $file->getSize();
        if ($size > $this->maxSize) {
            throw new HttpException(413, $this->tooLargeMessage());
        }
        if (0 === $size) {
            throw new UnprocessableEntityHttpException('The file is empty.');
        }

        $filename = self::sanitizeFilename($file->getClientOriginalName());
        $extension = mb_strtolower(pathinfo($filename, \PATHINFO_EXTENSION));
        if (\in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
            throw new UnprocessableEntityHttpException(\sprintf('Files of type ".%s" cannot be attached.', $extension));
        }

        $attachment = new Attachment($owner, $filename, $file->getMimeType() ?? 'application/octet-stream', $size);
        $this->storage->store($file, $attachment);
        $this->em->persist($attachment);
        $this->em->flush();

        return $attachment;
    }

    public function maxSize(): int
    {
        return $this->maxSize;
    }

    /** Keeps the name readable for recipients while removing paths and control characters. */
    public static function sanitizeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F"<>:|?*\/]+/u', '_', $name), " .\t");
        if ('' === $name) {
            $name = 'fichier';
        }

        return mb_substr($name, -200);
    }

    private function tooLargeMessage(): string
    {
        return \sprintf('The file is too large (maximum %d MB).', intdiv($this->maxSize, 1024 * 1024));
    }
}
