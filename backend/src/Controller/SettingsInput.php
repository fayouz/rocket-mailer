<?php

namespace App\Controller;

final readonly class SettingsInput
{
    public function __construct(
        /** Whether users may send from their own address. */
        public ?bool $personalFromAllowed = null,
    ) {
    }
}
