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
use Illuminate\Database\Eloquent\Builder;
use TryHackX\CoverStudio\CoverStudioUserData;
use TryHackX\CoverStudio\Support\AvatarApplyGuard;
use TryHackX\CoverStudio\Support\Focus;

class CoverStudioServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        // Shared, request-scoped instance so AvatarFocusService (the writer) and
        // ClearAvatarFocusOnExternalChange (the reader) see the same flag. See
        // AvatarApplyGuard for why this is a container binding, not a static.
        $this->container->singleton(AvatarApplyGuard::class);
    }

    public function boot(Dispatcher $events): void
    {
        $this->eagerLoadCompanionRow();

        // When a media-manager file is removed (user action, moderator action,
        // or fof/upload's cleanup command), detach it everywhere it is used as
        // a cover or avatar original.
        //
        // Two deliberate choices here:
        //
        // 1. We listen on the *deleting* (pre-delete) Eloquent event, NOT
        //    *deleted*: the companion table's cover_file_id / avatar_file_id
        //    foreign keys are declared ON DELETE SET NULL, so by the time
        //    *deleted* fires those columns are already NULL and a WHERE on them
        //    would match nothing — leaving stale URL copies behind.
        //
        // 2. We register through the shared event dispatcher (string event
        //    name) rather than File::deleting(), which silently no-ops if the
        //    model's static dispatcher has not been attached yet.
        //
        // The FKs remain as a DB-level backstop for deletes that bypass
        // Eloquent entirely. Both WHEREs hit the FK indexes on the companion
        // table, so this stays cheap even on very large forums.
        $events->listen('eloquent.deleting: ' . File::class, static function (File $file): void {
            CoverStudioUserData::query()
                ->where('cover_file_id', $file->id)
                ->update([
                    'cover_file_id' => null,
                    'cover_url' => null,
                    'cover_thumb_url' => null,
                    'cover_focus_x' => Focus::DEFAULT,
                    'cover_focus_y' => Focus::DEFAULT,
                    'cover_zoom' => Focus::ZOOM_DEFAULT,
                ]);

            CoverStudioUserData::query()
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

    /**
     * Eager-load each user's companion row on every User query, so the cover /
     * avatar fields never cause an N+1 no matter where users are serialised
     * (discussion lists, popovers, the directory, notifications, …). This is a
     * global scope rather than per-endpoint eager loading precisely so no
     * user-bearing relation path can be missed.
     *
     * `with()` only fires when models are hydrated, so count()/exists()/update()
     * queries pay nothing; the follow-up is a single indexed
     * `WHERE user_id IN (…)` against a sparse table.
     */
    protected function eagerLoadCompanionRow(): void
    {
        User::addGlobalScope('tryhackx-cover-studio', function (Builder $query): void {
            if (CoverStudioUserData::tableAvailable()) {
                $query->with('coverStudioData');
            }
        });
    }
}
