import app from 'flarum/forum/app';
import Modal, { IInternalModalAttrs } from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type User from 'flarum/common/models/User';
import type Mithril from 'mithril';

import FocusDragArea from './FocusDragArea';
import { mediaPickerAvailable, openMediaPicker } from '../mediaPicker';
import { formatBytes, clampZoom } from '../../common/util';

interface CoverEditorModalAttrs extends IInternalModalAttrs {
  user: User;
}

/**
 * Full cover management: upload a new image, reuse one from the media manager,
 * drag the focal point (pos-crop) with live preview, save the position without
 * re-uploading, or detach the cover.
 */
export default class CoverEditorModal extends Modal<CoverEditorModalAttrs> {
  loading = false;
  focusX = 50;
  focusY = 50;
  zoom = 1;
  dirty = false;

  oninit(vnode: Mithril.Vnode<CoverEditorModalAttrs, this>) {
    super.oninit(vnode);

    this.syncFromUser();
  }

  className() {
    return 'CoverStudio-Modal Modal--small';
  }

  title() {
    return app.translator.trans('tryhackx-cover-studio.forum.editor.title');
  }

  protected get user(): User {
    return this.attrs.user;
  }

  protected syncFromUser() {
    this.focusX = Number(this.user.coverFocusX() ?? 50);
    this.focusY = Number(this.user.coverFocusY() ?? 50);
    this.zoom = clampZoom(Number(this.user.coverZoom() ?? 1));
    this.dirty = false;
  }

  content() {
    const coverUrl = this.user.coverUrl();
    // The preview area is ~560px wide at most — the fof/upload thumbnail
    // (same aspect ratio, a fraction of the size) is plenty and loads much
    // faster on image-heavy pages. Falls back to the full image when the
    // forum has thumbnails disabled.
    const previewUrl = this.user.coverThumbUrl() || coverUrl;
    const maxSize = Number(app.forum.attribute('tryhackx-cover-studio.max_size') || 2048);
    const height = Math.max(120, Math.min(Number(app.forum.attribute('tryhackx-cover-studio.cover_height') || 220), 260));

    return (
      <div className="Modal-body CoverStudio-Editor">
        {coverUrl ? (
          [
            <FocusDragArea
              url={previewUrl!}
              focusX={this.focusX}
              focusY={this.focusY}
              zoom={this.zoom}
              height={height}
              onchange={(x: number, y: number) => {
                this.focusX = x;
                this.focusY = y;
                this.dirty = true;
              }}
              onzoom={this.setZoom.bind(this)}
            />,
            this.zoomControl(),
          ]
        ) : (
          <div className="CoverStudio-Editor-empty" style={{ height: `${height}px` }}>
            {this.loading ? (
              <LoadingIndicator />
            ) : (
              <p>{app.translator.trans('tryhackx-cover-studio.forum.editor.empty_hint')}</p>
            )}
          </div>
        )}

        <p className="CoverStudio-Editor-sizeHint helpText">
          {app.translator.trans('tryhackx-cover-studio.forum.editor.size_hint', { max: formatBytes(maxSize * 1024) })}
        </p>

        <div className="CoverStudio-Editor-actions">
          <Button
            className="Button"
            icon="fas fa-upload"
            loading={this.loading}
            onclick={this.openFilePicker.bind(this)}
          >
            {app.translator.trans('tryhackx-cover-studio.forum.editor.upload_button')}
          </Button>

          {mediaPickerAvailable(this.user) && (
            <Button className="Button" icon="fas fa-photo-video" disabled={this.loading} onclick={this.openMediaManager.bind(this)}>
              {app.translator.trans('tryhackx-cover-studio.forum.editor.media_button')}
            </Button>
          )}

          {!!coverUrl && (
            <Button className="Button Button--danger" icon="fas fa-times" disabled={this.loading} onclick={this.remove.bind(this)}>
              {app.translator.trans('tryhackx-cover-studio.forum.editor.remove_button')}
            </Button>
          )}
        </div>

        {!!coverUrl && (
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
        <label htmlFor="CoverStudio-cover-zoom">
          {app.translator.trans('tryhackx-cover-studio.forum.editor.zoom_label')}
        </label>
        <input
          id="CoverStudio-cover-zoom"
          type="range"
          min="0.5"
          max="3"
          step="0.05"
          list="CoverStudio-zoom-ticks"
          value={this.zoom}
          disabled={this.loading}
          oninput={(e: InputEvent) => this.setZoom(parseFloat((e.target as HTMLInputElement).value))}
        />
        <datalist id="CoverStudio-zoom-ticks">
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
    body.append('cover', file);
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

  protected remove() {
    if (this.loading) return;
    if (!confirm(app.translator.trans('tryhackx-cover-studio.forum.editor.remove_confirm') as unknown as string)) return;

    this.request({ method: 'DELETE' });
  }

  protected request(options: { method: string; body?: unknown; serialize?: (raw: never) => unknown }) {
    this.loading = true;
    this.alertAttrs = null;
    m.redraw();

    app
      .request({
        url: `${app.forum.attribute('apiUrl')}/users/${this.user.id()}/cover-studio`,
        method: options.method,
        body: options.body,
        ...(options.serialize ? { serialize: options.serialize } : {}),
      })
      .then((response) => {
        if (response) app.store.pushPayload(response as { data: object });

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
