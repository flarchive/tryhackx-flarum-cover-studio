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
                // No ->unsigned(): MySQL-only modifier; the 0.50–4.00 range is
                // enforced in Focus::parseZoom() before any write.
                $table->decimal('cover_zoom', 4, 2)->default(1);
            }

            if (!$schema->hasColumn('users', 'avatar_zoom')) {
                // Avatar crop zoom: the square crop side is min(w,h) / zoom.
                $table->decimal('avatar_zoom', 4, 2)->default(1);
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
