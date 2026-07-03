import app from 'flarum/forum/app';
import { extend, override } from 'flarum/common/extend';
import AvatarEditor from 'flarum/forum/components/AvatarEditor';
import Button from 'flarum/common/components/Button';
import type User from 'flarum/common/models/User';
import type ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';

import AvatarFocusModal from './components/AvatarFocusModal';

function featureActive(user: User | undefined): boolean {
  return (
    !!app.forum.attribute('tryhackx-cover-studio.avatar_focus') &&
    !!user?.canSetAvatarFocus?.()
  );
}

/**
 * When avatar focal-point mode is enabled:
 *
 * 1. avatar uploads are routed through Cover Studio's endpoint so the original
 *    image is preserved in the media manager (core's endpoint discards it),
 * 2. an "Adjust position" entry appears in the avatar dropdown, which re-crops
 *    the avatar server-side from that original — no re-upload needed,
 * 3. for users WITHOUT an avatar, clicking the avatar opens our modal (with
 *    upload + "choose from My Media") instead of core's bare file picker.
 */
export default function extendAvatarEditor() {
  extend(AvatarEditor.prototype, 'controlItems', function (this: AvatarEditor, items: ItemList<Mithril.Children>) {
    const user = (this.attrs as { user?: User }).user;

    // Shown even without a stored original: the modal itself offers uploading
    // a new image or picking one from the media manager.
    if (!featureActive(user)) return;

    items.add(
      'cover-studio-focus',
      <Button icon="fas fa-crosshairs" onclick={() => app.modal.show(AvatarFocusModal, { user })}>
        {app.translator.trans('tryhackx-cover-studio.forum.avatar.adjust_button')}
      </Button>,
      -5
    );
  });

  // Route EVERY core path into the file picker through our modal instead:
  // the dropdown "Upload" item calls openPicker directly, and for users
  // without an avatar core's quickUpload also lands in openPicker. The modal
  // offers uploading AND picking from the media manager, followed directly by
  // positioning — so the positioning step can never be skipped accidentally.
  override(AvatarEditor.prototype, 'openPicker', function (this: AvatarEditor, original: () => void) {
    const user = (this.attrs as { user?: User }).user;

    if (!featureActive(user)) {
      return original();
    }

    app.modal.show(AvatarFocusModal, { user });
  });

  override(AvatarEditor.prototype, 'upload', function (this: AvatarEditor, original: (file: File) => void, file: File) {
    const user = (this.attrs as { user?: User }).user;

    if (!featureActive(user)) {
      return original(file);
    }

    const self = this as unknown as {
      loading: boolean;
      success: (response: unknown) => void;
      failure: (response: unknown) => void;
    };

    if (self.loading) return;

    const body = new FormData();
    body.append('avatar', file);
    body.append('focusX', '50');
    body.append('focusY', '50');

    self.loading = true;
    m.redraw();

    app
      .request({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/users/${user!.id()}/cover-studio/avatar`,
        serialize: (raw: FormData) => raw,
        body,
      })
      .then(self.success.bind(self), self.failure.bind(self));
  });
}
