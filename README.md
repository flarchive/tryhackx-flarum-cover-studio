# Cover Studio

![License](https://img.shields.io/badge/license-MIT-blue.svg) [![Latest Stable Version](https://img.shields.io/packagist/v/tryhackx/flarum-cover-studio.svg)](https://packagist.org/packages/tryhackx/flarum-cover-studio)

A [Flarum](https://flarum.org) extension that adds **profile covers with a repositionable focal point ("pos-crop") and zoom**, fully integrated with the [FoF Upload](https://github.com/FriendsOfFlarum/upload) media manager. Optionally, the same focal-point editing is available for **avatars**.

## Features

### Profile covers
- Upload a cover image or **pick one you already uploaded from "My Media"** (FoF Upload's media manager).
- The image is kept at **full resolution** — no destructive cropping. Instead you choose a **focal point** and an optional **zoom (0.5×–4×)**, and every surface centers on that point.
- **Zoom out below 1×** deliberately pulls the image back — the exposed bands are filled with a blurred copy of the image (cinematic letterbox effect), great for showing a wide sky or a full artwork.
- **Edit the position/zoom at any time without re-uploading** — drag with mouse/touch, zoom with the mouse wheel or a slider, or use the keyboard (arrows pan, `+`/`-` zoom). A rule-of-thirds framing grid and a focal-point crosshair make precise positioning easy.
- Covers show on the user profile hero and (optionally) on post hover cards and in the [FoF User Directory](https://github.com/FriendsOfFlarum/user-directory), where a lightweight thumbnail is used instead of the full image.
- Optional readability overlay (gradient / uniform darken / none) and a default cover URL for users without one.
- Moderators with the *edit user* permission can manage other users' covers.

### Media manager lifecycle
Cover files are regular FoF Upload files **owned by the profile owner**:
- The file appears in the user's *My Media*.
- **Hiding** it from the media manager keeps the cover visible (the file still exists).
- **Deleting** it removes the cover automatically — enforced both by an event listener and by an `ON DELETE SET NULL` foreign key at the database level.

### Avatar focal point (optional)
When enabled, avatar uploads keep the **original image** in the user's media manager, and the actual avatar is **re-cropped server-side** from that original whenever the user adjusts the focal point or zoom. The avatar source can also be **picked straight from "My Media"**. Zooming out composes the avatar on a blurred backdrop of the same image. The avatar remains a 100% standard Flarum avatar (all sizes, HiDPI variants, e-mails, notifications) — no CSS hacks, no compatibility issues. Repositioning never requires a re-upload.

## Requirements

- Flarum `^2.0`
- [fof/upload](https://github.com/FriendsOfFlarum/upload) (installed **and enabled** — Flarum enforces the enable order automatically)
- PHP `^8.3`

## Installation

```sh
composer require tryhackx/flarum-cover-studio
php flarum migrate
php flarum cache:clear
```

Then enable **Cover Studio** in the admin panel.

## Updating

```sh
composer update tryhackx/flarum-cover-studio
php flarum migrate
php flarum cache:clear
```

## Settings

| Setting | Default | Description |
|---|---|---|
| Maximum upload size (KB) | 2048 | Limit for cover / avatar-original uploads. The global FoF Upload limit also applies — the lower of the two wins. |
| Cover height on profiles (px) | 220 | Hero height when a cover is set. |
| Cover height on mobile (px) | 160 | Hero height on phones. |
| Readability overlay | Gradient | Dark layer over covers so text stays legible. |
| Show covers on user hover cards | On | Uses the lightweight thumbnail. |
| Show covers in the user directory | On | Applies when FoF User Directory is enabled. |
| Allow choosing a cover from My Media | On | Adds the "Choose from My Media" button. |
| Enable avatar focal point | Off | Avatar pos-crop as described above. |
| Default cover URL | — | Optional image for users without a cover. |

## Permissions

- **Set own profile cover** — granted to Members by default.
- Editing someone else's cover or avatar focal point additionally requires the core *edit user* ability (moderators).

## Migrating from sycho/flarum-profile-cover

Existing covers can be imported into Cover Studio (each one becomes a proper FoF Upload file in the owner's media manager):

```sh
php flarum cover-studio:migrate-sycho --dry-run   # preview
php flarum cover-studio:migrate-sycho             # import
```

The command is non-destructive: legacy files and the `users.cover` column are left untouched. Once you have verified the result, disable sycho/flarum-profile-cover. `--force` re-imports even for users who already have a Cover Studio cover.

## How focal point & zoom work

The focal point is stored as `x/y` percentages and the zoom as a factor (1–4). On every surface the cover is rendered with plain CSS:

```
background-size: cover;
background-position: X% Y%;
transform: scale(Z);           /* on the image layer */
transform-origin: X% Y%;       /* zoom magnifies around the focal point */
```

The editor preview uses the exact same math, so what you see while dragging is what gets rendered — at any container size or aspect ratio. For avatars, the square crop window (`min(width, height) / zoom`, centered on the focal point and clamped to the image bounds) is applied server-side from the stored original.

## Performance & scale

Cover Studio is built to stay cheap on large forums (hundreds of thousands to millions of users):

- Cover and avatar focal-point data lives in a dedicated **`cover_studio_user_data`** companion table keyed by `user_id`, **not** on the hot `users` table — so serializing post authors in discussion lists never has to read wider user rows.
- That table is **sparse**: a row exists only for users who have actually set a cover or an avatar original, and it is deleted again once neither remains.
- The row is **eager-loaded on every user query**, so it is fetched in a single batched `WHERE user_id IN (…)` — no N+1, whether users appear as authors, in the [user directory](https://github.com/FriendsOfFlarum/user-directory), in hover cards or in notifications.
- Cover URLs are denormalized onto that row, so rendering a cover never touches the file relation.

## Security notes

- Real (magic-byte) MIME detection via FoF Upload's detector; client-declared types are only a first-line check.
- Allowed formats: JPEG, PNG, WebP, GIF. SVG and BMP are rejected by design.
- Decompression-bomb guard: image dimensions are validated from the header (~40 MP ceiling) before any decode.
- Upload flood guard: at most 6 uploads per user per minute (admins exempt).
- Reusing a media file as a cover requires that the file belongs to the profile owner's personal library; foreign/shared/private files are rejected without leaking their existence.
- The uncropped avatar original is only ever serialized to the user themselves and to staff who can edit them.
- All uploads run through the full FoF Upload pipeline (SVG sanitizer not needed here, watermark/resize settings, thumbnails, storage adapters), so forum-wide upload policies apply consistently.

## FAQ

**Why is there no thumbnail for my covers?**
Thumbnails are generated by FoF Upload — check *Generate thumbnails* in its settings. Without them, hover cards simply use the full image.

**Does zooming reduce quality?**
No file is modified for covers — zoom is purely a display transform of the full-resolution image. For avatars the crop is re-generated from the stored original at full quality.

**What happens when the user deletes the cover file in My Media?**
The cover disappears everywhere immediately. "Remove cover" in the editor, by contrast, only detaches it — the file stays in the media manager.

## License

[MIT](LICENSE) © TryHackX
