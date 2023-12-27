<?php

declare(strict_types=1);

namespace App\Platform\Actions;

use App\Platform\Models\Game;

class TrimGameMetadata
{
    public function execute(Game $game): void
    {
        $game->Title = $this->trimWhitespace($game->Title);
        $game->Publisher = $this->trimWhitespace($game->Publisher);
        $game->Developer = $this->trimWhitespace($game->Developer);
        $game->Genre = $this->trimWhitespace($game->Genre);
        $game->Released = $this->trimWhitespace($game->Released);
        $game->save();
    }

    private function trimWhitespace(?string $toTrim): ?string
    {
        if ($toTrim == null) {
        return null;
        }

        return trim(preg_replace('/\s+/', ' ', $toTrim));
    }
}
