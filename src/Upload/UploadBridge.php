<?php

/*
 * This file is part of tryhackx/flarum-cover-studio.
 *
 * Copyright (c) TryHackX.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace TryHackX\CoverStudio\Upload;

use Carbon\Carbon;
use Flarum\Foundation\Paths;
use Flarum\Foundation\ValidationException;
use Flarum\User\User;
use FoF\Upload\Contracts\Template;
use FoF\Upload\Events;
use FoF\Upload\File;
use FoF\Upload\Helpers\Util;
use FoF\Upload\Repositories\FileRepository;
use GuzzleHttp\Client;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Arr;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Http\Message\UploadedFileInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Bridges Cover Studio with the fof/upload pipeline.
 *
 * Uploads go through the exact same machinery as regular fof/upload files
 * (temp staging, magic-byte mime detection, ImageProcessor via events —
 * orientation fix, dimensions, thumbnails, optional watermark/resize, and the
 * configured storage adapter), so the resulting File rows behave identically
 * in the media manager ("My Media").
 *
 * The one deliberate difference from FoF's own UploadHandler: the File is
 * owned by the PROFILE OWNER (not the acting moderator), because the cover
 * lifecycle must follow the profile owner's media library.
 */
class UploadBridge
{
    /**
     * Real (magic-byte) mime types accepted for covers and avatar originals.
     */
    public const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    /**
     * Basic anti-flood: max uploads through this bridge per owner per minute.
     */
    protected const UPLOADS_PER_MINUTE = 6;

    public function __construct(
        protected Dispatcher $events,
        protected Util $util,
        protected FileRepository $files,
        protected TranslatorInterface $translator,
        protected Paths $paths
    ) {
    }

    /**
     * Push an uploaded image through the fof/upload pipeline.
     *
     * @param UploadedFileInterface $uploadedFile the raw PSR-7 upload
     * @param User                  $actor        who performs the action (permission subject for throttling/events)
     * @param User                  $owner        whose media library the file lands in
     * @param bool                  $shared       store as a shared media file (forum-wide, admin managed)
     *
     * @throws ValidationException
     */
    public function uploadImage(UploadedFileInterface $uploadedFile, User $actor, User $owner, bool $shared = false): File
    {
        $this->assertNotFlooding($owner, $actor);

        $upload = $this->files->moveUploadedFileToTemp($uploadedFile);

        try {
            $mime = (string) $this->files->determineMime($upload);

            if (!in_array($mime, self::ALLOWED_MIMES, true)) {
                throw new ValidationException([
                    'upload' => $this->translator->trans('tryhackx-cover-studio.api.unsupported_type'),
                ]);
            }

            $mimeConfiguration = $this->util->getMimeConfiguration($mime);
            $adapter = $this->util->getAdapter(Arr::get($mimeConfiguration, 'adapter'));

            if (!$adapter || !$adapter->forMime($mime)) {
                // The forum's fof/upload mime configuration does not route
                // images to any storage adapter — an admin configuration issue.
                throw new ValidationException([
                    'upload' => $this->translator->trans('tryhackx-cover-studio.api.no_adapter'),
                ]);
            }

            $file = $this->files->createFileFromUpload($upload, $owner, $mime);

            // Explicitly pin ownership to the profile owner. (createFileFromUpload
            // already does this via $owner, but being explicit guards against
            // upstream signature changes — ownership is security-relevant here.)
            $file->actor_id = $owner->id;
            $file->hidden = false;
            $file->shared = $shared;

            $this->events->dispatch(
                new Events\File\WillBeUploaded($actor, $file, $upload, $mime)
            );

            $response = $adapter->upload(
                $file,
                $upload,
                $this->files->readUpload($upload, $adapter)
            );

            if (!($response instanceof File)) {
                throw new ValidationException([
                    'upload' => $this->translator->trans('tryhackx-cover-studio.api.upload_failed'),
                ]);
            }

            $file = $response;
            $file->upload_method = $this->util->setMethod($adapter);

            $template = $this->util->getTemplate(Arr::get($mimeConfiguration, 'template', 'image-preview'));

            if ($template instanceof Template) {
                $file->tag = $template;
            }

            $this->events->dispatch(
                new Events\File\WillBeSaved($actor, $file, $upload, $mime)
            );

            if ($file->isDirty() || !$file->exists) {
                $file->save();
            }

            $this->events->dispatch(
                new Events\File\WasSaved($actor, $file, $upload, $mime)
            );

            return $file;
        } finally {
            $this->files->removeFromTemp($upload);
        }
    }

