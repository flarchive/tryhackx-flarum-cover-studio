import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import UserControls from 'flarum/forum/utils/UserControls';
import Button from 'flarum/common/components/Button';
import type User from 'flarum/common/models/User';
import type ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';

import CoverEditorModal from './components/CoverEditorModal';

/**
 * Adds an "Edit cover" entry to the user controls dropdown (user page hero and
 * hover cards) for anyone allowed to change that user's cover.
 */
export default function addCoverControls() {
  extend(UserControls, 'userControls', (items: ItemList<Mithril.Children>, user: User) => {
    if (!user.canSetCover?.()) return;

    items.add(
      'cover-studio',
      <Button icon="fas fa-panorama" onclick={() => app.modal.show(CoverEditorModal, { user })}>
        {app.translator.trans('tryhackx-cover-studio.forum.controls.edit_cover')}
      </Button>
    );
  });
}
