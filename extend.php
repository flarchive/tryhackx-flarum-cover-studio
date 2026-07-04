<?php

/*
 * This file is part of tryhackx/flarum-cover-studio.
 *
 * Copyright (c) TryHackX.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Flarum\Api\Resource\UserResource;
use Flarum\Extend;
use Flarum\User\Event\AvatarChanged;
use Flarum\User\User;
use TryHackX\CoverStudio\Access\UserPolicy;
use TryHackX\CoverStudio\Api\DefaultImageController;
use TryHackX\CoverStudio\Api\UserCoverEndpoints;
use TryHackX\CoverStudio\Api\UserCoverFields;
use TryHackX\CoverStudio\Console\MigrateSychoCommand;
use TryHackX\CoverStudio\CoverStudioUserData;
use TryHackX\CoverStudio\Listener\ClearAvatarFocusOnExternalChange;
use TryHackX\CoverStudio\Provider\CoverStudioServiceProvider;

// Only ever emit http(s) URLs to the frontend — anything else (e.g. a
// javascript: URI) is silently dropped. Shared by the default cover/avatar.
$httpUrlOnly = function ($value): string {
    if (!is_string($value) || $value === '') {
        return '';
    }

    return in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true) ? $value : '';
};

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js')
        ->css(__DIR__ . '/less/admin.less'),

    new Extend\Locales(__DIR__ . '/locale'),

    // Cover/avatar data lives in the companion table (see CoverStudioUserData),
    // reached through this relation. It is eager-loaded on every User query by
    // the global scope in CoverStudioServiceProvider, so serialising authors in
    // discussion lists never triggers an N+1.
    (new Extend\Model(User::class))
        ->hasOne('coverStudioData', CoverStudioUserData::class, 'user_id'),

    (new Extend\ApiResource(UserResource::class))
        ->fields(UserCoverFields::class)
        ->endpoints(UserCoverEndpoints::class),

    (new Extend\Policy())
        ->modelPolicy(User::class, UserPolicy::class),

    (new Extend\Settings())
        ->default('tryhackx-cover-studio.max_size', 2048)
        ->default('tryhackx-cover-studio.cover_height', 220)
        ->default('tryhackx-cover-studio.cover_height_mobile', 160)
        ->default('tryhackx-cover-studio.show_on_popover', true)
        ->default('tryhackx-cover-studio.show_on_directory', true)
        ->default('tryhackx-cover-studio.media_picker', true)
        ->default('tryhackx-cover-studio.avatar_focus', false)
        ->default('tryhackx-cover-studio.overlay', 'gradient')
        ->default('tryhackx-cover-studio.default_cover_url', '')
        ->default('tryhackx-cover-studio.default_cover_focus_x', 50)
        ->default('tryhackx-cover-studio.default_cover_focus_y', 50)
        ->default('tryhackx-cover-studio.default_cover_zoom', 1)
        ->default('tryhackx-cover-studio.default_avatar_url', '')
        ->default('tryhackx-cover-studio.default_avatar_focus_x', 50)
        ->default('tryhackx-cover-studio.default_avatar_focus_y', 50)
        ->default('tryhackx-cover-studio.default_avatar_zoom', 1)
        ->serializeToForum('tryhackx-cover-studio.max_size', 'tryhackx-cover-studio.max_size', 'intval')
        ->serializeToForum('tryhackx-cover-studio.cover_height', 'tryhackx-cover-studio.cover_height', 'intval')
        ->serializeToForum('tryhackx-cover-studio.cover_height_mobile', 'tryhackx-cover-studio.cover_height_mobile', 'intval')
        ->serializeToForum('tryhackx-cover-studio.show_on_popover', 'tryhackx-cover-studio.show_on_popover', 'boolval')
        ->serializeToForum('tryhackx-cover-studio.show_on_directory', 'tryhackx-cover-studio.show_on_directory', 'boolval')
        ->serializeToForum('tryhackx-cover-studio.media_picker', 'tryhackx-cover-studio.media_picker', 'boolval')
        ->serializeToForum('tryhackx-cover-studio.avatar_focus', 'tryhackx-cover-studio.avatar_focus', 'boolval')
        ->serializeToForum('tryhackx-cover-studio.overlay', 'tryhackx-cover-studio.overlay', function ($value) {
            return in_array($value, ['none', 'gradient', 'darken'], true) ? $value : 'gradient';
        })
        ->serializeToForum('tryhackx-cover-studio.default_cover_url', 'tryhackx-cover-studio.default_cover_url', $httpUrlOnly)
        ->serializeToForum('tryhackx-cover-studio.default_cover_focus_x', 'tryhackx-cover-studio.default_cover_focus_x', 'floatval')
        ->serializeToForum('tryhackx-cover-studio.default_cover_focus_y', 'tryhackx-cover-studio.default_cover_focus_y', 'floatval')
        ->serializeToForum('tryhackx-cover-studio.default_cover_zoom', 'tryhackx-cover-studio.default_cover_zoom', 'floatval')
        ->serializeToForum('tryhackx-cover-studio.default_avatar_url', 'tryhackx-cover-studio.default_avatar_url', $httpUrlOnly)
        ->serializeToForum('tryhackx-cover-studio.default_avatar_focus_x', 'tryhackx-cover-studio.default_avatar_focus_x', 'floatval')
        ->serializeToForum('tryhackx-cover-studio.default_avatar_focus_y', 'tryhackx-cover-studio.default_avatar_focus_y', 'floatval')
        ->serializeToForum('tryhackx-cover-studio.default_avatar_zoom', 'tryhackx-cover-studio.default_avatar_zoom', 'floatval'),

    (new Extend\Event())
        ->listen(AvatarChanged::class, ClearAvatarFocusOnExternalChange::class),

    (new Extend\ServiceProvider())
        ->register(CoverStudioServiceProvider::class),

    (new Extend\Console())
        ->command(MigrateSychoCommand::class),

    // Admin-only endpoints for the forum-wide default cover / avatar image.
    // A single controller switches on the HTTP method (see DefaultImageController).
    (new Extend\Routes('api'))
        ->post('/cover-studio/default/{kind}', 'coverStudio.default.set', DefaultImageController::class)
        ->patch('/cover-studio/default/{kind}', 'coverStudio.default.reposition', DefaultImageController::class)
        ->delete('/cover-studio/default/{kind}', 'coverStudio.default.remove', DefaultImageController::class),
];
