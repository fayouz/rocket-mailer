<?php

namespace App\Inbox;

final readonly class FetchedMessage
{
    /** @param string|null $raw RFC 5322 message; null when it exceeds the size limit */
    public function __construct(
        public int $uid,
        public int $size,
        public ?string $raw,
    ) {
    }
}
