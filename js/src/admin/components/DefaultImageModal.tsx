import app from 'flarum/admin/app';
import Modal, { IInternalModalAttrs } from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';

import FocusDragArea from '../../common/components/FocusDragArea';
import { formatBytes, clampFocus, clampZoom } from '../../common/util';

export interface DefaultImageModalAttrs extends IInternalModalAttrs {
  kind: 'cover' | 'avatar';
}

interface SharedFile {
  id: string;
  url: string;
  name: string;
}

const t = (key: string, params?: Record<string, unknown>) => app.translator.trans(`tryhackx-cover-studio.admin.default.${key}`, params);

/**
 * Admin editor for a forum-wide default image (cover or avatar). Mirrors the
 * forum-side cover/avatar editors — upload OR pick from the shared media
 * library, drag the focal point, zoom, save the position without re-uploading,
 * or remove — but persists to the extension settings via the admin-only
 * endpoints. The image lives in the shared media library; only its URL + focal
 * point / zoom are stored.
 *
 * Reads and writes `app.data.settings` directly (the admin settings store), so
 * the change is reflected immediately on the settings page and on the next
 * modal open, without a page reload.
 */
export default class DefaultImageModal extends Modal<DefaultImageModalAttrs> {
  loading = false;
  focusX = 50;
  focusY = 50;
  zoom = 1;
  dirty = false;

  /** Shared-media picker state. */
  picking = false;
  sharedFiles: SharedFile[] | null = null;
  sharedLoading = false;
  sharedError = false;

  oninit(vnode: Mithril.Vnode<DefaultImageModalAttrs, this>) {
    super.oninit(vnode);

    this.syncFromSettings();
  }

  get kind(): 'cover' | 'avatar' {
    return this.attrs.kind;
  }

  get isAvatar(): boolean {
    return this.kind === 'avatar';
  }

  protected setting(suffix: string): string {
    return `tryhackx-cover-studio.default_${this.kind}_${suffix}`;
  }

  protected currentUrl(): string {
    return String(app.data.settings[this.setting('url')] || '');
  }

  protected syncFromSettings() {
    this.focusX = clampFocus(Number(app.data.settings[this.setting('focus_x')] ?? 50));
    this.focusY = clampFocus(Number(app.data.settings[this.setting('focus_y')] ?? 50));
    this.zoom = clampZoom(Number(app.data.settings[this.setting('zoom')] ?? 1));
    this.dirty = false;
  }

  className() {
    return 'CoverStudio-Modal CoverStudio-DefaultModal' + (this.isAvatar ? ' CoverStudio-AvatarModal' : '') + ' Modal--small';
  }

  title() {
    return t(this.isAvatar ? 'avatar_title' : 'cover_title');
  }

  content() {
    return this.picking ? this.pickerContent() : this.editorContent();
  }

