/**
 * Human-readable file size for a byte count.
 */
export function formatBytes(bytes: number, decimals = 1): string {
  if (!Number.isFinite(bytes) || bytes <= 0) return '0 B';

  const units = ['B', 'KB', 'MB', 'GB'];
  const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
  const value = bytes / Math.pow(1024, i);

  return `${value.toFixed(i === 0 ? 0 : decimals)} ${units[i]}`;
}

/**
 * Clamp a focal-point percentage into [0, 100].
 */
export function clampFocus(value: number): number {
  return Math.max(0, Math.min(100, value));
}

/**
 * Clamp a zoom factor into [0.5, 4] (matches the server-side limits),
 * rounded to two decimals. Values below 1 mean a deliberate zoom-out with a
 * blurred fill behind the image.
 */
export function clampZoom(value: number): number {
  if (!Number.isFinite(value)) return 1;

  return Math.round(Math.max(0.5, Math.min(4, value)) * 100) / 100;
}

/**
 * Escape a URL for safe embedding inside a CSS url("...") value.
 */
export function cssUrl(url: string): string {
  return `url("${url.replace(/["\\]/g, (c) => '%' + c.charCodeAt(0).toString(16).toUpperCase())}")`;
}
