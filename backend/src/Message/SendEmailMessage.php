<?php

namespace App\Message;

final readonly class SendEmailMessage
{
    public function __construct(public string $emailId)
    {
    }
}
