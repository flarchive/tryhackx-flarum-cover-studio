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
            if (!$schema->hasColumn('users', 'cover_zoom')) {
                // Zoom factor over the base "cover" fit (1.00 = no zoom).
                $table->decimal('cover_zoom', 4, 2)->unsigned()->default(1);
            }

            if (!$schema->hasColumn('users', 'avatar_zoom')) {
                // Avatar crop zoom: the square crop side is min(w,h) / zoom.
                $table->decimal('avatar_zoom', 4, 2)->unsigned()->default(1);
            }
        });
    },

    'down' => function (Builder $schema) {
        $schema->table('users', function (Blueprint $table) use ($schema) {
            if ($schema->hasColumn('users', 'cover_zoom')) {
                $table->dropColumn('cover_zoom');
            }

            if ($schema->hasColumn('users', 'avatar_zoom')) {
                $table->dropColumn('avatar_zoom');
            }
        });
    },
];
