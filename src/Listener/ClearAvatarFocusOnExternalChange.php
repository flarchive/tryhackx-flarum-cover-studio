<?php

/*
 * This file is part of tryhackx/flarum-cover-studio.
 *
 * Copyright (c) TryHackX.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace TryHackX\CoverStudio\Listener;

use Flarum\User\Event\AvatarChanged;
use TryHackX\CoverStudio\AvatarFocusService;
use TryHackX\CoverStudio\Support\Focus;

/**
 * When the avatar changes OUTSIDE Cover Studio (core upload/removal, OAuth
 * providers, other extensions), the stored original no longer corresponds to
 * the live avatar — drop the reference so stale re-crops are impossible.
 *
 * The original file itself stays in the user's media manager; only the link
 * is severed. Deleting the file is the user's call.
 */
class ClearAvatarFocusOnExternalChange
{
    public function handle(AvatarChanged $event): void
    {
        if (AvatarFocusService::$applying) {
            return;
        }

        $user = $event->user;

        if ($user->avatar_file_id === null && $user->avatar_original_url === null) {
            return;
        }

        $user->avatar_file_id = null;
        $user->avatar_original_url = null;
        $user->avatar_focus_x = Focus::DEFAULT;
        $user->avatar_focus_y = Focus::DEFAULT;

        // AvatarChanged is dispatched after the triggering save, so persist our
        // cleanup with a quiet save — no events, no listener loops.
        $user->saveQuietly();
    }
}
