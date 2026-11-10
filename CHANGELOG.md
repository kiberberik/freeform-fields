# Changelog

All notable changes to this project are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

## [1.2.0] — 2026-11-10

### Added
- Before migration, field groups and options pages are read from ACF's tables when ACF is not active, so a site keeps working if ACF is deactivated first. They are read-only and marked **ACF**. Filter: `wpff/read_acf_groups`.
- The output of the "ACF to REST API" plugin (`acf/v3/…` routes and the `acf` property) is served while that plugin is active and ACF is not. Only fields of the groups shown on each object are served, never other meta or options. Unlike the original, posts, terms and users follow the core REST API's visibility rules, Password fields are left out, and options page fields need the page's capability unless their group is shown in REST (filter: `wpff/acf_to_rest_api/public_options`). Filter: `wpff/emulate_acf_to_rest_api`.

### Changed
- `get_field( $name, $id, false )` and `get_fields( $id, false )` return group, repeater and flexible content values keyed by field keys, as ACF does. Empty rows are `false`.
- REST `acf` values are typed as in ACF: numbers, booleans, attachment and object IDs as integers, empty ones as `null`. The `acf` property is not added while ACF or ACF to REST API provides it.
- WYSIWYG values run through the same filters as ACF's `acf_the_content` (embeds, `wptexturize`, `wpautop`, shortcodes, responsive images), and `acf_the_content` filters run while ACF compatibility is on.
- `get_fields()` skips values whose field was renamed or moved, as ACF does.
- Faster reads: images, galleries, relationship, taxonomy and user fields load their items with one query; options page values load with two queries per page instead of two per field; ACF's tables are no longer read once every ACF group is migrated; REST responses skip location checks when no group is shown in REST; front-end forms no longer load React.
- Redesigned admin screens in the style of the WordPress editor: page headers, panels, type icons with category colors, status pills, empty states.
- Field group editor: a field tree with every level visible, settings of the selected field in a side inspector (General, type settings, layouts, validation and presentation, conditional logic), group settings in their own tab, Ctrl/Cmd+S to save, undo after deleting a field.
- Drag and drop: visible handles, moving fields between levels (into and out of repeaters, groups and flexible layouts), dragging new field types from the palette into place; a menu offers the same moves without a mouse.
- Field group list: filters (all, active, inactive, sync available), search, location chips and an actions menu.
- Custom selects everywhere: in the builder (with search for long lists, option groups and field type icons) and for Select fields on edit screens (search, chips for multiple values, full keyboard support). The native select stays in the form, so saving, conditional logic and the `wpff/fields` store work as before.
- Fields on edit screens: tidier labels and spacing, repeater rows with a visible grip and icon buttons, underlined tabs, clearer errors, drop-area style for empty image and file fields.

### Fixed
- Opening and saving a post without changes rewrote values: text with HTML entities (`&amp;`, `&quot;`) lost one level of escaping, and empty repeaters were saved again.
- With ACF active, edit screens showed both plugins' forms for the same group, and options pages were added twice.
- A field group with a tab named like one of its fields could not be imported from ACF.
- An empty `wpff` object appeared in REST responses while ACF was active.
- Repeater pagination now actually hides rows of other pages.
- Location summaries had no spaces around "and" / "or".

## [1.1.0] — 2026-11-09

### Added
- ACF's `acf/…` hooks run next to `wpff/…` ones when ACF is not active: `load_field`, `load_value`, `format_value`, `update_value`, `validate_value`, `prepare_field`, `save_post` (with `$_POST['acf']`), `pre_save_post`, `submit_form`, `include_fields`, `settings/{name}`, relational `fields/{type}/query` and more.
- `acf_form()` and `acf_form_head()`: front-end forms with signed settings, a honeypot and server-side validation.
- `get_field_objects()`, `acf_get_field()`, `acf_get_fields()`, `acf_get_field_group()`, `acf_get_field_groups()`, `acf_add_local_field()`, `update_sub_field()`, `delete_sub_field()`, `acf_esc_html()`, `acf_get_options_page()`, `acf_get_options_pages()`.
- Theme check before migrating from ACF (Tools screen and `wp wpff import-acf --dry-run`).
- An admin notice while ACF is active.

### Fixed
- With ACF active, groups from `acf-json` were shown twice and blocks with an `acf` key in `block.json` were taken over. Both are now left to ACF.
- A theme that bundles ACF no longer hits "Cannot redeclare get_field()": ACF-named functions are defined on `setup_theme` and skipped when the theme ships ACF.
- Activating ACF while WP Freeform Fields is active failed with "Cannot redeclare". ACF-named functions are now skipped on that request.

## [1.0.0] — 2026-11-09

First public release under the name **WP Freeform Fields**. Earlier versions were internal builds.

### Added
- Map field (`google_map`, ACF value format): OpenStreetMap and Nominatim without a key, Google Maps with one.
- Repeater pagination in the editor.
- InnerBlocks in PHP-template blocks (`supports.jsx`).
- Block Bindings source `wpff/field` and the "Allow in Block Bindings" field setting.
- Read-only abilities for the WordPress Abilities API.
- Migration of ACF UI options pages. `wp wpff import-acf` now also imports post types, taxonomies and options pages.
- `wpff_get_setting()` and `wpff_update_setting()`, and the ACF aliases `acf_get_setting()` and `acf_update_setting()`.
- English and Russian README, an English developer guide, and LICENSE, CONTRIBUTING, SECURITY and readme.txt files.

### Changed
- The plugin was renamed from its working title:
  - slug and text domain: `freeform-fields`;
  - code prefix: `wpff_`;
  - namespace: `FreeformFields\`;
  - hooks: `wpff/…`;
  - REST namespace: `wpff/v1`;
  - WP-CLI command: `wp wpff`;
  - Local JSON folder: `wpff-json`.
- `wpff_register_block_type()` registers names without a namespace under `wpff/`. `acf_register_block_type()` keeps `acf/`.

## [0.9.0] — internal
- Russian translation, a security and performance audit, and accessibility improvements.

## [0.8.0] — internal
- Post types and taxonomies UI, bidirectional relationships, and the `wpff/fields` data store.

## [0.7.0] — internal
- Local JSON, the Tools screen, migration of ACF field groups, REST fields and WP-CLI.

## [0.6.0] — internal
- Field-powered blocks with ACF-compatible data.

## [0.5.0] — internal
- Options pages from code and from the admin.

## [0.4.0] — internal
- Repeater, Flexible Content, Gallery, Clone and row loops.

## [0.3.0] — internal
- 20 more field types, conditional logic, the Group field, and term and user screens.

## [0.2.0] — internal
- Field group builder and REST API.

## [0.1.0] — internal
- First build: field groups, 10 field types and ACF-compatible storage.
