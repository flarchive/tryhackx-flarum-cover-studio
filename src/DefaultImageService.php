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

use Flarum\Foundation\ValidationException;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Psr\Http\Message\UploadedFileInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use TryHackX\CoverStudio\Support\Focus;
use TryHackX\CoverStudio\Upload\UploadBridge;

/**
 * Forum-wide default cover / avatar images, set by an admin.
 *
 * The image is stored as a SHARED fof/upload file (it lands in the media
 * manager's "Shared" tab, owned by the acting admin) and its URL plus focal
 * point / zoom are persisted as plain settings. It can either be uploaded
 * fresh or picked from the existing shared media library. The frontend paints
 * those onto any user who has no cover / avatar of their own — purely
 * presentational, so no per-user files are ever generated.
 */
class DefaultImageService
{
    /** The two image roles this service manages. */
    public const KINDS = ['cover', 'avatar'];

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected CoverValidator $validator,
        protected UploadBridge $bridge,
        protected TranslatorInterface $translator,
        protected Focus $focus
    ) {
    }

    /**
     * Set the default cover/avatar from a fresh upload OR an existing shared
     * media file id.
     *
     * @return array<string, mixed> the stored values, keyed by full setting name
     */
    public function set(User $actor, string $kind, ?UploadedFileInterface $file, mixed $fileId, mixed $focusX, mixed $focusY, mixed $zoom): array
    {
        $this->assert($actor, $kind);

        if ($file !== null) {
            $this->validator->assertImageValid('image', $file);

            // Owner = the acting admin; stored as a shared file so it shows in
            // the "Shared" media library rather than any single user's.
            $fofFile = $this->bridge->uploadImage($file, $actor, $actor, true);
        } elseif ($fileId !== null && $fileId !== '') {
            $fofFile = $this->bridge->resolveSharedImage($fileId);
        } else {
            throw new ValidationException([
                'image' => $this->translator->trans('tryhackx-cover-studio.api.no_file'),
            ]);
        }

        $this->settings->set($this->key($kind, 'url'), (string) $fofFile->url);
        $this->settings->set($this->key($kind, 'focus_x'), $this->focus->parse($focusX));
        $this->settings->set($this->key($kind, 'focus_y'), $this->focus->parse($focusY));
        $this->settings->set($this->key($kind, 'zoom'), $this->focus->parseZoom($zoom));

        return $this->values($kind);
    }

    /**
     * Re-position the existing default image — no re-upload required.
     *
     * @return array<string, mixed>
     */
    public function reposition(User $actor, string $kind, mixed $focusX, mixed $focusY, mixed $zoom): array
    {
        $this->assert($actor, $kind);

        if (!$this->settings->get($this->key($kind, 'url'))) {
            throw new ValidationException([
                'image' => $this->translator->trans('tryhackx-cover-studio.api.no_default_image'),
            ]);
        }

        $this->settings->set($this->key($kind, 'focus_x'), $this->focus->parse($focusX));
        $this->settings->set($this->key($kind, 'focus_y'), $this->focus->parse($focusY));
        $this->settings->set($this->key($kind, 'zoom'), $this->focus->parseZoom($zoom));

        return $this->values($kind);
    }

    /**
     * Clear the default image. The underlying shared file intentionally stays
     * in the media manager — deleting it there is the way to remove it for good.
     *
     * @return array<string, mixed>
     */
    public function remove(User $actor, string $kind): array
    {
        $this->assert($actor, $kind);

        $this->settings->set($this->key($kind, 'url'), '');
        $this->settings->set($this->key($kind, 'focus_x'), Focus::DEFAULT);
        $this->settings->set($this->key($kind, 'focus_y'), Focus::DEFAULT);
        $this->settings->set($this->key($kind, 'zoom'), Focus::ZOOM_DEFAULT);

        return $this->values($kind);
    }

    protected function assert(User $actor, string $kind): void
    {
        $actor->assertRegistered();
        $actor->assertAdmin();

        if (!in_array($kind, self::KINDS, true)) {
            throw new ValidationException([
                'kind' => $this->translator->trans('tryhackx-cover-studio.api.unknown_default_kind'),
            ]);
        }
    }

    protected function key(string $kind, string $suffix): string
    {
        return "tryhackx-cover-studio.default_{$kind}_{$suffix}";
    }

    /**
     * The current stored values for $kind, keyed by full setting name — ready
     * to merge straight into the admin frontend's settings store.
     *
     * @return array<string, mixed>
     */
    protected function values(string $kind): array
    {
        return [
            $this->key($kind, 'url')     => (string) $this->settings->get($this->key($kind, 'url'), ''),
            $this->key($kind, 'focus_x') => (float) $this->settings->get($this->key($kind, 'focus_x'), Focus::DEFAULT),
            $this->key($kind, 'focus_y') => (float) $this->settings->get($this->key($kind, 'focus_y'), Focus::DEFAULT),
            $this->key($kind, 'zoom')    => (float) $this->settings->get($this->key($kind, 'zoom'), Focus::ZOOM_DEFAULT),
        ];
    }
}
