<?php

/*
 * This file is part of tryhackx/flarum-cover-studio.
 *
 * Copyright (c) TryHackX.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace TryHackX\CoverStudio\Console;

use Carbon\Carbon;
use Flarum\Foundation\Paths;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use FoF\Upload\Adapters\Flysystem;
use FoF\Upload\Contracts\Template;
use FoF\Upload\File;
use FoF\Upload\Helpers\Util;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Intervention\Image\ImageManager;
use Ramsey\Uuid\Uuid;
use TryHackX\CoverStudio\Support\Focus;

/**
 * One-time importer: moves covers created by sycho/flarum-profile-cover into
 * Cover Studio. Each legacy cover file (public/assets/covers/*) is registered
 * as a proper fof/upload file — owned by the profile owner, visible in their
 * media manager — and linked as the user's Cover Studio cover.
 *
 * Non-destructive: the legacy files and the users.cover column are left
 * untouched, so sycho's extension keeps working until you disable it.
 */
class MigrateSychoCommand extends Command
{
    protected $signature = 'cover-studio:migrate-sycho
        {--dry-run : List what would be migrated without changing anything}
        {--force : Re-import even for users who already have a Cover Studio cover}
    ';

    protected $description = 'Import existing sycho/flarum-profile-cover covers into Cover Studio (via fof/upload)';

    public function handle(
        Paths $paths,
        Util $util,
        ImageManager $imageManager,
        ConnectionInterface $db,
        SettingsRepositoryInterface $settings
    ): void {
        $schema = $db->getSchemaBuilder();

        if (!$schema->hasColumn('users', 'cover')) {
            $this->error('The users.cover column does not exist — sycho/flarum-profile-cover is not installed (or was already purged). Nothing to migrate.');

            return;
        }

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $makeThumbs = (bool) $settings->get('fof-upload.generateThumbnails', true);
        $thumbMaxWidth = max(1, (int) $settings->get('fof-upload.thumbnailMaxWidth', 1000));
        $coversDir = $paths->public . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'covers';

        $query = User::query()
            ->whereNotNull('cover')
            ->where('cover', '!=', '');

        if (!$force) {
            $query->whereNull('cover_file_id');
        }

        $total = $query->count();

        if ($total === 0) {
            $this->info('No legacy covers to migrate.');

            return;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Found $total user(s) with a legacy cover.");

        $migrated = 0;
        $skipped = 0;

        $query->orderBy('id')->each(function (User $user) use ($coversDir, $util, $imageManager, $dryRun, &$migrated, &$skipped) {
            // Defensive: sycho stores bare random filenames, but never trust
            // stored values when composing filesystem paths.
            $name = basename((string) $user->cover);
            $source = $coversDir . DIRECTORY_SEPARATOR . $name;

            if ($name === '' || !is_file($source)) {
                $this->warn("- {$user->username} (#{$user->id}): file '{$name}' not found, skipped.");
                $skipped++;

                return;
            }

            $bytes = file_get_contents($source);
            $info = $bytes !== false ? @getimagesizefromstring($bytes) : false;

            $allowed = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp',
                'image/gif'  => 'gif',
            ];

            if ($info === false || !isset($allowed[$info['mime']])) {
                $this->warn("- {$user->username} (#{$user->id}): '{$name}' is not a supported image, skipped.");
                $skipped++;

                return;
            }

            $mime = $info['mime'];

            if ($dryRun) {
                $this->line("- {$user->username} (#{$user->id}): would import '{$name}' ({$info[0]}x{$info[1]}, {$mime}).");
                $migrated++;

                return;
            }

            try {
                $adapter = $util->getAdapterForMime($mime);

                if (!$adapter instanceof Flysystem) {
                    $this->warn("- {$user->username} (#{$user->id}): no usable storage adapter for {$mime}, skipped.");
                    $skipped++;

                    return;
                }

                // Guaranteed-unique uuid, mirroring FileRepository.
                do {
                    $uuid = Uuid::uuid4()->toString();
                } while (File::byUuid($uuid)->exists());

                $file = new File();
                $file->forceFill([
                    'uuid'         => $uuid,
                    'base_name'    => $name,
                    'size'         => strlen($bytes),
                    'type'         => $mime,
                    'actor_id'     => $user->id,
                    'hidden'       => false,
                    'shared'       => false,
                    'image_width'  => $info[0],
                    'image_height' => $info[1],
                    'created_at'   => Carbon::now(),
                ]);

                // Pre-generate the thumbnail so the adapter writes it in the
                // same pass (same output as fof/upload's ImageProcessor).
                // Respects the forum-wide fof-upload.generateThumbnails setting.
                if ($makeThumbs) {
                    try {
                        $thumb = $imageManager->read($bytes);
                        $thumb->scaleDown(width: $thumbMaxWidth);
                        $file->thumbnailContent = $thumb->toWebp(quality: 80)->toString();
                        $file->thumbnailExtension = 'webp';
                    } catch (\Throwable) {
                        // Thumbnail is optional; continue without one.
                    }
                }

                $uploaded = $adapter->upload($file, null, $bytes);

                if (!($uploaded instanceof File)) {
                    $this->warn("- {$user->username} (#{$user->id}): storage upload failed, skipped.");
                    $skipped++;

                    return;
                }

                $file = $uploaded;
                $file->upload_method = $util->setMethod($adapter);

                $template = $util->getTemplate('image-preview');

                if ($template instanceof Template) {
                    $file->tag = $template;
                }

                $file->save();

                $user->cover_file_id = $file->id;
                $user->cover_url = $file->url;
                $user->cover_thumb_url = $file->thumbnail_url;
                $user->cover_focus_x = Focus::DEFAULT;
                $user->cover_focus_y = Focus::DEFAULT;
                $user->saveQuietly();

                $this->line("- {$user->username} (#{$user->id}): imported '{$name}'.");
                $migrated++;
            } catch (\Throwable $e) {
                $this->warn("- {$user->username} (#{$user->id}): {$e->getMessage()}");
                $skipped++;
            }
        });

        $this->newLine();
        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Done. Migrated: $migrated, skipped: $skipped.");

        if (!$dryRun && $migrated > 0) {
            $this->comment('Legacy files in assets/covers and the users.cover column were left untouched.');
            $this->comment('Once you have verified the result, disable sycho/flarum-profile-cover in the admin panel.');
        }
    }
}
