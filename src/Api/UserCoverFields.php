<?php

/*
 * This file is part of tryhackx/flarum-cover-studio.
 *
 * Copyright (c) TryHackX.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace TryHackX\CoverStudio\Api;

use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

/**
 * Extra user attributes. All cover values are read from denormalized columns
 * on the users row itself — users are serialized inside discussion lists, so
 * touching the file relation here would introduce N+1 queries.
 */
class UserCoverFields
{
    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function __invoke(): array
    {
        // Serialization gate: cover_file_id is the source of truth. If the file
        // vanished (media manager delete, FK SET NULL), stale URL copies are
        // never emitted.
        $coverActive = fn (User $user): bool => $user->cover_file_id !== null;

        // The uncropped avatar original may show content the user deliberately
        // cropped away — expose it only to the user themselves and to staff who
        // can edit them.
        $selfOrEditor = function (User $user, Context $context): bool {
            $actor = $context->getActor();

            return $actor->id === $user->id || $actor->can('edit', $user);
        };

        return [
            Schema\Str::make('coverUrl')
                ->get(fn (User $user) => $coverActive($user) ? $user->cover_url : null),

            Schema\Str::make('coverThumbUrl')
                ->get(fn (User $user) => $coverActive($user) ? ($user->cover_thumb_url ?: $user->cover_url) : null),

            Schema\Number::make('coverFocusX')
                ->get(fn (User $user) => $coverActive($user) ? (float) $user->cover_focus_x : 50.0),

            Schema\Number::make('coverFocusY')
                ->get(fn (User $user) => $coverActive($user) ? (float) $user->cover_focus_y : 50.0),

            Schema\Number::make('coverZoom')
                ->get(fn (User $user) => $coverActive($user) ? max(0.5, min(4.0, (float) ($user->cover_zoom ?? 1.0))) : 1.0),

            Schema\Boolean::make('canSetCover')
                ->get(fn (User $user, Context $context) => $context->getActor()->can('setCover', $user)),

            Schema\Str::make('avatarOriginalUrl')
                ->visible($selfOrEditor)
                ->get(fn (User $user) => $user->avatar_file_id !== null ? $user->avatar_original_url : null),

            Schema\Number::make('avatarFocusX')
                ->visible($selfOrEditor)
                ->get(fn (User $user) => (float) ($user->avatar_focus_x ?? 50.0)),

            Schema\Number::make('avatarFocusY')
                ->visible($selfOrEditor)
                ->get(fn (User $user) => (float) ($user->avatar_focus_y ?? 50.0)),

            Schema\Number::make('avatarZoom')
                ->visible($selfOrEditor)
                ->get(fn (User $user) => max(0.5, min(4.0, (float) ($user->avatar_zoom ?? 1.0)))),

            Schema\Boolean::make('canSetAvatarFocus')
                ->get(function (User $user, Context $context) {
                    return (bool) $this->settings->get('tryhackx-cover-studio.avatar_focus')
                        && $context->getActor()->can('setAvatarFocus', $user);
                }),
        ];
    }
}
