<?php

/*
 * This file is part of tryhackx/flarum-cover-studio.
 *
 * Copyright (c) TryHackX.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace TryHackX\CoverStudio\Provider;

use Flarum\Foundation\AbstractServiceProvider;
use Flarum\User\User;
use FoF\Upload\File;
use Illuminate\Contracts\Events\Dispatcher;
use TryHackX\CoverStudio\Support\Focus;

class CoverStudioServiceProvider extends AbstractServiceProvider
{
    public function boot(Dispatcher $events): void
    {
        // When a media-manager file is removed (user action, moderator action,
        // or fof/upload's cleanup command), detach it everywhere it is used as
        // a cover or avatar original.
        //
        // Two deliberate choices here:
        //
        // 1. We listen on the *deleting* (pre-delete) Eloquent event, NOT
        //    *deleted*: the users.cover_file_id / avatar_file_id foreign keys
        //    are declared ON DELETE SET NULL, so by the time *deleted* fires
        //    the reference columns are already NULL and a WHERE on them would
        //    match nothing — leaving stale URL copies behind.
        //
        // 2. We register through the shared event dispatcher (string event
        //    name) rather than File::deleting(), which silently no-ops if the
        //    model's static dispatcher has not been attached yet.
        //
        // The FK remains as a DB-level backstop for deletes that bypass
        // Eloquent entirely.
        $events->listen('eloquent.deleting: ' . File::class, static function (File $file): void {
            User::query()
                ->where('cover_file_id', $file->id)
                ->update([
                    'cover_file_id' => null,
                    'cover_url' => null,
                    'cover_thumb_url' => null,
                    'cover_focus_x' => Focus::DEFAULT,
                    'cover_focus_y' => Focus::DEFAULT,
                    'cover_zoom' => Focus::ZOOM_DEFAULT,
                ]);

            User::query()
                ->where('avatar_file_id', $file->id)
                ->update([
                    'avatar_file_id' => null,
                    'avatar_original_url' => null,
                    'avatar_focus_x' => Focus::DEFAULT,
                    'avatar_focus_y' => Focus::DEFAULT,
                    'avatar_zoom' => Focus::ZOOM_DEFAULT,
                ]);
        });
    }
}
