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
use TryHackX\CoverStudio\Support\AvatarApplyGuard;
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
    public function __construct(
        protected AvatarApplyGuard $applyGuard
    ) {
    }

    public function handle(AvatarChanged $event): void
    {
        if ($this->applyGuard->applying()) {
            return;
        }

        $data = $event->user->coverStudioData;

        if ($data === null || $data->avatar_file_id === null) {
            return;
        }

        $data->avatar_file_id = null;
        $data->avatar_original_url = null;
        $data->avatar_focus_x = Focus::DEFAULT;
        $data->avatar_focus_y = Focus::DEFAULT;
        $data->avatar_zoom = Focus::ZOOM_DEFAULT;

        // Persist the cleanup (or drop the row if no cover remains either). The
        // companion model has no listeners, so this cannot loop back into us.
        $data->saveOrPurge();
    }
}
