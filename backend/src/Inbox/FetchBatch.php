<?php

namespace App\Inbox;

final readonly class FetchBatch
{
    /** @param list<FetchedMessage> $messages */
    public function __construct(
        public int $uidValidity,
        public array $messages,
    ) {
    }
}
