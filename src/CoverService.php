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
        protected TranslatorInterface $translator,
        protected Focus $focus
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

        $this->apply($user, $fofFile, $this->focus->parse($focusX), $this->focus->parse($focusY), $this->focus->parseZoom($zoom));

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

        $this->apply($user, $fofFile, $this->focus->parse($focusX), $this->focus->parse($focusY), $this->focus->parseZoom($zoom));

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

        $data = $user->coverStudioData;

        if ($data === null || !$data->cover_file_id) {
            throw new ValidationException([
                'cover' => $this->translator->trans('tryhackx-cover-studio.api.no_cover'),
            ]);
        }

        $data->cover_focus_x = $this->focus->parse($focusX, $data->cover_focus_x ?? Focus::DEFAULT);
        $data->cover_focus_y = $this->focus->parse($focusY, $data->cover_focus_y ?? Focus::DEFAULT);
        $data->cover_zoom = $this->focus->parseZoom($zoom, $data->cover_zoom ?? Focus::ZOOM_DEFAULT);

        $data->saveOrPurge();
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

        $data = $user->coverStudioData;

        if ($data !== null) {
            $data->cover_file_id = null;
            $data->cover_url = null;
            $data->cover_thumb_url = null;
            $data->cover_focus_x = Focus::DEFAULT;
            $data->cover_focus_y = Focus::DEFAULT;
            $data->cover_zoom = Focus::ZOOM_DEFAULT;

            // Drops the row entirely when no avatar original remains either.
            $data->saveOrPurge();
        }

        $this->dispatchEventsFor($user, $actor);

        return $user;
    }

    protected function apply(User $user, File $file, float $focusX, float $focusY, float $zoom): void
    {
        $data = CoverStudioUserData::forUser($user);
        $data->cover_file_id = $file->id;
        $data->cover_url = $file->url;
        $data->cover_thumb_url = $file->thumbnail_url;
        $data->cover_focus_x = $focusX;
        $data->cover_focus_y = $focusY;
        $data->cover_zoom = $zoom;
        $data->saveOrPurge();
    }
}
