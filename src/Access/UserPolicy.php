<?php

/*
 * This file is part of tryhackx/flarum-cover-studio.
 *
 * Copyright (c) TryHackX.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace TryHackX\CoverStudio\Access;

use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

class UserPolicy extends AbstractPolicy
{
    /**
     * Whether $actor may set/change/remove the profile cover of $user.
     *
     * Own profile requires the dedicated permission; other users' profiles
     * additionally require the core "edit user" ability (moderators).
     */
    public function setCover(User $actor, User $user): string
    {
        if (!$actor->hasPermission('tryhackx-cover-studio.setCover')) {
            return $this->deny();
        }

        if ($actor->id === $user->id || $actor->can('edit', $user)) {
            return $this->allow();
        }

        return $this->deny();
    }

    /**
     * Whether $actor may change the avatar (and its focal point) of $user.
     *
     * Delegates to core's own "editAvatar" ability (Flarum 2.0): the
     * `user.editAvatar` permission for your own avatar, the edit-user ability
     * for someone else's. Anything looser would let users who lost avatar
     * rights change their avatar through this extension's endpoints. The
     * feature toggle is checked separately in the service layer.
     */
    public function setAvatarFocus(User $actor, User $user): string
    {
        if (!$actor->isGuest() && $actor->can('editAvatar', $user)) {
            return $this->allow();
        }

        return $this->deny();
    }
}
