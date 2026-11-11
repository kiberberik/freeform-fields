# Changelog

All notable changes to this project are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Security
- Post Object, Relationship and Page Link fields no longer return drafts and private posts to users who cannot read them, and Image, File and Gallery fields leave out attachments of private posts. A contributor could put a private post's ID into a block's data and see its title in the preview. Raw values (`get_field( $name, $id, false )`) are unchanged. Filter: `wpff/format/readable_post`.

### Performance
- Values read with `get_field()` and `get_fields()` are kept for the rest of the request: reading the same field again costs nothing. They are cleared when meta or options change, also when written by other code. Filter: `wpff/cache_values`.
- Field groups saved in the admin and Local JSON files are kept in the object cache between requests, and read again after a group is saved, trashed, restored or deleted, or a JSON file changes. Filter: `wpff/cache_field_groups`.
- Removed repeater and flexible content rows are deleted in one query per object (removing 100 rows of three fields: 1,204 → 4 queries). WordPress's delete meta hooks still run for each key.
- `update_field()` in a loop no longer reads all meta of the object again on every call (100 fields: 301 → 202 queries).

## [1.4.3] — 2026-11-11

### Security
- Front-end forms: two-way relationships no longer write the form's post into posts the visitor cannot edit. Before, a logged-out visitor could link their submission to any published post.
- Front-end form settings are now valid only for the user they were shown to and for two days (filter `wpff/form_lifetime`). A form shown only to editors can no longer be replayed by someone else.
- Block data saved by users without `unfiltered_html` goes through each field type's sanitizing and validation, like the block's form: values outside a select's choices, links to posts the author cannot read and `_name` references to fields outside the block are dropped.
- "ACF to REST API" emulation: linked drafts and private posts are left out, and users are returned without e-mail and registration date unless the requester may list users. A User field returning objects no longer exposes `WP_User` (whose data holds the password hash).
- Password fields are never returned by the REST API, even in groups shown in REST.

### Performance
- The check for ACF's own field groups and options pages no longer queries the posts table on every request: its result is kept in one autoloaded option, cleared when ACF's posts change and refreshed daily.
- oEmbed fields use WordPress's embed cache instead of asking the provider on every page view.
- Images, files and posts picked inside repeater and flexible content rows are loaded in one query for all rows. Posts picked in Post Object and Relationship fields are loaded with their fields.
- The field form script is minified (69 KB → 38 KB).

### Fixed
- Image, File and Gallery fields set to "Uploaded to post" no longer filter the media library by a non-existent post on term, user and new-post forms.
- On front-end forms where the media library is not available, the image and file buttons say so instead of failing.
- Nested flexible content fields count layout limits on their own rows only.
- Options pages read from ACF are shown read-only (saving them failed); migrate them in Tools to edit them.
- The field settings panel no longer breaks on fields saved without wrapper settings.

### Changed
- The plugin's scripts are written in TypeScript (for contributors: `npm run typecheck`). The field form script is now built to `build/admin.js` instead of `assets/js/admin.js`; its handle `wpff-admin` is unchanged.

## [1.4.2] — 2026-11-11

### Fixed
- "Check again" on the Updates screen did not see a new release: the release manifest stayed cached for up to 12 hours. It is now fetched again on "Check again", and otherwise cached for an hour.

## [1.4.1] — 2026-11-11

### Changed
- Field groups that have no translation option yet (groups made before 1.4.0) use "Different fields across languages" instead of "Same fields across languages". On a site that already has translations, saving a post no longer overwrites images, choices, relations or the number of repeater rows in its translations until you choose an option for the group. New groups still start with "Same fields", and groups with ACFML field preferences still use "Expert".

## [1.4.0] — 2026-11-11

### Added
- Multilingual sites with WPML or Polylang, compatible with ACFML's settings:
  - "Multilingual setup" panel in the field group editor: same fields across languages, different fields across languages, or expert (`acfml_field_group_mode`). In the expert option each field has a "Translation" setting: translate, copy, copy once or don't translate (`wpml_cf_preferences`). A "Translation" column in the field group list.
  - WPML: the translation preference of every stored meta key (repeater rows included) is written to WPML's settings, so WPML copies, synchronizes and translates fields. Polylang: `pll_copy_post_metas` / `pll_copy_term_metas` copy every field except "don't translate" into a new translation and keep only "copy" fields in sync.
  - IDs in Image, File, Gallery, Post Object, Page Link, Relationship and Taxonomy values are replaced by their translations when copied, and on display in the current language.
  - Options pages store values per language like ACF (`options_{lang}_{name}`); a language without its own value shows the default language's value. The options screen says which language is being edited.
  - Group titles, field labels, instructions, placeholders, choices and layout labels are registered with WPML String Translation / Polylang and shown translated.
  - Location rules on a post, page or page parent match the translations of that post.
  - `wpml-config.xml` keeps field groups, options pages, post types and taxonomies made in the admin out of translation.
