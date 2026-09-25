<?php

namespace App\Controller;

final readonly class SettingsInput
{
    public function __construct(
        /** Whether users may send from their own address. */
        public ?bool $personalFromAllowed = null,
        /** IRI or id of the project's color palette; "" goes back to the default colors. */
        public ?string $palette = null,
    ) {
    }
}
