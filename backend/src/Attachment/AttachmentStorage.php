<?php

namespace App\Attachment;

use App\Entity\Attachment;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Stores attachment files on disk. The directory must be shared by the API and the worker (Docker volume).
 */
final class AttachmentStorage
{
    private readonly Filesystem $filesystem;

    public function __construct(#[Autowire(env: 'resolve:ATTACHMENTS_DIR')] private readonly string $directory)
    {
        $this->filesystem = new Filesystem();
    }

    public function store(UploadedFile $file, Attachment $attachment): void
    {
        $this->filesystem->mkdir($this->directory, 0o750);
        $file->move($this->directory, $attachment->getStorageName());
    }

    public function path(Attachment $attachment): string
    {
        return $this->directory.\DIRECTORY_SEPARATOR.$attachment->getStorageName();
    }

    /** Stores a received file (shared inbox) under its storage name. */
    public function storeContent(string $storageName, string $content): void
    {
        $this->filesystem->mkdir($this->directory, 0o750);
        $this->filesystem->dumpFile($this->pathOf($storageName), $content);
    }

    public function pathOf(string $storageName): string
    {
        return $this->directory.\DIRECTORY_SEPARATOR.basename($storageName);
    }

    public function delete(Attachment $attachment): void
    {
        $this->filesystem->remove($this->path($attachment));
    }
}
