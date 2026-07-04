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
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\AvatarUploader;
use Flarum\User\AvatarValidator;
use Flarum\User\Event\AvatarSaving;
use Flarum\User\User;
use FoF\Upload\File;
use Illuminate\Contracts\Events\Dispatcher;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Psr\Http\Message\UploadedFileInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use TryHackX\CoverStudio\Support\Focus;
use TryHackX\CoverStudio\Upload\UploadBridge;

/**
 * Avatar "pos-crop": the ORIGINAL avatar image is preserved through fof/upload
 * (visible in the media manager), and the actual avatar files that core serves
 * everywhere (posts, emails, notifications) are re-cropped server-side from
 * that original whenever the focal point changes. No CSS hacks — the avatar
 * stays a plain, standard core avatar.
 */
class AvatarFocusService
{
    use DispatchEventsTrait;

    /**
     * Guard flag: true while THIS service is writing avatar files, so that the
     * AvatarChanged listener can distinguish our own re-crops from external
     * avatar changes (which must invalidate the stored original).
     */
    public static bool $applying = false;

    public function __construct(
        protected Dispatcher $events,
        protected SettingsRepositoryInterface $settings,
        protected AvatarValidator $validator,
        protected AvatarUploader $avatarUploader,
        protected ImageManager $imageManager,
        protected UploadBridge $bridge,
        protected TranslatorInterface $translator
    ) {
    }

    /**
     * Upload a new avatar: store the original in the media manager, then crop
     * at the requested focal point.
     */
    public function uploadAndSet(User $actor, User $user, ?UploadedFileInterface $file, mixed $focusX, mixed $focusY, mixed $zoom = null): User
    {
        $this->assertEnabled();
        $actor->assertRegistered();
        $actor->assertCan('setAvatarFocus', $user);

        if ($file === null) {
            throw new ValidationException([
                'avatar' => $this->translator->trans('tryhackx-cover-studio.api.no_file'),
            ]);
        }

        // Core's own avatar validator: identical limits to a regular avatar upload.
        $this->validator->assertImageValid('avatar', $file);

        // Read the bytes BEFORE the bridge consumes (moves) the temp file.
        $stream = $file->getStream();
        $bytes = (string) $stream;

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $fofFile = $this->bridge->uploadImage($file, $actor, $user);

        $x = Focus::parse($focusX);
        $y = Focus::parse($focusY);
        $z = Focus::parseZoom($zoom);

        $image = $this->readImage($bytes);

        $this->events->dispatch(new AvatarSaving($user, $actor, $image));

        self::$applying = true;

        try {
            $this->cropAndStore($user, $image, $x, $y, $z);

            $user->avatar_file_id = $fofFile->id;
            $user->avatar_original_url = $fofFile->url;
            $user->avatar_focus_x = $x;
            $user->avatar_focus_y = $y;
            $user->avatar_zoom = $z;

            $user->save();
            $this->dispatchEventsFor($user, $actor);
        } finally {
            self::$applying = false;
        }

        return $user;
    }

    /**
     * Use an EXISTING media-manager image (owned by $user) as the avatar
     * source: the file becomes the stored original and the avatar is cropped
     * from it at the requested focal point/zoom. Mirrors the cover flow.
     */
    public function attachExisting(User $actor, User $user, mixed $fileId, mixed $focusX, mixed $focusY, mixed $zoom = null): User
    {
        $this->assertEnabled();
        $actor->assertRegistered();
        $actor->assertCan('setAvatarFocus', $user);

        // Eligibility (ownership, mime, not shared) is enforced by the bridge.
        $fofFile = $this->bridge->resolveUserImage($fileId, $user);

        $bytes = $this->bridge->readFileContents($fofFile);

        if ($bytes === null) {
            throw new ValidationException([
                'avatar' => $this->translator->trans('tryhackx-cover-studio.api.original_unreadable'),
            ]);
        }

        $x = Focus::parse($focusX);
        $y = Focus::parse($focusY);
        $z = Focus::parseZoom($zoom);

        $image = $this->readImage($bytes);

        $this->events->dispatch(new AvatarSaving($user, $actor, $image));

        self::$applying = true;

        try {
            $this->cropAndStore($user, $image, $x, $y, $z);

            $user->avatar_file_id = $fofFile->id;
            $user->avatar_original_url = $fofFile->url;
            $user->avatar_focus_x = $x;
            $user->avatar_focus_y = $y;
            $user->avatar_zoom = $z;

            $user->save();
            $this->dispatchEventsFor($user, $actor);
        } finally {
            self::$applying = false;
        }

        return $user;
    }

    /**
     * Change only the focal point and/or zoom: re-crop from the stored original.
     */
    public function setFocus(User $actor, User $user, mixed $focusX, mixed $focusY, mixed $zoom = null): User
    {
        $this->assertEnabled();
        $actor->assertRegistered();
        $actor->assertCan('setAvatarFocus', $user);

        $fofFile = $user->avatar_file_id ? File::query()->find($user->avatar_file_id) : null;

        if ($fofFile === null) {
            throw new ValidationException([
                'avatar' => $this->translator->trans('tryhackx-cover-studio.api.no_original'),
            ]);
        }

        $bytes = $this->bridge->readFileContents($fofFile);

        if ($bytes === null) {
            throw new ValidationException([
                'avatar' => $this->translator->trans('tryhackx-cover-studio.api.original_unreadable'),
            ]);
        }

        $x = Focus::parse($focusX, $user->avatar_focus_x ?? Focus::DEFAULT);
        $y = Focus::parse($focusY, $user->avatar_focus_y ?? Focus::DEFAULT);
        $z = Focus::parseZoom($zoom, $user->avatar_zoom ?? Focus::ZOOM_DEFAULT);

        $image = $this->readImage($bytes);

        $this->events->dispatch(new AvatarSaving($user, $actor, $image));

        self::$applying = true;

        try {
            $this->cropAndStore($user, $image, $x, $y, $z);

            $user->avatar_focus_x = $x;
            $user->avatar_focus_y = $y;
            $user->avatar_zoom = $z;

            $user->save();
            $this->dispatchEventsFor($user, $actor);
        } finally {
            self::$applying = false;
        }

        return $user;
    }

