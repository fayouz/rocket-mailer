<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** A file received with an InboundMessage, stored in the attachments directory (see AttachmentStorage). */
#[ORM\Entity]
class InboundAttachment
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'attachments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private InboundMessage $message;

    #[ORM\Column(length: 255)]
    private string $filename;

    #[ORM\Column(length: 127)]
    private string $mimeType;

    #[ORM\Column]
    private int $size;

    /** Name of the file in the attachments storage directory (never exposed). */
    #[ORM\Column(length: 64, unique: true)]
    private string $storageName;

    public function __construct(InboundMessage $message, string $filename, string $mimeType, int $size)
    {
        $this->id = Uuid::v7();
        $this->storageName = 'inbound-'.$this->id->toRfc4122();
        $this->message = $message;
        $this->filename = mb_substr($filename, 0, 255);
        $this->mimeType = mb_substr($mimeType, 0, 127);
        $this->size = $size;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMessage(): InboundMessage
    {
        return $this->message;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getStorageName(): string
    {
        return $this->storageName;
    }
}
