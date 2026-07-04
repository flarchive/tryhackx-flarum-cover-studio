<?php

/*
 * This file is part of tryhackx/flarum-cover-studio.
 *
 * Copyright (c) TryHackX.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->table('users', function (Blueprint $table) use ($schema) {
            if (!$schema->hasColumn('users', 'cover_file_id')) {
                // Reference to the fof/upload file backing the profile cover.
                // ON DELETE SET NULL guarantees the cover disappears the moment
                // the file is removed through the media manager — even if the
                // Eloquent cleanup listener were ever bypassed.
                $table->unsignedInteger('cover_file_id')->nullable();
                $table->foreign('cover_file_id')
                    ->references('id')
                    ->on('fof_upload_files')
                    ->nullOnDelete();

                // Denormalized copies of the file URLs. Serialized on every user
                // payload (discussion lists include authors), so reading them via
                // the relation would cause N+1 queries. fof/upload never rewrites
                // these URLs after upload; cleanup is handled by the listener.
                $table->string('cover_url', 500)->nullable();
                $table->string('cover_thumb_url', 500)->nullable();

                // Focal point of the cover, as percentages (CSS background-position).
                $table->decimal('cover_focus_x', 5, 2)->unsigned()->default(50);
                $table->decimal('cover_focus_y', 5, 2)->unsigned()->default(50);
            }

            if (!$schema->hasColumn('users', 'avatar_file_id')) {
                // Reference to the fof/upload file holding the ORIGINAL (uncropped)
                // avatar image, used to re-crop when the focal point changes.
                $table->unsignedInteger('avatar_file_id')->nullable();
                $table->foreign('avatar_file_id')
                    ->references('id')
                    ->on('fof_upload_files')
                    ->nullOnDelete();

                $table->string('avatar_original_url', 500)->nullable();

                $table->decimal('avatar_focus_x', 5, 2)->unsigned()->default(50);
                $table->decimal('avatar_focus_y', 5, 2)->unsigned()->default(50);
            }
        });
    },

    'down' => function (Builder $schema) {
        $schema->table('users', function (Blueprint $table) use ($schema) {
            if ($schema->hasColumn('users', 'cover_file_id')) {
                $table->dropForeign(['cover_file_id']);
                $table->dropColumn([
                    'cover_file_id',
                    'cover_url',
                    'cover_thumb_url',
                    'cover_focus_x',
                    'cover_focus_y',
                ]);
            }

            if ($schema->hasColumn('users', 'avatar_file_id')) {
                $table->dropForeign(['avatar_file_id']);
                $table->dropColumn([
                    'avatar_file_id',
                    'avatar_original_url',
                    'avatar_focus_x',
                    'avatar_focus_y',
                ]);
            }
        });
    },
];