    public function enabled(): bool
    {
        return (bool) $this->settings->get('tryhackx-cover-studio.avatar_focus');
    }

    protected function assertEnabled(): void
    {
        if (!$this->enabled()) {
            throw new ValidationException([
                'avatar' => $this->translator->trans('tryhackx-cover-studio.api.avatar_focus_disabled'),
            ]);
        }
    }

    protected function readImage(string $bytes): ImageInterface
    {
        // Decompression-bomb guard: reject absurd pixel counts from the image
        // HEADER before intervention decodes the full bitmap into memory.
        $info = @getimagesizefromstring($bytes);

        if ($info !== false && ($info[0] ?? 0) * ($info[1] ?? 0) > CoverValidator::MAX_PIXELS) {
            throw new ValidationException([
                'avatar' => $this->translator->trans('tryhackx-cover-studio.api.original_unreadable'),
            ]);
        }

        try {
            return $this->imageManager->read($bytes);
        } catch (\Exception) {
            throw new ValidationException([
                'avatar' => $this->translator->trans('tryhackx-cover-studio.api.original_unreadable'),
            ]);
        }
    }

    /**
     * Crop a square window around the focal point, then generate the standard
     * core avatar variants.
     *
     * zoom > 1  → the window shrinks (magnification), always inside the image.
     * zoom = 1  → largest square that fits (full source resolution).
     * zoom < 1  → deliberate pull-back: the window is LARGER than the image on
     *             at least one axis; the exposed bands are filled with a
     *             blurred, slightly darkened copy of the image — the same
     *             effect covers render with CSS.
     */
    protected function cropAndStore(User $user, ImageInterface $image, float $x, float $y, float $zoom = Focus::ZOOM_DEFAULT): void
    {
        $width = $image->width();
        $height = $image->height();

        $side = (int) round(min($width, $height) / max(0.01, $zoom));

        // Zooming in should not produce mush: keep at least the base avatar
        // size (unless the source itself is smaller).
        $side = max(min(100, min($width, $height)), $side);

        if ($side > 1600) {
            // Zoomed-out windows can get big (up to 2× the shorter edge). The
            // largest avatar variant is 300px, so compose on a bounded working
            // canvas to keep image-driver memory in check.
            $scale = 1600 / $side;
            $image = $image->scale(
                (int) max(1, round($width * $scale)),
                (int) max(1, round($height * $scale))
            );
            $width = $image->width();
            $height = $image->height();
            $side = 1600;
        }

        // IMPORTANT: the window origin uses CSS object-position semantics —
        // "align the p% point of the image with the p% point of the window",
        // i.e. origin = p * (imageSize - windowSize). This is EXACTLY what the
        // client-side preview renders (object-position + transform-origin), so
        // the saved avatar matches the preview 1:1. A "window centered on the
        // focal point" formula looks similar but diverges everywhere except at
        // 0/50/100%. As a bonus, the origin is automatically within bounds for
        // any p in [0, 100], whichever sign (imageSize - windowSize) has.
        if ($side <= $width && $side <= $height) {
            // Window fits inside the image — plain crop at full resolution.
            $left = (int) round($x / 100 * ($width - $side));
            $top = (int) round($y / 100 * ($height - $side));

            $square = $image->crop($side, $side, $left, $top);
        } else {
            $square = $this->composeZoomedOut($image, $side, $x, $y);
        }

        $variant = fn (int $size): ImageInterface => (clone $square)->cover($size, $size);

        // Mirror core's AvatarUploader: never upscale — HiDPI variants are only
        // generated when the composed square is large enough.
        $this->avatarUploader->uploadPresized(
            $user,
            $variant(100),
            $side >= 200 ? $variant(200) : null,
            $side >= 300 ? $variant(300) : null
        );
    }

    /**
     * Compose a zoomed-out square: a blurred cover-fill of the image as the
     * backdrop, with the sharp image placed on top, positioned by the focal
     * point wherever there is room to pan.
     */
    protected function composeZoomedOut(ImageInterface $image, int $side, float $x, float $y): ImageInterface
    {
        $width = $image->width();
        $height = $image->height();

        // Blurred backdrop: blur at a small working size, then upscale — far
        // cheaper than blurring the full canvas, and visually smoother.
        $backdrop = (clone $image)->cover(min($side, 400), min($side, 400));
        $backdrop->blur(30);
        $backdrop->brightness(-12);
        $backdrop->resize($side, $side);

        // Window origin in image coordinates — same object-position semantics
        // as the plain-crop branch (see cropAndStore). For an axis where the
        // window exceeds the image the origin is negative and the image floats
        // inside the window, exactly like the CSS preview.
        $x0 = (int) round($x / 100 * ($width - $side));
        $y0 = (int) round($y / 100 * ($height - $side));

        // Visible part of the image (intersection with the window) — cropping
        // first avoids negative placement offsets.
        $srcX = max(0, $x0);
        $srcY = max(0, $y0);
        $srcW = min($width, $x0 + $side) - $srcX;
        $srcH = min($height, $y0 + $side) - $srcY;

        $visible = (clone $image)->crop($srcW, $srcH, $srcX, $srcY);

        $backdrop->place($visible, 'top-left', $srcX - $x0, $srcY - $y0);

        return $backdrop;
    }
}