- Filters: `wpff/multilingual/provider`, `wpff/multilingual/text_types`, `wpff/multilingual/field_preference`, `wpff/multilingual/id_types`, `wpff/multilingual/translate_ids_on_display`, `wpff/multilingual/localize_options`, `wpff/multilingual/options_fallback`.

### Fixed
- `get_field_object()` returned settings that had not been through `load_field` filters; ACF applies them.
- Importing an ACF 6 JSON export: post types, taxonomies and options pages in the file are imported as such (before the field groups) instead of becoming empty field groups. Importing the same file again updates them.
- Importing an ACF group no longer fails on a field without a label. Tabs, messages and accordions keep an empty label (the builder now allows that too); other fields and flexible content layouts take their name as the label.

## [1.3.0] — 2026-11-10

### Added
- Updates from the Plugins and Updates screens. The plugin header's `Update URI` points to wpff.kiberberik.link, and the updater reads `update.json` from the latest GitHub release, then installs that release's zip like any other update. The "View details" window shows the release's changelog. Filters: `wpff/updates/enabled`, `wpff/updates/manifest_url`.
- Website with documentation in English and Russian: https://wpff.kiberberik.link (`Plugin URI` header, links in the READMEs).

### Changed
- Releases attach `update.json` next to the zip (`bin/build-update-manifest.sh`).

## [1.2.2] — 2026-11-10

### Added
- Block Bindings: `args.object` reads other objects — `term` for the term of a taxonomy archive, or an options page's storage key (`options` or the page's `post_id`). Outside a post, a taxonomy archive template reads its term by default. Such fields are offered in the Attributes panel too.
- Options pages can be placed under the menu of a custom post type (`edit.php?post_type=…`).
- Field group editor: "Move into “…”" in a field's menu moves it into the repeater, group or flexible layout above it, without dragging.
- Menu icon picker for post types and options pages: search the Dashicons library and pick an icon, or type a `dashicons-*` class (options pages also take an image URL).

### Changed
- Post type, taxonomy and options page screens are reorganised into titled sections (Names, Admin menu, Behavior, Supports…) with switches as rows, checkbox tiles, and a side panel with the status and a preview of the admin menu item.

### Fixed
- Clone fields were expanded when the field group editor loaded a group, so saving it again replaced the clone with copies of the cloned fields. Duplicate, JSON/PHP export and Local JSON had the same problem. They now keep the clone field (`get_group_definition()` / `get_all_group_definitions()`).
- The "Add term" form kept the previous term's field values (image, color, choices) after a term was added over AJAX.
- Searchable selects in the field group editor: Enter right after typing did nothing until the highlight caught up; it now picks the first match.
- Saving an options page placed under a post type menu redirected to the post list: the return URL had `&#038;` instead of `&`.
- Field group editor: before the page was scrolled, the bottom of the settings panel (for example the last field types of the palette) was cut off by the window. The panel now fits the window from where it is.

## [1.2.1] — 2026-11-10

### Added
- Field group list: a **Trash** tab to restore trashed groups or delete them for good. REST: `GET /wpff/v1/field-groups/trash`, `DELETE /wpff/v1/field-groups/{key}?force=1`.
- Block Bindings: fields with "Allow in Block Bindings" can be picked in the block's **Attributes** panel (WordPress 6.9+), filtered by the post type of the template or post.

### Changed
- `Update URI: false` in the plugin header, so WordPress never offers an update from an unrelated wordpress.org plugin with the same slug.
- The field group list counts sub fields and layout fields, as the field group editor does.

### Fixed
- Block editor: values rejected by the server (invalid email or URL, number limits, `wpff/validate_value`) were dropped on save without a message. They are now checked while editing: the field shows the error, an editor notice lists the errors, and saving waits until the values are valid.
- Block Bindings: a WYSIWYG value bound to a paragraph or heading produced nested `<p>` tags; its paragraphs are now joined with line breaks.
- Field group editor: the field settings panel did not scroll in Chrome, so settings below the fold (such as "Allow in Block Bindings") were out of reach.

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
