# Changelog

All notable changes to `tryhackx/flarum-cover-studio` are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

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

[2.1.0]: https://github.com/TryHackX/flarum-cover-studio/releases/tag/v2.1.0
[2.0.2]: https://github.com/TryHackX/flarum-cover-studio/releases/tag/v2.0.2
[2.0.1]: https://github.com/TryHackX/flarum-cover-studio/releases/tag/v2.0.1
[2.0.0]: https://github.com/TryHackX/flarum-cover-studio/releases/tag/v2.0.0