    /**
     * Resolve a media-manager file id into a File usable as a cover or avatar
     * original for $owner: it must exist, belong to the owner's personal
     * library (not shared, not private-shared) and be a supported raster
     * image. Every failure mode gets the same generic error — no information
     * leaks about other users' file ids.
     */
    public function resolveUserImage(mixed $fileId, User $owner): File
    {
        $file = is_numeric($fileId) ? File::query()->find((int) $fileId) : null;

        if (
            $file === null
            || $file->actor_id !== $owner->id
            || $file->shared
            || !in_array($file->type, self::ALLOWED_MIMES, true)
        ) {
            throw new ValidationException([
                'file' => $this->translator->trans('tryhackx-cover-studio.api.file_not_eligible'),
            ]);
        }

        return $file;
    }

    /**
     * Resolve a media-manager file id into a File usable as a forum-wide
     * default image: it must exist, be a SHARED file and a supported raster
     * image. Callers must already have asserted admin — this is only reached
     * from the admin default cover/avatar picker.
     */
    public function resolveSharedImage(mixed $fileId): File
    {
        $file = is_numeric($fileId) ? File::query()->find((int) $fileId) : null;

        if (
            $file === null
            || !$file->shared
            || !in_array($file->type, self::ALLOWED_MIMES, true)
        ) {
            throw new ValidationException([
                'file' => $this->translator->trans('tryhackx-cover-studio.api.file_not_eligible'),
            ]);
        }

        return $file;
    }

    /**
     * Read the raw bytes of a previously uploaded file back from storage.
     *
     * Local files are read straight from disk; remote adapters (S3, Imgur,
     * Qiniu, custom) are fetched over HTTP from the stored URL with strict
     * limits. Returns null when the file cannot be retrieved.
     */
    public function readFileContents(File $file): ?string
    {
        if (in_array($file->upload_method, ['local', null, ''], true)) {
            $filesystem = new Filesystem(
                new LocalFilesystemAdapter($this->paths->public . '/assets/files')
            );

            try {
                // League's path normalizer converts Windows-style backslashes
                // (fof's Local adapter stores DIRECTORY_SEPARATOR paths).
                return $filesystem->read(str_replace('\\', '/', $file->path));
            } catch (FilesystemException) {
                return null;
            }
        }

        return $this->fetchRemote($file->url);
    }

    protected function fetchRemote(string $url): ?string
    {
        if (!in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return null;
        }

        $client = new Client([
            'timeout'         => 15,
            'allow_redirects' => ['max' => 3],
            'headers'         => ['Accept' => 'image/*'],
        ]);

        try {
            $response = $client->get($url, ['stream' => true]);
        } catch (\Exception) {
            return null;
        }

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        // Cap the download at 32 MB — originals were size-validated on upload,
        // so anything larger indicates a misbehaving remote.
        $body = $response->getBody();
        $contents = '';

        while (!$body->eof()) {
            $contents .= $body->read(1024 * 512);

            if (strlen($contents) > 32 * 1024 * 1024) {
                return null;
            }
        }

        return $contents !== '' ? $contents : null;
    }

    /**
     * DB-backed upload throttle: at most a handful of pipeline uploads per
     * owner per minute. Admins are exempt.
     */
    protected function assertNotFlooding(User $owner, User $actor): void
    {
        if ($actor->isAdmin()) {
            return;
        }

        $recent = File::query()
            ->where('actor_id', $owner->id)
            ->where('created_at', '>=', Carbon::now()->subMinute())
            ->count();

        if ($recent >= self::UPLOADS_PER_MINUTE) {
            throw new ValidationException([
                'upload' => $this->translator->trans('tryhackx-cover-studio.api.too_many_uploads'),
            ]);
        }
    }
}
