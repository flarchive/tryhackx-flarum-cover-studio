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

/**
 * Move the cover / avatar-focus data OFF the users table into a dedicated
 * companion table.
 *
 * The eleven columns added by the two earlier migrations widen every users row
 * — and users are read (and serialised as authors) on nearly every page. On
 * forums with hundreds of thousands to millions of users that hurts buffer-pool
 * efficiency and cold reads. Moving the data into a sparse, user_id-keyed table
 * keeps users lean while a global eager-load (CoverStudioServiceProvider) still
 * avoids any N+1.
 *
 * Transitional and reversible: existing data is copied across before the old
 * columns are dropped, and down() restores the previous shape.
 */

$columns = [
    'cover_file_id', 'cover_url', 'cover_thumb_url', 'cover_focus_x', 'cover_focus_y', 'cover_zoom',
    'avatar_file_id', 'avatar_original_url', 'avatar_focus_x', 'avatar_focus_y', 'avatar_zoom',
];

$addUserColumns = function (Blueprint $table): void {
    $table->unsignedInteger('cover_file_id')->nullable();
    $table->foreign('cover_file_id')->references('id')->on('fof_upload_files')->nullOnDelete();
    $table->string('cover_url', 500)->nullable();
    $table->string('cover_thumb_url', 500)->nullable();
    $table->decimal('cover_focus_x', 5, 2)->default(50);
    $table->decimal('cover_focus_y', 5, 2)->default(50);
    $table->decimal('cover_zoom', 4, 2)->default(1);

    $table->unsignedInteger('avatar_file_id')->nullable();
    $table->foreign('avatar_file_id')->references('id')->on('fof_upload_files')->nullOnDelete();
    $table->string('avatar_original_url', 500)->nullable();
    $table->decimal('avatar_focus_x', 5, 2)->default(50);
    $table->decimal('avatar_focus_y', 5, 2)->default(50);
    $table->decimal('avatar_zoom', 4, 2)->default(1);
};

return [
    'up' => function (Builder $schema) use ($columns) {
        if (!$schema->hasTable('cover_studio_user_data')) {
            $schema->create('cover_studio_user_data', function (Blueprint $table) {
                // One row per user; disappears with the user (cascade) and loses
                // a file reference the moment the media file is deleted (SET NULL,
                // mirroring the previous on-users foreign keys). The two FK
                // indexes also keep the file-deletion cleanup lookups fast.
                $table->unsignedInteger('user_id')->primary();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

                $table->unsignedInteger('cover_file_id')->nullable();
                $table->foreign('cover_file_id')->references('id')->on('fof_upload_files')->nullOnDelete();
                $table->string('cover_url', 500)->nullable();
                $table->string('cover_thumb_url', 500)->nullable();
                $table->decimal('cover_focus_x', 5, 2)->default(50);
                $table->decimal('cover_focus_y', 5, 2)->default(50);
                $table->decimal('cover_zoom', 4, 2)->default(1);

                $table->unsignedInteger('avatar_file_id')->nullable();
                $table->foreign('avatar_file_id')->references('id')->on('fof_upload_files')->nullOnDelete();
                $table->string('avatar_original_url', 500)->nullable();
                $table->decimal('avatar_focus_x', 5, 2)->default(50);
                $table->decimal('avatar_focus_y', 5, 2)->default(50);
                $table->decimal('avatar_zoom', 4, 2)->default(1);
            });
        }

        if ($schema->hasColumn('users', 'cover_file_id')) {
            $db = $schema->getConnection();

            // Copy only the rows that actually carry a cover or avatar original,
            // so the companion table starts out sparse. INSERT … SELECT is a
            // single pass and portable across MySQL / PostgreSQL / SQLite.
            $db->table('cover_studio_user_data')->insertUsing(
                array_merge(['user_id'], $columns),
                $db->table('users')
                    ->select(array_merge(['id'], $columns))
                    ->where(function ($query) {
                        $query->whereNotNull('cover_file_id')->orWhereNotNull('avatar_file_id');
                    })
            );

            // Drop the FKs before the columns, then remove all eleven in one
            // ALTER to minimise the number of table rebuilds on large forums.
            $schema->table('users', function (Blueprint $table) use ($columns) {
                $table->dropForeign(['cover_file_id']);
                $table->dropForeign(['avatar_file_id']);
                $table->dropColumn($columns);
            });
        }
    },

    'down' => function (Builder $schema) use ($columns, $addUserColumns) {
        if (!$schema->hasColumn('users', 'cover_file_id')) {
            $schema->table('users', function (Blueprint $table) use ($addUserColumns) {
                $addUserColumns($table);
            });
        }

        if ($schema->hasTable('cover_studio_user_data')) {
            $db = $schema->getConnection();

            $db->table('cover_studio_user_data')->orderBy('user_id')->chunk(1000, function ($rows) use ($db, $columns) {
                foreach ($rows as $row) {
                    $values = [];

                    foreach ($columns as $column) {
                        $values[$column] = $row->{$column};
                    }

                    $db->table('users')->where('id', $row->user_id)->update($values);
                }
            });

            $schema->drop('cover_studio_user_data');
        }
    },
];