  protected editorContent(): Mithril.Children {
    const url = this.currentUrl();
    const maxSize = Number(app.data.settings['tryhackx-cover-studio.max_size'] || 2048);
    const height = Math.max(120, Math.min(Number(app.data.settings['tryhackx-cover-studio.cover_height'] || 220), 260));

    return (
      <div className="Modal-body CoverStudio-Editor">
        {url ? (
          [
            <FocusDragArea
              url={url}
              focusX={this.focusX}
              focusY={this.focusY}
              zoom={this.zoom}
              circle={this.isAvatar}
              height={this.isAvatar ? undefined : height}
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
          <div
            className={'CoverStudio-Editor-empty' + (this.isAvatar ? ' CoverStudio-Editor-empty--avatar' : '')}
            style={this.isAvatar ? undefined : { height: `${height}px` }}
          >
            {this.loading ? <LoadingIndicator /> : <p>{t(this.isAvatar ? 'avatar_empty' : 'cover_empty')}</p>}
          </div>
        )}

        <p className="CoverStudio-Editor-sizeHint helpText">{t('size_hint', { max: formatBytes(maxSize * 1024) })}</p>

        <div className="CoverStudio-Editor-actions">
          <Button className="Button" icon="fas fa-upload" loading={this.loading} onclick={this.openFilePicker.bind(this)}>
            {t('upload_button')}
          </Button>

          <Button className="Button" icon="fas fa-photo-video" disabled={this.loading} onclick={this.openSharedPicker.bind(this)}>
            {t('media_button')}
          </Button>

          {!!url && (
            <Button className="Button Button--danger" icon="fas fa-times" disabled={this.loading} onclick={this.remove.bind(this)}>
              {t('remove_button')}
            </Button>
          )}
        </div>

        {!!url && (
          <div className="CoverStudio-Editor-save">
            <Button className="Button Button--primary" icon="fas fa-check" loading={this.loading} disabled={!this.dirty} onclick={this.savePosition.bind(this)}>
              {t('save_position_button')}
            </Button>
          </div>
        )}
      </div>
    );
  }

  protected pickerContent(): Mithril.Children {
    return (
      <div className="Modal-body CoverStudio-Editor CoverStudio-SharedPicker">
        <div className="CoverStudio-SharedPicker-head">
          <Button className="Button Button--link" icon="fas fa-arrow-left" disabled={this.loading} onclick={this.closePicker.bind(this)}>
            {t('picker_back')}
          </Button>
        </div>

        {this.sharedLoading ? (
          <div className="CoverStudio-SharedPicker-state">
            <LoadingIndicator />
          </div>
        ) : this.sharedError ? (
          <div className="CoverStudio-SharedPicker-state">{t('generic_error')}</div>
        ) : !this.sharedFiles || this.sharedFiles.length === 0 ? (
          <div className="CoverStudio-SharedPicker-state">{t('picker_empty')}</div>
        ) : (
          <div className="CoverStudio-SharedPicker-grid">
            {this.sharedFiles.map((file) => (
              <button
                type="button"
                key={file.id}
                className="CoverStudio-SharedPicker-item"
                title={file.name}
                disabled={this.loading}
                onclick={() => this.pickShared(file.id)}
              >
                <img src={file.url} alt={file.name} loading="lazy" />
              </button>
            ))}
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
    const id = `CoverStudio-default-${this.kind}-zoom`;

    return (
      <div className="CoverStudio-Editor-zoom">
        <label htmlFor={id}>{t('zoom_label')}</label>
        <input
          id={id}
          type="range"
          min="0.5"
          max="3"
          step="0.05"
          value={this.zoom}
          disabled={this.loading}
          oninput={(e: InputEvent) => this.setZoom(parseFloat((e.target as HTMLInputElement).value))}
        />
        <button
          type="button"
          className="Button Button--link CoverStudio-Editor-zoomValue"
          title={t('zoom_reset') as unknown as string}
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
    body.append('image', file);
    // A fresh image starts centered, without zoom.
    body.append('focusX', '50');
    body.append('focusY', '50');
    body.append('zoom', '1');

    this.request({ method: 'POST', body, serialize: (raw: FormData) => raw });
  }

  protected openSharedPicker() {
    if (this.loading) return;

    this.picking = true;

    if (this.sharedFiles === null && !this.sharedLoading) this.fetchShared();
  }

  protected closePicker() {
    this.picking = false;
  }

  protected fetchShared() {
    this.sharedLoading = true;
    this.sharedError = false;
    m.redraw();

    app
      .request<any>({
        method: 'GET',
        url: `${app.forum.attribute('apiUrl')}/fof/upload/shared-files`,
        params: { page: { limit: 50 } },
      })
      .then((response) => {
        const data: any[] = (response && response.data) || [];

        // Only raster images can be used as a cover / avatar.
        this.sharedFiles = data
          .filter((f) => String(f?.attributes?.type || '').indexOf('image/') === 0 && f?.attributes?.url)
          .map((f) => ({ id: String(f.id), url: String(f.attributes.url), name: String(f.attributes.baseName || '') }));

        this.sharedLoading = false;
        m.redraw();
      })
      .catch(() => {
        this.sharedLoading = false;
        this.sharedError = true;
        m.redraw();
      });
  }

  protected pickShared(fileId: string) {
    if (this.loading) return;

    this.request({ method: 'POST', body: { fileId, focusX: 50, focusY: 50, zoom: 1 } });
  }

  protected savePosition() {
    if (this.loading || !this.dirty) return;

    this.request({ method: 'PATCH', body: { focusX: this.focusX, focusY: this.focusY, zoom: this.zoom } });
  }

  protected remove() {
    if (this.loading) return;
    if (!confirm(t('remove_confirm') as unknown as string)) return;

    this.request({ method: 'DELETE' });
  }

  protected request(options: { method: string; body?: unknown; serialize?: (raw: never) => unknown }) {
    this.loading = true;
    this.alertAttrs = null;
    m.redraw();

    app
      .request<any>({
        method: options.method,
        url: `${app.forum.attribute('apiUrl')}/cover-studio/default/${this.kind}`,
        ...(options.body ? { body: options.body } : {}),
        ...(options.serialize ? { serialize: options.serialize } : {}),
      })
      .then((response) => {
        const settings = response?.settings;
        if (settings) {
          Object.keys(settings).forEach((key) => {
            app.data.settings[key] = settings[key];
          });
        }

        this.loading = false;
        this.picking = false;
        this.syncFromSettings();
        this.alertAttrs = { type: 'success', content: t('saved') };
        m.redraw();
      })
      .catch((error) => {
        this.loading = false;
        this.alertAttrs = { type: 'error', content: error?.response?.errors?.[0]?.detail || t('generic_error') };
        m.redraw();
      });
  }
}
