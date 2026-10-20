import app from 'flarum/forum/app';
import Modal, { IInternalModalAttrs } from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type User from 'flarum/common/models/User';
import type Mithril from 'mithril';

import FocusDragArea from '../../common/components/FocusDragArea';
import { mediaPickerAvailable, openMediaPicker } from '../mediaPicker';
import { clampZoom } from '../../common/util';

interface AvatarFocusModalAttrs extends IInternalModalAttrs {
  user: User;
}

/**
 * Avatar focal-point editor. Shows the stored ORIGINAL (uncropped) avatar
 * image behind a circular mask; dragging/zooming picks which region the
 * server re-crops the real avatar files from. A new source image can be
 * uploaded here or picked from the media manager — repositioning itself
 * never requires a re-upload.
 */
export default class AvatarFocusModal extends Modal<AvatarFocusModalAttrs> {
  loading = false;
  focusX = 50;
  focusY = 50;
  zoom = 1;
  dirty = false;

  oninit(vnode: Mithril.Vnode<AvatarFocusModalAttrs, this>) {
    super.oninit(vnode);

    this.syncFromUser();
  }

  className() {
    return 'CoverStudio-Modal CoverStudio-AvatarModal Modal--small';
  }

  title() {
    return app.translator.trans('tryhackx-cover-studio.forum.avatar.title');
  }

  protected get user(): User {
    return this.attrs.user;
  }

  protected syncFromUser() {
    this.focusX = Number(this.user.avatarFocusX() ?? 50);
    this.focusY = Number(this.user.avatarFocusY() ?? 50);
    this.zoom = clampZoom(Number(this.user.avatarZoom() ?? 1));
    this.dirty = false;
  }

  content() {
    const originalUrl = this.user.avatarOriginalUrl();

    return (
      <div className="Modal-body CoverStudio-Editor">
        {originalUrl ? (
          [
            <FocusDragArea
              url={originalUrl}
              focusX={this.focusX}
              focusY={this.focusY}
              zoom={this.zoom}
              circle
              onchange={(x: number, y: number) => {
                this.focusX = x;
                this.focusY = y;
                this.dirty = true;
              }}
              onzoom={this.setZoom.bind(this)}
            />,
            this.zoomControl(),
            <p className="helpText CoverStudio-Editor-sizeHint">
              {app.translator.trans('tryhackx-cover-studio.forum.avatar.hint')}
            </p>,
          ]
        ) : (
          <div className="CoverStudio-Editor-empty CoverStudio-Editor-empty--avatar">
            {this.loading ? (
              <LoadingIndicator />
            ) : (
              <p>{app.translator.trans('tryhackx-cover-studio.forum.avatar.no_original')}</p>
            )}
          </div>
        )}

        <div className="CoverStudio-Editor-actions">
          <Button className="Button" icon="fas fa-upload" loading={this.loading} onclick={this.openFilePicker.bind(this)}>
            {app.translator.trans('tryhackx-cover-studio.forum.editor.upload_button')}
          </Button>

          {mediaPickerAvailable(this.user) && (
            <Button className="Button" icon="fas fa-photo-video" disabled={this.loading} onclick={this.openMediaManager.bind(this)}>
              {app.translator.trans('tryhackx-cover-studio.forum.editor.media_button')}
            </Button>
          )}
        </div>

        {!!originalUrl && (
          <div className="CoverStudio-Editor-save">
            <Button
              className="Button Button--primary"
              icon="fas fa-check"
              loading={this.loading}
              disabled={!this.dirty}
              onclick={this.saveFocus.bind(this)}
            >
              {app.translator.trans('tryhackx-cover-studio.forum.editor.save_position_button')}
            </Button>
          </div>
        )}
      </div>
    );
  }

  protected setZoom(zoom: number) {
    this.zoom = clampZoom(zoom);
    this.dirty = true;
  }

  protected zoomControl(): Mithril.Children {
    return (
      <div className="CoverStudio-Editor-zoom">
        <label htmlFor="CoverStudio-avatar-zoom">
          {app.translator.trans('tryhackx-cover-studio.forum.editor.zoom_label')}
        </label>
        <input
          id="CoverStudio-avatar-zoom"
          type="range"
          min="0.5"
          max="3"
          step="0.05"
          list="CoverStudio-zoom-ticks-avatar"
          value={this.zoom}
          disabled={this.loading}
          oninput={(e: InputEvent) => this.setZoom(parseFloat((e.target as HTMLInputElement).value))}
        />
        <datalist id="CoverStudio-zoom-ticks-avatar">
          <option value="1" />
        </datalist>
        <button
          type="button"
          className="Button Button--link CoverStudio-Editor-zoomValue"
          title={app.translator.trans('tryhackx-cover-studio.forum.editor.zoom_reset') as unknown as string}
          disabled={this.loading}
          onclick={() => this.setZoom(1)}
        >
          {this.zoom.toFixed(2)}×
        </button>
      </div>
    );
  }

  protected openFilePicker() {
    if (this.loading) return;

    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/jpeg,image/png,image/webp,image/gif';

    input.addEventListener('change', () => {
      const file = input.files?.[0];
      if (file) this.upload(file);
    });

    input.click();
  }

  protected upload(file: File) {
    const body = new FormData();
    body.append('avatar', file);
    // A fresh image starts centered, without zoom.
    body.append('focusX', '50');
    body.append('focusY', '50');
    body.append('zoom', '1');

    this.request({ method: 'POST', body, serialize: (raw: FormData) => raw });
  }

  protected openMediaManager() {
    openMediaPicker(this.user, (fileId) => {
      this.request({
        method: 'POST',
        body: { fileId, focusX: 50, focusY: 50, zoom: 1 },
      });
    });
  }

  protected saveFocus() {
    if (this.loading || !this.dirty) return;

    this.request({ method: 'PATCH', body: { focusX: this.focusX, focusY: this.focusY, zoom: this.zoom } });
  }

  protected request(options: { method: string; body?: unknown; serialize?: (raw: never) => unknown }) {
    this.loading = true;
    this.alertAttrs = null;
    m.redraw();

    app
      .request({
        url: `${app.forum.attribute('apiUrl')}/users/${this.user.id()}/cover-studio/avatar`,
        method: options.method,
        body: options.body,
        ...(options.serialize ? { serialize: options.serialize } : {}),
      })
      .then((response) => {
        if (response) app.store.pushPayload(response as { data: object });

        // Force avatar color recomputation, same as core's AvatarEditor.
        delete (this.user as unknown as { avatarColor?: unknown }).avatarColor;

        this.loading = false;
        this.syncFromUser();
        this.alertAttrs = {
          type: 'success',
          content: app.translator.trans('tryhackx-cover-studio.forum.editor.saved'),
        };
        m.redraw();
      })
      .catch((error) => {
        this.loading = false;
        this.alertAttrs = {
          type: 'error',
          content:
            error?.response?.errors?.[0]?.detail ||
            app.translator.trans('tryhackx-cover-studio.forum.editor.generic_error'),
        };
        m.redraw();
      });
  }
}
