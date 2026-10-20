<?php

/*
 * This file is part of tryhackx/flarum-cover-studio.
 *
 * Copyright (c) TryHackX.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace TryHackX\CoverStudio\Support;

/**
 * Request-scoped flag marking that AvatarFocusService is itself writing avatar
 * files, so ClearAvatarFocusOnExternalChange can tell our own re-crops apart
 * from external avatar changes (core uploads, OAuth, other extensions) that
 * must invalidate the stored original.
 *
 * Deliberately a container-managed singleton instance rather than a static
 * property. The container is rebuilt per request on every host — PHP-FPM and
 * persistent runtimes (Octane / RoadRunner / Swoole) alike — so a worker that
 * dies mid-re-crop can never leak a stale "applying" state into the next
 * request, which would otherwise silently disable avatar-original cleanup for
 * the rest of that worker's life. Registered as a singleton in
 * CoverStudioServiceProvider so the service and the listener share one instance.
 */
class AvatarApplyGuard
{
    private bool $applying = false;

    public function applying(): bool
    {
        return $this->applying;
    }

    public function begin(): void
    {
        $this->applying = true;
    }

    public function end(): void
    {
        $this->applying = false;
    }
}
