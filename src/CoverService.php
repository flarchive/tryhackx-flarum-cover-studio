<?php

/*
 * This file is part of tryhackx/flarum-cover-studio.
 *
 * Copyright (c) TryHackX.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace TryHackX\CoverStudio;

use Flarum\Foundation\DispatchEventsTrait;
use Flarum\Foundation\ValidationException;
use Flarum\User\User;
use FoF\Upload\File;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Http\Message\UploadedFileInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use TryHackX\CoverStudio\Support\Focus;
use TryHackX\CoverStudio\Upload\UploadBridge;

class CoverService
{
    use DispatchEventsTrait;

    public function __construct(
        protected Dispatcher $events,
        protected CoverValidator $validator,
        protected UploadBridge $bridge,
        protected TranslatorInterface $translator
    ) {
    }

    /**
     * Upload a fresh image and set it as $user's cover.
     */
    public function uploadAndSet(User $actor, User $user, ?UploadedFileInterface $file, mixed $focusX, mixed $focusY, mixed $zoom = null): User
    {
        $actor->assertRegistered();
        $actor->assertCan('setCover', $user);

        if ($file === null) {
            throw new ValidationException([
                'cover' => $this->translator->trans('tryhackx-cover-studio.api.no_file'),
            ]);
        }

        $this->validator->assertImageValid('cover', $file);

        $fofFile = $this->bridge->uploadImage($file, $actor, $user);

        $this->apply($user, $fofFile, Focus::parse($focusX), Focus::parse($focusY), Focus::parseZoom($zoom));

        $user->save();
        $this->dispatchEventsFor($user, $actor);

        return $user;
    }

    /**
     * Use an EXISTING media-manager file (owned by $user) as the cover.
     */
    public function attachExisting(User $actor, User $user, mixed $fileId, mixed $focusX, mixed $focusY, mixed $zoom = null): User
    {
        $actor->assertRegistered();
        $actor->assertCan('setCover', $user);

        // Eligibility (ownership, mime, not shared) is enforced by the bridge.
        $fofFile = $this->bridge->resolveUserImage($fileId, $user);

        $this->apply($user, $fofFile, Focus::parse($focusX), Focus::parse($focusY), Focus::parseZoom($zoom));

        $user->save();
        $this->dispatchEventsFor($user, $actor);

        return $user;
    }

    /**
     * Update only the focal point and/or zoom — no re-upload required.
     */
    public function setFocus(User $actor, User $user, mixed $focusX, mixed $focusY, mixed $zoom = null): User
    {
        $actor->assertRegistered();
        $actor->assertCan('setCover', $user);

        if (!$user->cover_file_id) {
            throw new ValidationException([
                'cover' => $this->translator->trans('tryhackx-cover-studio.api.no_cover'),
            ]);
        }

        $user->cover_focus_x = Focus::parse($focusX, $user->cover_focus_x ?? Focus::DEFAULT);
        $user->cover_focus_y = Focus::parse($focusY, $user->cover_focus_y ?? Focus::DEFAULT);
        $user->cover_zoom = Focus::parseZoom($zoom, $user->cover_zoom ?? Focus::ZOOM_DEFAULT);

        $user->save();
        $this->dispatchEventsFor($user, $actor);

        return $user;
    }

    /**
     * Detach the cover. The underlying file intentionally stays in the user's
     * media manager — deleting it there is the way to remove it for good.
     */
    public function remove(User $actor, User $user): User
    {
        $actor->assertRegistered();
        $actor->assertCan('setCover', $user);

        $user->cover_file_id = null;
        $user->cover_url = null;
        $user->cover_thumb_url = null;
        $user->cover_focus_x = Focus::DEFAULT;
        $user->cover_focus_y = Focus::DEFAULT;
        $user->cover_zoom = Focus::ZOOM_DEFAULT;

        $user->save();
        $this->dispatchEventsFor($user, $actor);

        return $user;
    }

    protected function apply(User $user, File $file, float $focusX, float $focusY, float $zoom): void
    {
        $user->cover_file_id = $file->id;
        $user->cover_url = $file->url;
        $user->cover_thumb_url = $file->thumbnail_url;
        $user->cover_focus_x = $focusX;
        $user->cover_focus_y = $focusY;
        $user->cover_zoom = $zoom;
    }
}
