# WP Freeform Fields

**A free, open-source custom fields plugin for WordPress, inspired by ACF PRO and compatible with its data.**

[Website and docs](https://wpff.kiberberik.link) · [Русская версия](README.ru.md) · [Developer guide](docs/developer-guide.md) · [Changelog](CHANGELOG.md) · [Contributing](CONTRIBUTING.md)

WP Freeform Fields adds field groups, repeaters, flexible content, options pages and PHP-rendered blocks to WordPress. Everything is free under the GPL: there is no "Pro" version.

It stores values in the same format as Advanced Custom Fields and offers the same template functions (`get_field()`, `have_rows()`, …). You can switch an existing ACF site over without converting data or rewriting themes.

> **Not affiliated with ACF or WP Engine.** WP Freeform Fields is an independent project. It is not affiliated with, endorsed by, or sponsored by WP Engine or the Advanced Custom Fields team. "Advanced Custom Fields", "ACF" and "ACF PRO" are trademarks of WP Engine; they are used here only to describe compatibility. The plugin was written from scratch and contains no ACF code.

**Requirements:** WordPress 6.5+ and PHP 8.1+. Block Bindings in the editor need WordPress 6.7+, and the Abilities API needs WordPress 6.9+.

## Features

### Building fields
- **Field group builder** in the admin (React):
  - drag and drop with mouse and keyboard;
  - settings for each field type;
  - location rules editor;
  - conditional logic;
  - duplicate, trash and undo;
  - a warning about unsaved changes.
- **35 field types:**
  - **Basic:** Text, Textarea, Number, Range, Email, URL, Password.
  - **Content:** Image, File, WYSIWYG, oEmbed, Gallery.
  - **Choice:** Select, Checkbox, Radio, Button Group, True/False.
  - **Relational:** Link, Post Object, Page Link, Relationship, Taxonomy, User, with search and bidirectional relationships.
  - **Advanced:** Date, Date Time and Time Pickers, Color Picker, and a **Map**. The Map uses OpenStreetMap with no API key, or Google Maps when you add a key.
  - **Layout:** Message, Accordion, Tab, Group, **Repeater** (with pagination), **Flexible Content**, Clone.
- **Location rules** in ACF's format: post type, template, category, taxonomy, user role, options page, block, and your own rules.
- Field groups can be defined **in PHP**, stored in the database, or kept as **Local JSON** in your theme. Local JSON comes with a "Sync available" status.

### Screens
- Posts and pages, in both the classic and block editors.
- Terms and user profiles.
- **Options pages**, defined in code or created in the admin.
- **Post types and taxonomies**, created without code.

### Blocks
- **PHP-template blocks**: `wpff_register_block_type()`, or a `block.json` with an `acf` or `wpff` key.
- Live preview, with the field form in the sidebar.
- **InnerBlocks** support.
- Block data is stored in ACF's format.
- **Block Bindings** source `wpff/field`: show field values in core blocks (paragraph, heading, image, button) with no template.

### For developers
- ACF-compatible functions:
  - `get_field()`, `the_field()`, `get_fields()`, `update_field()`;
  - `have_rows()`, `the_row()`, `get_sub_field()`, `add_row()`;
  - `acf_add_local_field_group()`, `acf_add_options_page()`, `acf_register_block_type()`, and more.
- `wpff_*` versions of all these functions are always available.
- Hooks: `wpff/load_value`, `wpff/format_value`, `wpff/update_value`, `wpff/validate_value` and `wpff/prepare_field`. Each also comes in `/type=`, `/name=` and `/key=` variants.
- **REST API**: field values appear in the `acf` property in `wp/v2`, and can be written back with the same validation as forms.
- **WP-CLI**: `wp wpff list | export | import | sync | import-acf`.
- **Abilities API** (WordPress 6.9+): AI agents and other tools can read field groups and values with the user's permissions.
- A `wp.data` store `wpff/fields` for reading and changing form values in JavaScript.
- **Migration from ACF**, from Tools or WP-CLI. It moves field groups, post types, taxonomies and options pages from the database. Field keys are kept, so existing values stay linked.

### Quality
- **Security:**
  - capability and nonce checks everywhere;
  - HTML filtering for users without `unfiltered_html`;
  - the REST API and Block Bindings never expose private posts, password-protected content or user emails.
- **Performance:** unchanged values are not rewritten, new values are written in one INSERT, and reading all values of an object takes one query.
- **Accessibility:** labeled controls, keyboard-friendly tabs, rows and map search.
- **Translations:** a `.pot` template and a full Russian translation.

## Quick start

```php
add_action( 'wpff/init', function () {
	wpff_add_local_field_group( array(
		'key'      => 'group_book',
		'title'    => 'Book',
		'fields'   => array(
			array( 'key' => 'field_book_isbn', 'name' => 'isbn', 'label' => 'ISBN', 'type' => 'text', 'required' => 1 ),
			array( 'key' => 'field_book_cover', 'name' => 'cover', 'label' => 'Cover', 'type' => 'image', 'return_format' => 'url' ),
			array( 'key' => 'field_book_shop', 'name' => 'shop', 'label' => 'Bookshop', 'type' => 'google_map' ),
		),
		'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ),
	) );
} );
```

```php
// In a template:
echo esc_html( get_field( 'isbn' ) );
printf( '<img src="%s" alt="">', esc_url( get_field( 'cover' ) ) );

$shop = get_field( 'shop' ); // array( 'address' => …, 'lat' => …, 'lng' => …, 'zoom' => … )
```

More examples are in the [developer guide](docs/developer-guide.md) and in [examples/](examples/).

## Moving from ACF

1. Back up the site.
2. Install and activate WP Freeform Fields next to ACF. While ACF is active, WP Freeform Fields stays out of its way: ACF's fields, `acf-json` files, blocks and function names remain ACF's.
3. Go to **Custom Fields → Tools → Migrate from ACF**. Read the **theme check** first: it lists any ACF functions or `acf/…` hooks your theme uses that WP Freeform Fields does not provide. Then import. With WP-CLI, run `wp wpff import-acf --dry-run`, then `wp wpff import-acf`.
4. Deactivate ACF. Themes that use `get_field()` keep working: values are read where ACF saved them, and `acf/…` filters in the theme (for example `acf/load_field` that fills choices) keep running.

If ACF is deactivated before step 3, the site keeps working: field groups and options pages are read from ACF's tables (read-only, marked **ACF** in the list) until you migrate them. The REST output of the [ACF to REST API](https://wordpress.org/plugins/acf-to-rest-api/) plugin (`acf/v3/…` routes and the `acf` property) is served too while that plugin is active and ACF is not, with stricter access: only the fields of each object's groups, core REST visibility rules, and options page fields only for users who may open the page unless the group is shown in REST.

ACF JSON files in `acf-json` are read too. Groups exported from ACF as PHP work as they are.

See [known differences](#known-differences) before switching a production site.

## Map field and external services

The Map field works without an API key: it uses [OpenStreetMap](https://www.openstreetmap.org/) tiles and [Nominatim](https://nominatim.org/) search. To use Google Maps instead, set a key in any of these ways:

```php
define( 'WPFF_GOOGLE_MAPS_API_KEY', 'your-key' );   // wp-config.php
wpff_update_setting( 'google_api_key', 'your-key' ); // or acf_update_setting(), as with ACF
```

These services are contacted only from admin screens that show a Map field, and only to load the map and run the searches an editor starts. The plugin itself sends no data anywhere. You can change the tile server and geocoder with the `wpff/fields/google_map/tiles` and `wpff/fields/google_map/geocoder_url` filters.

## Updates

New versions show up on the Plugins and Updates screens, like updates from wordpress.org. The plugin reads `update.json` from its [latest GitHub release](https://github.com/kiberberik/freeform-fields/releases/latest) with the regular WordPress update check, twice a day, and installs that release's zip. No site data is sent. To turn the check off:

```php
add_filter( 'wpff/updates/enabled', '__return_false' );
```

Version 1.2.2 and older cannot update themselves: install 1.3.0 once by uploading the zip, and later versions arrive as updates.

## Development

```bash
composer install
```

```bash
npm install && npm run build
```

```bash
bin/setup-dev.sh
```

`setup-dev.sh` installs WordPress on SQLite in `.dev/wp`, with no Docker or MySQL needed. It links the plugin and adds demo fields. The admin login is saved to `.dev/credentials`.

```bash
wp --path=.dev/wp server --port=8889
```

```bash
composer test
```

```bash
composer lint
```

```bash
npm run lint:js
```

Tests run PHPUnit against a real WordPress with a throwaway database. See [CONTRIBUTING.md](CONTRIBUTING.md).

## Known differences

- In the block editor, an empty required field blocks every save, drafts included. ACF lets you save drafts.
- Repeater pagination only splits rows into pages in the editor. All rows are still loaded and saved together, so very large repeaters load as slowly as without pagination.
- `the_field()` escapes its output with `wp_kses_post()`, as ACF does since 6.2.5. Pages printed with an older ACF may differ in markup details such as attribute order or a trailing `;` in `style`. `get_field()` is not escaped.
- Group layouts `table` and `row` are simplified: `table` is shown as `block`.
- The block form lives in the sidebar. The old in-block "edit" mode of ACF blocks is not implemented.
- Tabs and accordions work in post meta boxes. On term and user screens, fields are shown as a list.
- Conditional logic is checked only in the browser, as in ACF.
- `setFieldValue()` in the `wpff/fields` store does not handle images, files, relations or repeater rows.
- Only part of ACF's API exists: the template functions, `acf_form()`, the common `acf_get_*` helpers and the main `acf/…` hooks. The theme check on the migration screen lists what is missing for your theme.
- `acf_form()` has no basic uploader: image and file fields in front-end forms work only for users who can upload files.
- There is no WPGraphQL integration yet.

## Credits

- Map editor: [Leaflet](https://leafletjs.com/) (BSD-2-Clause). Map data © [OpenStreetMap contributors](https://www.openstreetmap.org/copyright) (ODbL).
- Builder drag and drop: [dnd kit](https://dndkit.com/) (MIT).
- Full license texts of bundled libraries: [THIRD-PARTY-NOTICES.txt](THIRD-PARTY-NOTICES.txt).
- Thanks to the authors of Advanced Custom Fields for the conventions this plugin is compatible with.

## License

[GPL-2.0-or-later](LICENSE). Copyright © 2026 [kiberberik](https://kiberberik.link) and WP Freeform Fields contributors.
