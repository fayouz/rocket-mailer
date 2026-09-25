<?php

namespace App\Mailbox;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class MailboxTestInput
{
    public function __construct(
        /** Also send a test email to this address. */
        #[Assert\Email]
        public ?string $sendTo = null,
    ) {
    }
}
