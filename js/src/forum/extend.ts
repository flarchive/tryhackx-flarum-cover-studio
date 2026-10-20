import Extend from 'flarum/common/extenders';
import User from 'flarum/common/models/User';

export default [
  new Extend.Model(User)
    .attribute<string | null>('coverUrl')
    .attribute<string | null>('coverThumbUrl')
    .attribute<number>('coverFocusX')
    .attribute<number>('coverFocusY')
    .attribute<number>('coverZoom')
    .attribute<boolean>('canSetCover')
    .attribute<string | null>('avatarOriginalUrl')
    .attribute<number>('avatarFocusX')
    .attribute<number>('avatarFocusY')
    .attribute<number>('avatarZoom')
    .attribute<boolean>('canSetAvatarFocus'),
];
