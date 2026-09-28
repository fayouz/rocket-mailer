<?php

namespace App\Mailbox;

use App\Entity\Mailbox;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A personal mailbox with a password (POST /api/mailboxes/personal, PATCH /api/mailboxes/personal/{id}).
 * Servers left empty are detected from the address (MailProviderDetector).
 */
final class PersonalMailboxInput
{
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    public ?string $email = null;

    #[Assert\Length(max: 120)]
    public ?string $name = null;

    #[Assert\Length(max: 120)]
    public ?string $displayName = null;

    /** Login (default: the address). */
    #[Assert\Length(max: 255)]
    public ?string $username = null;

    /** Required on creation; empty on update keeps the current one. */
    public ?string $password = null;

    #[Assert\Length(max: 255)]
    public ?string $imapHost = null;

    #[Assert\Range(min: 1, max: 65535)]
    public ?int $imapPort = null;

    #[Assert\Choice(choices: Mailbox::ENCRYPTIONS)]
    public ?string $imapEncryption = null;

    #[Assert\Length(max: 255)]
    public ?string $smtpHost = null;

    #[Assert\Range(min: 1, max: 65535)]
    public ?int $smtpPort = null;

    #[Assert\Choice(choices: Mailbox::ENCRYPTIONS)]
    public ?string $smtpEncryption = null;

    /** Fetch the INBOX into conversations (default true). */
    public ?bool $inboxEnabled = null;

    /** Keep a copy of sent emails in the IMAP "Sent" folder (default true). */
    public ?bool $keepSentCopy = null;

    public ?bool $enabled = null;
}
