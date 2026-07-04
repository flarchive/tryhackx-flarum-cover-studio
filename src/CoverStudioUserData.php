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

use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cover / avatar-focus data for a single user, kept OUT of the hot `users`
 * table so that widening it for millions of rows never slows down the queries
 * that serialise authors on every discussion list.
 *
 * A row exists only for users who actually have a cover or an avatar original
 * (see saveOrPurge()), so on large forums this table stays small and its
 * user_id-keyed lookups are cheap. It is eager-loaded on every User query via
 * the global scope registered in CoverStudioServiceProvider — one batched
 * `WHERE user_id IN (…)` per request, never N+1.
 *
 * @property int         $user_id
 * @property int|null    $cover_file_id
 * @property string|null $cover_url
 * @property string|null $cover_thumb_url
 * @property float       $cover_focus_x
 * @property float       $cover_focus_y
 * @property float       $cover_zoom
 * @property int|null    $avatar_file_id
 * @property string|null $avatar_original_url
 * @property float       $avatar_focus_x
 * @property float       $avatar_focus_y
 * @property float       $avatar_zoom
 */
class CoverStudioUserData extends AbstractModel
{
    protected $table = 'cover_studio_user_data';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $guarded = [];

    protected $casts = [
        'user_id'        => 'int',
        'cover_file_id'  => 'int',
        'cover_focus_x'  => 'float',
        'cover_focus_y'  => 'float',
        'cover_zoom'     => 'float',
        'avatar_file_id' => 'int',
        'avatar_focus_x' => 'float',
        'avatar_focus_y' => 'float',
        'avatar_zoom'    => 'float',
    ];

    /**
     * Process-level memo for tableAvailable().
     */
    protected static ?bool $tableAvailable = null;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the companion row for $user, creating (but not persisting) a fresh
     * one linked to the eager-loaded relation when none exists yet. Callers
     * mutate the returned instance and finish with saveOrPurge().
     */
    public static function forUser(User $user): self
    {
        $data = $user->coverStudioData;

        if ($data === null) {
            $data = new self();
            $data->user_id = $user->id;

            // Keep the in-memory relation in sync so the serializer that runs
            // right after the write sees the new values without a re-query.
            $user->setRelation('coverStudioData', $data);
        }

        return $data;
    }

    /**
     * Persist the row, or delete it when it carries neither a cover nor an
     * avatar original — keeping the table sparse on large forums.
     */
    public function saveOrPurge(): void
    {
        if ($this->cover_file_id === null && $this->avatar_file_id === null) {
            if ($this->exists) {
                $this->delete();
            }

            return;
        }

        $this->save();
    }

    /**
     * Whether the companion table exists yet, memoised for the process.
     *
     * Guards the eager-loading global scope against the brief window during an
     * upgrade where the extension is booted but this migration has not run —
     * without it, a pending migration could break every User query.
     *
     * The connection is taken from a model instance (native
     * Model::getConnection()) rather than from the User query builder: fetching
     * it off the builder would route through __call → applyScopes and recurse
     * back into the very scope that calls this.
     */
    public static function tableAvailable(): bool
    {
        if (self::$tableAvailable === null) {
            $instance = new self();

            self::$tableAvailable = $instance->getConnection()
                ->getSchemaBuilder()
                ->hasTable($instance->getTable());
        }

        return self::$tableAvailable;
    }
}
