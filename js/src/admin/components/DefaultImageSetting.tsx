import app from 'flarum/admin/app';
import Component, { ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';

import DefaultImageModal from './DefaultImageModal';
import { clampFocus, clampZoom } from '../../common/util';

const t = (key: string) => app.translator.trans(`tryhackx-cover-studio.admin.${key}`);

export interface DefaultImageSettingAttrs extends ComponentAttrs {
  kind: 'cover' | 'avatar';
}

/**
 * A single admin settings row for a forum-wide default image (cover or
 * avatar). Renders a live preview (with the stored focal point / zoom) plus a
 * button that opens {@link DefaultImageModal}. Replaces the old plain "Default
 * cover URL" text field.
 */
export default class DefaultImageSetting extends Component<DefaultImageSettingAttrs> {
  view() {
    const kind = this.attrs.kind;
    const prefix = `tryhackx-cover-studio.default_${kind}_`;
    const url = String(app.data.settings[`${prefix}url`] || '');

    return (
      <div className="Form-group CoverStudio-defaultSetting">
        <label>{t(`default_${kind}_label`)}</label>
        <div className="helpText">{t(`default_${kind}_help`)}</div>

        <div className="CoverStudio-defaultSetting-row">
          {this.preview(kind, url, prefix)}

          <div className="CoverStudio-defaultSetting-actions">
            <Button className="Button" icon={url ? 'fas fa-pen' : 'fas fa-image'} onclick={() => app.modal.show(DefaultImageModal, { kind })}>
              {t(`default.${url ? 'change_button' : 'set_button'}`)}
            </Button>

            {!url && <span className="CoverStudio-defaultSetting-status">{t('default.not_set')}</span>}
          </div>
        </div>
      </div>
    );
  }

  protected preview(kind: 'cover' | 'avatar', url: string, prefix: string): Mithril.Children {
    const isAvatar = kind === 'avatar';
    const className =
      'CoverStudio-defaultSetting-preview' +
      (isAvatar ? ' CoverStudio-defaultSetting-preview--avatar' : ' CoverStudio-defaultSetting-preview--cover') +
      (url ? '' : ' CoverStudio-defaultSetting-preview--empty');

    if (!url) {
      return (
        <div className={className}>
          <i className={isAvatar ? 'fas fa-user' : 'fas fa-image'} />
        </div>
      );
    }

    const x = clampFocus(Number(app.data.settings[`${prefix}focus_x`] ?? 50));
    const y = clampFocus(Number(app.data.settings[`${prefix}focus_y`] ?? 50));
    const zoom = clampZoom(Number(app.data.settings[`${prefix}zoom`] ?? 1));

    return (
      <div className={className}>
        <img src={url} alt="" style={{ objectPosition: `${x}% ${y}%`, transform: `scale(${zoom})`, transformOrigin: `${x}% ${y}%` }} />
      </div>
    );
  }
}
