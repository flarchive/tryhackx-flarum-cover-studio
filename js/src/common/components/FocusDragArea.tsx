import app from 'flarum/common/app';
import Component, { ComponentAttrs } from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import { clampFocus, clampZoom } from '../util';
import type Mithril from 'mithril';

export interface FocusDragAreaAttrs extends ComponentAttrs {
  /** Full-resolution image URL. */
  url: string;
  focusX: number;
  focusY: number;
  /** Zoom factor over the base cover fit (1 = no zoom). */
  zoom?: number;
  /** Show a circular mask (avatar mode). */
  circle?: boolean;
  /** Fixed CSS height of the area in px (cover mode). */
  height?: number;
  onchange: (x: number, y: number) => void;
  /** Called when the user zooms via mouse wheel or +/- keys. */
  onzoom?: (zoom: number) => void;
}

/**
 * The "pos-crop" surface: shows the image exactly as it will be displayed
 * (cover fit at the chosen focal point, magnified by the zoom factor around
 * that same point) and lets the user pan it by dragging (mouse/touch via
 * pointer events), zoom with the mouse wheel, or use the keyboard
 * (arrows = pan, +/- = zoom).
 *
 * The percentages map 1:1 to CSS `object-position` / `background-position`
 * and the zoom to `transform: scale()` with `transform-origin` at the focal
 * point — the real cover surfaces use identical CSS, so what you see here is
 * exactly what gets rendered.
 */
export default class FocusDragArea extends Component<FocusDragAreaAttrs> {
  natural = { width: 0, height: 0 };
  dragging = false;
  loaded = false;

  private lastUrl: string | null = null;
  private startPointer = { x: 0, y: 0 };
  private startFocus = { x: 50, y: 50 };
  private rafPending = false;

  private boundMove = this.onPointerMove.bind(this);
  private boundUp = this.onPointerUp.bind(this);

  view() {
    const { url, focusX, focusY, circle, height } = this.attrs;
    const zoom = clampZoom(this.attrs.zoom ?? 1);
    const x = clampFocus(focusX);
    const y = clampFocus(focusY);

    // A fresh source (upload / media pick) restarts the loading state.
    if (url !== this.lastUrl) {
      this.lastUrl = url;
      this.loaded = false;
      this.natural = { width: 0, height: 0 };
    }

    return (
      <div
        className={
          'CoverStudio-FocusArea' +
          (circle ? ' CoverStudio-FocusArea--circle' : '') +
          (this.dragging ? ' is-dragging' : '')
        }
        style={height ? { height: `${height}px` } : undefined}
        tabindex="0"
        aria-label={app.translator.trans('tryhackx-cover-studio.forum.editor.drag_aria')}
        onpointerdown={this.onPointerDown.bind(this)}
        onkeydown={this.onKeyDown.bind(this)}
        onwheel={this.onWheel.bind(this)}
      >
        {zoom < 1 && (
          // Zoom-out exposes bands around the image — fill them with a blurred
          // copy, exactly like the real cover/avatar rendering does.
          <img
            className="CoverStudio-FocusArea-blur"
            src={url}
            alt=""
            draggable="false"
            aria-hidden="true"
            style={{ objectPosition: `${x}% ${y}%` }}
          />
        )}
        <img
          className="CoverStudio-FocusArea-image"
          src={url}
          alt=""
          draggable="false"
          // Jump the browser's request queue: on pages with dozens of post
          // images (HTTP/1.1 = ~6 connections per host) the editor image
          // would otherwise wait many seconds at default priority.
          fetchpriority="high"
          decoding="async"
          style={{
            objectPosition: `${x}% ${y}%`,
            transform: `scale(${zoom})`,
            transformOrigin: `${x}% ${y}%`,
          }}
          onload={(e: Event) => {
            const img = e.target as HTMLImageElement;
            this.natural = { width: img.naturalWidth, height: img.naturalHeight };
            this.loaded = true;
            m.redraw();
          }}
        />
        {!this.loaded && (
          <div className="CoverStudio-FocusArea-loading" aria-hidden="true">
            <LoadingIndicator />
          </div>
        )}
        {circle && <div className="CoverStudio-FocusArea-mask" aria-hidden="true" />}
        <div className="CoverStudio-FocusArea-grid" aria-hidden="true" />
        {/* Focal-point marker: useful on the wide cover surface; redundant in
            avatar mode, where the circular mask already frames the crop. */}
        {!circle && (
          <div className="CoverStudio-FocusArea-crosshair" style={{ left: `${x}%`, top: `${y}%` }} aria-hidden="true" />
        )}
        <div className="CoverStudio-FocusArea-hint" aria-hidden="true">
          <Icon name="fas fa-arrows-alt" /> {app.translator.trans('tryhackx-cover-studio.forum.editor.drag_hint')}
        </div>
      </div>
    );
  }

