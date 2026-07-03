import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';

import applyCoverToUserCards from './applyCoverToUserCards';
import addCoverControls from './addCoverControls';
import extendAvatarEditor from './extendAvatarEditor';

export { default as extend } from './extend';

app.initializers.add('tryhackx/flarum-cover-studio', () => {
  applyCoverToUserCards();
  addCoverControls();
  extendAvatarEditor();

  // IMPORTANT: initializers run BEFORE the boot payload is pushed into the
  // store, so app.forum is still undefined at this point. Anything reading
  // forum attributes must wait until mount.
  extend(app, 'mount', () => {
    // Publish admin-configured dimensions/overlay as CSS hooks so the
    // stylesheet stays static and cacheable.
    const root = document.documentElement;
    const height = parseInt(String(app.forum.attribute('tryhackx-cover-studio.cover_height')), 10) || 220;
    const heightMobile = parseInt(String(app.forum.attribute('tryhackx-cover-studio.cover_height_mobile')), 10) || 160;

    root.style.setProperty('--CoverStudio-height', `${Math.max(96, Math.min(height, 600))}px`);
    root.style.setProperty('--CoverStudio-height-mobile', `${Math.max(96, Math.min(heightMobile, 600))}px`);

    const overlay = String(app.forum.attribute('tryhackx-cover-studio.overlay') || 'gradient');
    document.body.classList.add(`CoverStudio-overlay-${['none', 'gradient', 'darken'].includes(overlay) ? overlay : 'gradient'}`);
  });
});
