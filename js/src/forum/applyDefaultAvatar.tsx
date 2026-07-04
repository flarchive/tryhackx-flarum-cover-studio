import app from 'flarum/forum/app';
import { override } from 'flarum/common/extend';
import Avatar from 'flarum/common/components/Avatar';
import classList from 'flarum/common/utils/classList';
import type User from 'flarum/common/models/User';
import type Mithril from 'mithril';

import { clampFocus, clampZoom } from '../common/util';

/**
 * When an admin has configured a forum-wide default avatar, render it for any
 * user who has not uploaded one — in place of core's colored letter avatar.
 *
 * The image is positioned purely with CSS (object-position + a clipped zoom
 * transform, exactly mirroring the cover rendering), so it stays a plain
 * presentational fallback: no per-user files are generated and the user's own
 * `avatarUrl()` is left untouched, so avatar-editing UI keeps treating them as
 * "no avatar yet".
 */
export default function applyDefaultAvatar() {
  override(Avatar.prototype, 'view', function (original: (vnode: Mithril.Vnode) => Mithril.Children, vnode: Mithril.Vnode<{ user?: User | null }>) {
    const user = vnode.attrs.user;

    // Only step in for a real user who has no avatar of their own.
    const url = user && !user.avatarUrl() ? app.forum.attribute<string>('tryhackx-cover-studio.default_avatar_url') : null;
    if (!url) return original(vnode);

    const x = clampFocus(Number(app.forum.attribute('tryhackx-cover-studio.default_avatar_focus_x') ?? 50));
    const y = clampFocus(Number(app.forum.attribute('tryhackx-cover-studio.default_avatar_focus_y') ?? 50));
    const zoom = clampZoom(Number(app.forum.attribute('tryhackx-cover-studio.default_avatar_zoom') ?? 1));

    const { user: _user, ...attrs } = vnode.attrs as Record<string, any>;
    // `loading`/`alt` belong on the <img>, not on the wrapper <span>.
    delete attrs.loading;
    delete attrs.alt;

    const username = user!.displayName() || '?';
    if (attrs.title === undefined) attrs.title = username;

    attrs.className = classList('Avatar', 'CoverStudio-defaultAvatar', attrs.className, zoom < 1 && 'CoverStudio-zoomOut');
    attrs.style = {
      ...(attrs.style && typeof attrs.style === 'object' ? attrs.style : {}),
      '--da-x': `${x}%`,
      '--da-y': `${y}%`,
      '--da-zoom': `${zoom}`,
    };

    // A wrapper <span> clips the zoom transform: the Avatar is circular via
    // border-radius, and overflow:hidden keeps the scaled image inside it.
    return (
      <span {...attrs}>
        {zoom < 1 && (
          // Blurred underlay, visible only when zoomed OUT (< 1×) — the same
          // trick the real cover uses to fill the exposed bands.
          <img className="CoverStudio-defaultAvatar-blur" src={url} alt="" aria-hidden="true" draggable="false" />
        )}
        <img className="CoverStudio-defaultAvatar-image" src={url} alt={username} loading="lazy" draggable="false" />
      </span>
    );
  });
}
