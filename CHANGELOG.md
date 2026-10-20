# Changelog

All notable changes to `tryhackx/flarum-cover-studio` are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [2.3.1] - 2026-10-20

### Fixed
- The upload limit (6 per minute per profile owner) no longer blocks covers and
  avatars for hours. It used to count the owner's files from fof/upload's
  `created_at`, which the database fills in its own (server-local) time while
  Flarum runs in UTC — on a server two hours ahead of UTC every upload of the
  last two hours counted, post attachments included. The limit is now kept in
  the cache and counts only uploads made through Cover Studio.
- `php flarum cover-studio:migrate-sycho` crashed on Flarum 2 ("Call to a
  member function compileColumns() on null"): the schema builder is now taken
  from the database connection. Imported files get their `created_at` from the
  database, like every other fof/upload file.
- The admin "Choose from shared media" picker for the default cover / avatar
  lists only the image types that can actually be used (JPEG, PNG, WebP, GIF);
  picking an SVG or BMP used to end with "This file cannot be used".

No migration required.

## [2.3.0] - 2026-10-20

### Security
- Avatar changes made through Cover Studio (upload, "choose from My Media",
  focal point / zoom) now follow core's `editAvatar` ability, introduced in
  Flarum 2.0: the *Edit own avatar* permission (`user.editAvatar`) for your
  own avatar, the *edit user* ability for someone else's. Previously any
  registered user could change their own avatar through these endpoints even
  after the forum revoked *Edit own avatar*.
- Avatar re-crops that do not upload a file (focal point / zoom changes,
  picking an existing media file) are limited to 20 per minute per user
  (admins exempt). Each re-crop decodes the stored original, so they were the
  one heavy operation without a ceiling.

### Fixed
- A failed move of the upload into fof/upload's temp directory (full disk,
  permissions) now returns a validation error instead of a server error.

### Changed
- Requires Flarum `^2.0.2` and fof/upload `^2.0` (stable releases).

No migration required.

## [2.2.1] - 2026-07-04

### Fixed
- The avatar-focus guard is now a request-scoped, container-managed instance
  instead of a `static` property. Under persistent-process servers (Octane /
  RoadRunner / Swoole) a worker terminated mid-re-crop could previously leave
  the flag set, silently disabling the "clear the stored avatar original on an
  external avatar change" listener for the rest of that worker's life. No effect
  under standard PHP-FPM, where the flag was already reset per request.

### Internal
- `Focus` is now an injectable service (translator injected via the constructor)
  instead of reaching into the container with `resolve()` on its error path,
  making focal-point parsing unit-testable in isolation.
- `MigrateSychoCommand` injects `Illuminate\Database\Schema\Builder` for its
  schema-introspection check instead of `Illuminate\Database\ConnectionInterface`,
  per Flarum convention.

No migration required.

## [2.2.0] - 2026-07-04

### Performance
- Cover and avatar focal-point data has moved off the `users` table into a
  dedicated, sparse **`cover_studio_user_data`** companion table (keyed by
  `user_id`). The eleven columns previously added to `users` widened every row
  on a table that is read — and whose authors are serialized — on nearly every
  page; on large forums (hundreds of thousands to millions of users) that hurt
  buffer-pool efficiency and cold reads. A row now exists only for users who
  actually set a cover or avatar original, and it is eager-loaded on every user
  query in a single batched `WHERE user_id IN (…)`, so serialization stays free
  of N+1 queries. **Run `php flarum migrate` when updating** — existing data is
  copied across automatically and the old columns are dropped; the migration is
  reversible.

### Fixed
- `cover-studio:migrate-sycho` never generated cover thumbnails during a real
  (non-dry-run) import: the per-user closure used `$makeThumbs` /
  `$thumbMaxWidth` without capturing them, so the `fof-upload.generateThumbnails`
  setting was silently ignored. Imported covers now get thumbnails as intended.

### Changed
- Pinned the `fof/upload` dependency to `^2.0.0-beta.5` (was `*`), so a future
  major release can no longer silently break the upload bridge.
- Removed the MySQL-only `->unsigned()` modifier from the decimal focal-point /
  zoom columns for correct schema portability on PostgreSQL and SQLite (the
  valid ranges are already enforced in application code).

### Internal
- The Guzzle HTTP client used to fetch remote-adapter files is now injected
  instead of constructed inline (mockable, container-configurable), and the
  fof/upload local-storage path is centralized in one method.
- `MigrateSychoCommand::handle()` (162 lines) was split into focused private
  methods. No behavior change beyond the thumbnail fix above.

## [2.1.0] - 2026-07-04

### Added
- **Default cover** and **default avatar** are now managed through the same
  focal-point editor modal used on profiles, instead of a plain URL field.
  Admins can upload an image or pick one from the FoF Upload **shared media**
  library, reposition it (drag + zoom 0.5×–4×) and remove it. Uploads are stored
  as shared files. The default avatar is shown (client-side) for users who have
  not set their own, with core's letter avatar kept as the ultimate fallback.

### Changed
- The `Default cover URL` admin text field was replaced by the new default-cover
  editor. Any previously configured URL keeps working and is shown in the editor.

### Backend
- New admin-only endpoints `POST|PATCH|DELETE /api/cover-studio/default/{kind}`
  (`kind` = `cover` | `avatar`) and settings
  `default_{cover,avatar}_{url,focus_x,focus_y,zoom}`. No migration required.

## [2.0.2] - 2026-07-03

### Fixed
- The user-controls dropdown (the `⋮` badge, `.UserCard-controls`) was hidden
  behind the top navigation bar on phones when a cover was present. CoverStudio
  gave `.darkenBackground` a `z-index`, which turned it into a stacking context
  and trapped the dropdown below the fixed `.App-navigation` bar. The `z-index`
  is now dropped (DOM order already keeps `.darkenBackground` above the cover
  layers), so core's phone promotion of the dropdown to `z-index: var(--zindex-
  header) + 1` works again and the badge floats above the nav bar. LESS-only fix
  — no JS rebuild and no migration.

## [2.0.1] - 2026-07-03

### Added
- **Support Development** button with a donation modal (XMR / BTC / ETH),
  shown at the top of the admin settings page. Addresses can be copied with one
  click.

## [2.0.0] - 2026-07-03

### Added
- Initial public release: profile covers with a repositionable focal point
  ("pos-crop") and zoom (0.5×–4×), integrated with FoF Upload's "My Media"
  manager. Optional focal-point editing for avatars, a readability overlay
  (gradient / darken / none), a default cover URL, and an optional migration
  from `sycho/flarum-profile-cover`.

[2.2.1]: https://github.com/TryHackX/flarum-cover-studio/releases/tag/v2.2.1
[2.2.0]: https://github.com/TryHackX/flarum-cover-studio/releases/tag/v2.2.0
[2.1.0]: https://github.com/TryHackX/flarum-cover-studio/releases/tag/v2.1.0
[2.0.2]: https://github.com/TryHackX/flarum-cover-studio/releases/tag/v2.0.2
[2.0.1]: https://github.com/TryHackX/flarum-cover-studio/releases/tag/v2.0.1
[2.0.0]: https://github.com/TryHackX/flarum-cover-studio/releases/tag/v2.0.0
