import app from 'flarum/forum/app';
import type User from 'flarum/common/models/User';

// fof/upload is a hard composer dependency, so its bundle is guaranteed to be
// loaded before ours; these resolve through the module registry at load time.
// Guarded below anyway, in case the extension is disabled.
import FileManagerModal from 'ext:fof/upload/forum/components/FileManagerModal';
import Uploader from 'ext:fof/upload/forum/handler/Uploader';

/**
 * Whether the "choose from My Media" flow can be offered for $user's profile:
 * fof/upload's frontend must be active, the admin must not have disabled the
 * picker, and the acting user must be allowed to browse the target user's
 * media library.
 */
export function mediaPickerAvailable(user: User): boolean {
  if (!app.forum.attribute('tryhackx-cover-studio.media_picker')) return false;
  if (!('fof-upload' in flarum.extensions) || !FileManagerModal || !Uploader) return false;

  const sessionUser = app.session.user;
  if (!sessionUser) return false;

  return user.id() === sessionUser.id() || !!sessionUser.attribute('fof-upload-viewOthersMediaLibrary');
}

/**
 * Open fof/upload's media manager as a single-image picker (stacked on top of
 * the current modal) and hand the chosen file id to the callback.
 */
export function openMediaPicker(user: User, onSelect: (fileId: string) => void): void {
  app.modal.show(
    FileManagerModal,
    {
      uploader: new Uploader(),
      user,
      hideShared: true,
      multiSelect: false,
      restrictFileType: 'image',
      onSelect: (fileIds: string[]) => {
        if (fileIds.length) onSelect(fileIds[0]);
      },
    },
    true
  );

  // The modal's focus trap auto-focuses the first tabbable control — the
  // Upload button — which then sits in its :focus state with no pointer
  // anywhere near it. Hand the initial focus to the dialog itself instead;
  // Tab still reaches the button as the first stop. Retried briefly because
  // the trap activates after the modal's fade-in.
  const stealFocus = (attempt: number): void => {
    const active = document.activeElement as HTMLElement | null;

    if (active?.closest('.fof-modal-buttons')) {
      const dialog = document.querySelector<HTMLElement>('.fof-file-manager-modal .Modal-content');

      if (dialog) {
        dialog.tabIndex = -1;
        dialog.focus({ preventScroll: true });
        return;
      }
    }

    if (attempt < 5) setTimeout(() => stealFocus(attempt + 1), 150);
  };

  setTimeout(() => stealFocus(0), 120);
}
