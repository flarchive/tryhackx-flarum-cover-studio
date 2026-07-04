# Changelog

All notable changes to `tryhackx/flarum-cover-studio` are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

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
