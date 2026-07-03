/**
 * Quietly warm the browser cache for images the user is about to need
 * (editor sources). Low priority — never competes with the page's own
 * images; on image-heavy forums (HTTP/1.1, ~6 connections per host) this
 * makes the position editors open near-instantly instead of waiting behind
 * dozens of post images.
 */
const requested = new Set<string>();

export default function prefetchImage(url: string | null | undefined): void {
  if (!url || requested.has(url)) return;

  requested.add(url);

  const img = new Image();
  (img as unknown as { fetchPriority?: string }).fetchPriority = 'low';
  img.decoding = 'async';
  img.src = url;
}
