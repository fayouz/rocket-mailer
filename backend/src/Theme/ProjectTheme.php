<?php

namespace App\Theme;

use App\Entity\Application;
use App\Entity\ColorPalette;
use App\Repository\ColorPaletteRepository;
use App\Sender\Settings;
use Symfony\Component\Uid\Uuid;

/**
 * Palette in use: the application's one for its embedded composer, else the project's one (Réglages), else none
 * (Rocket Mailer's default colors).
 */
class ProjectTheme
{
    private const PROJECT_PALETTE = 'theme.palette';

    public function __construct(
        private readonly Settings $settings,
        private readonly ColorPaletteRepository $palettes,
    ) {
    }

    public function projectPalette(): ?ColorPalette
    {
        $id = $this->settings->get(self::PROJECT_PALETTE);

        // A deleted palette falls back to the default colors.
        return \is_string($id) && Uuid::isValid($id) ? $this->palettes->find($id) : null;
    }

    /** Persists the choice; the caller flushes. */
    public function setProjectPalette(?ColorPalette $palette): void
    {
        null === $palette ? $this->settings->remove(self::PROJECT_PALETTE) : $this->settings->set(self::PROJECT_PALETTE, (string) $palette->getId());
    }

    /** @return array{source: 'application'|'project'|'default', palette: array<string, mixed>|null} */
    public function for(?Application $application): array
    {
        if (null !== $palette = $application?->getPalette()) {
            return ['source' => 'application', 'palette' => $palette->toTheme()];
        }
        if (null !== $palette = $this->projectPalette()) {
            return ['source' => 'project', 'palette' => $palette->toTheme()];
        }

        return ['source' => 'default', 'palette' => null];
    }
}