  onremove(vnode: Mithril.VnodeDOM<FocusDragAreaAttrs, this>) {
    super.onremove(vnode);
    this.unbindWindow();
  }

  /**
   * SIGNED pan range per axis: rendered size (cover fit × zoom) minus the
   * container size. Positive = the image overflows and panning slides the
   * viewport over it; negative = the image is smaller (zoom-out) and panning
   * slides the image inside the window. The same drag formula handles both
   * signs; ~zero means the axis cannot be panned.
   */
  private panRange(): { x: number; y: number } {
    const box = this.element?.getBoundingClientRect();

    if (!box || !this.natural.width || !this.natural.height) {
      return { x: 0, y: 0 };
    }

    const zoom = clampZoom(this.attrs.zoom ?? 1);
    const scale = Math.max(box.width / this.natural.width, box.height / this.natural.height) * zoom;

    return {
      x: this.natural.width * scale - box.width,
      y: this.natural.height * scale - box.height,
    };
  }

  onPointerDown(e: PointerEvent) {
    // Only main button / touch.
    if (e.button !== undefined && e.button !== 0) return;

    e.preventDefault();

    this.dragging = true;
    this.startPointer = { x: e.clientX, y: e.clientY };
    this.startFocus = { x: this.attrs.focusX, y: this.attrs.focusY };

    window.addEventListener('pointermove', this.boundMove);
    window.addEventListener('pointerup', this.boundUp);
    window.addEventListener('pointercancel', this.boundUp);

    m.redraw();
  }

  private onPointerMove(e: PointerEvent) {
    if (!this.dragging) return;

    const range = this.panRange();
    const dx = e.clientX - this.startPointer.x;
    const dy = e.clientY - this.startPointer.y;

    // Overflowing axis (range > 0): dragging the image right reveals more of
    // its left side → focus % shrinks. Zoomed-out axis (range < 0): dragging
    // right moves the floating image right → focus % grows. Both cases reduce
    // to the same signed formula.
    const x = Math.abs(range.x) > 1 ? this.startFocus.x - (dx / range.x) * 100 : this.startFocus.x;
    const y = Math.abs(range.y) > 1 ? this.startFocus.y - (dy / range.y) * 100 : this.startFocus.y;

    this.attrs.onchange(clampFocus(x), clampFocus(y));
    this.scheduleRedraw();
  }

  private onPointerUp() {
    this.dragging = false;
    this.unbindWindow();
    m.redraw();
  }

  private unbindWindow() {
    window.removeEventListener('pointermove', this.boundMove);
    window.removeEventListener('pointerup', this.boundUp);
    window.removeEventListener('pointercancel', this.boundUp);
  }

  onWheel(e: WheelEvent) {
    if (!this.attrs.onzoom) return;

    e.preventDefault();

    const current = clampZoom(this.attrs.zoom ?? 1);
    const step = e.deltaY < 0 ? 0.1 : -0.1;

    this.attrs.onzoom(clampZoom(current + step));
    this.scheduleRedraw();
  }

  onKeyDown(e: KeyboardEvent) {
    const step = e.shiftKey ? 10 : 2;
    let { focusX: x, focusY: y } = this.attrs;

    switch (e.key) {
      case 'ArrowLeft':
        x -= step;
        break;
      case 'ArrowRight':
        x += step;
        break;
      case 'ArrowUp':
        y -= step;
        break;
      case 'ArrowDown':
        y += step;
        break;
      case '+':
      case '=':
        if (this.attrs.onzoom) {
          e.preventDefault();
          this.attrs.onzoom(clampZoom(clampZoom(this.attrs.zoom ?? 1) + 0.1));
        }
        return;
      case '-':
      case '_':
        if (this.attrs.onzoom) {
          e.preventDefault();
          this.attrs.onzoom(clampZoom(clampZoom(this.attrs.zoom ?? 1) - 0.1));
        }
        return;
      default:
        return;
    }

    e.preventDefault();
    this.attrs.onchange(clampFocus(x), clampFocus(y));
  }

  private scheduleRedraw() {
    if (this.rafPending) return;

    this.rafPending = true;
    requestAnimationFrame(() => {
      this.rafPending = false;
      m.redraw();
    });
  }
}
