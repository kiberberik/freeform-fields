# WP Freeform Fields — developer guide

[Русская версия](developer-guide.ru.md)

## Function names

Every function has two names:

- **`wpff_` prefix**: `wpff_get_field()`, `wpff_have_rows()`, and so on. These are always available.
- **ACF names**: `get_field()`, `have_rows()`, and so on. These are defined only when ACF itself is not active.

To turn the ACF names off, use `define( 'WPFF_ACF_COMPAT', false );` or the `wpff/acf_compat` filter.

## The `$post_id` argument

`$post_id` accepts ACF's forms:

- a post ID;
- `'term_5'` (or `'category_5'`);
- `'user_3'`;
- `'comment_7'`;
- `'option'` / `'options'`;
- an options page storage key such as `'site_settings'`;
- a `WP_Post`, `WP_Term` or `WP_User` object.

Without the argument, functions use the current post. Inside a block template, they use the current block.

## Registering from code

```php
add_action( 'wpff/init', function () {
	wpff_add_local_field_group( array(
		'key'      => 'group_book',
		'title'    => 'Book',
		'fields'   => array(
			array( 'key' => 'field_book_isbn', 'name' => 'isbn', 'label' => 'ISBN', 'type' => 'text', 'required' => 1 ),
			array(
				'key'        => 'field_book_chapters',
				'name'       => 'chapters',
				'label'      => 'Chapters',
				'type'       => 'repeater',
				'pagination' => 1,
				'rows_per_page' => 20,
				'sub_fields' => array(
					array( 'key' => 'field_book_ch_title', 'name' => 'title', 'label' => 'Title', 'type' => 'text' ),
				),
			),
		),
		'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'book' ) ) ),
	) );

	wpff_add_options_page( array( 'page_title' => 'Theme settings', 'menu_slug' => 'theme-settings' ) );

	wpff_register_block_type( array(
		'name'            => 'hero',
		'title'           => 'Hero',
		'render_template' => get_template_directory() . '/blocks/hero.php',
	) );
} );
```

**Field keys.** You can leave out a field's `key`: a stable key is then generated from the group key and the field name. If the field already has data saved by ACF, give it the old key.

**ACF format.** Field groups use the same format as ACF exports, so PHP code from ACF's "Tools → Generate PHP" works as it is.

## Reading and writing values

| Function | What it does |
|---|---|
| `get_field( $name, $post_id = false, $format = true )` | Returns the value formatted by the field's return format. |
| `the_field( $name, $post_id )` | Prints the value, escaped with `wp_kses_post()`. |
| `get_field_object( $name, $post_id )` | Returns the field settings plus its `value`. |
| `get_fields( $post_id )` | Returns all values of an object as `name => value`. |
| `update_field( $name_or_key, $value, $post_id )` | Writes a value. For a repeater, pass a list of rows: `[ [ 'sub' => … ], … ]`. For flexible content, give each row an `acf_fc_layout`. |
| `delete_field( $name, $post_id )` | Deletes the value, including sub values in rows. |

Values are stored as ACF stores them:

| What | Storage |
|---|---|
| A field | `{name}`, plus `_{name}` holding the field key |
| Group sub fields | `{group}_{sub}` |
| Repeater rows | `{name}` holds the number of rows (for flexible content, the list of layouts); each value is `{name}_{i}_{sub}` |
| Options pages | `options_{name}` in `wp_options` |

## Row loops

```php
while ( have_rows( 'sections' ) ) : the_row();
	if ( 'gallery' === get_row_layout() ) {
		while ( have_rows( 'slides' ) ) : the_row();   // nested repeater
			echo esc_html( get_sub_field( 'caption' ) );
		endwhile;
	}
	echo get_row_index(); // 1, 2, …
endwhile;
```

Other row functions (row numbers start at 1):

- `the_sub_field()`, `get_sub_field_object()`;
- `get_row( $format )`, `reset_rows()`;
- `add_row( $name, $row, $post_id )`;
- `update_row( $name, $i, $row, $post_id )`;
- `delete_row( $name, $i, $post_id )`.

## Blocks

A block template gets these variables:

- `$block`: block settings and attributes, including `$block['id']`;
- `$content`;
- `$is_preview`;
- `$post_id`;
- `$wp_block`;
- `$context`.

Inside the template, `get_field()` and `have_rows()` without `$post_id` read the current block's data.

You can register a block with a `block.json` in ACF's format:

```json
{
	"apiVersion": 3,
	"name": "acf/testimonial",
	"title": "Testimonial",
	"acf": { "mode": "preview", "renderTemplate": "testimonial.php" }
}
```

Then call `register_block_type( __DIR__ . '/blocks/testimonial' );`. A `wpff` key works too. To give the block fields, create a field group with the location rule "Block".

A block name without a namespace gets one:

- `wpff_register_block_type( [ 'name' => 'hero' ] )` registers `wpff/hero`;
- `acf_register_block_type()` registers `acf/hero`, as ACF does, so existing content keeps working.

### InnerBlocks

To let a template place nested blocks with an `<InnerBlocks />` tag, set `'supports' => [ 'jsx' => true ]`, or `"supports": { "jsx": true }` in `block.json`.

On the site, the tag is replaced with the saved inner blocks. In the editor, it becomes an editable area.

Attributes work as in ACF; arrays are passed as JSON:

```php
<InnerBlocks
	allowedBlocks="<?php echo esc_attr( wp_json_encode( [ 'core/paragraph', 'core/image' ] ) ); ?>"
	template="<?php echo esc_attr( wp_json_encode( [ [ 'core/paragraph', [ 'placeholder' => 'Text…' ] ] ] ) ); ?>"
	templateLock="false"
/>
```

### Block Bindings

The `wpff/field` source shows a field value in core blocks with no template. It needs WordPress 6.5+, and 6.7+ to preview values in the editor.

The field must have "Allow in Block Bindings" turned on (`'allow_in_bindings' => 1`). The setting is off by default, so internal fields never reach the site by accident.

```html
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"wpff/field","args":{"key":"subtitle"}}}}} -->
<p></p>
<!-- /wp:paragraph -->
```

For image and link fields:

- the `url` attribute gets the address;
- `alt` and `title` get the matching parts of the value.

Values are shown only for posts the visitor can read.

## ACF compatibility

While ACF (or ACF PRO, or Secure Custom Fields) is active, WP Freeform Fields leaves it alone:

- ACF-named functions are not defined, and `acf/…` hooks are not fired;
- `acf-json` folders are not read, and blocks whose `block.json` has only an `acf` key stay ACF's;
- an admin notice explains how to finish the switch.

Without ACF, the compatibility layer is on (turn it off with `WPFF_ACF_COMPAT` or the `wpff/acf_compat` filter):

- **Functions** are defined on `setup_theme`, before the theme's `functions.php`. If the active theme bundles its own copy of ACF, they are not defined at all, so the theme's ACF can load (filter: `wpff/theme_bundles_acf`).
- **Hooks:** each of these runs as `wpff/…` and then as `acf/…`, with ACF's arguments and `/type=`, `/name=`, `/key=` variants where ACF has them: `init`, `include_fields`, `load_field`, `load_value`, `format_value`, `update_value`, `validate_value`, `prepare_field`, `load_field_groups`, `save_post`, `pre_save_post`, `submit_form`, `settings/{name}`, `fields/{post_object|relationship|page_link|taxonomy|user}/query`, `input/admin_enqueue_scripts`. During `acf/save_post`, `$_POST['acf']` holds the submitted values, as ACF callbacks expect.
- **Extra functions:** `get_field_objects()`, `acf_get_field()`, `acf_get_fields()`, `acf_get_field_group()`, `acf_get_field_groups()`, `acf_add_local_field()`, `update_sub_field()`, `delete_sub_field()`, `acf_esc_html()`, `acf_get_options_page()`, `acf_get_options_pages()`, `acf_form_head()`, `acf_form()`. Their `wpff_` versions: `wpff_get_field_objects()`, `wpff_get_field_settings()`, `wpff_get_group_fields()`, `wpff_get_field_groups()`, `wpff_update_sub_field()`, `wpff_delete_sub_field()`.
- **Raw values** (`get_field( $name, $id, false )`, `get_fields( $id, false )`) have ACF's shape: group, repeater and flexible content values are keyed by field keys, and empty rows are `false`.
- **Before migration**, while ACF is inactive, field groups and options pages saved in ACF's tables are read from there (read-only, `source` is `acf`). Turn it off with the `wpff/read_acf_groups` filter. The list is cached in a transient that changes when ACF's groups change.
- **ACF to REST API:** while the `acf-to-rest-api` plugin is active and ACF is not, its `acf/v3/{rest_base}/{id}[/{field}]` and `acf/v3/options/{id}[/{field}]` routes and the `acf` property in `wp/v2` are served from WP Freeform Fields. Only fields of the groups shown on the object are served, never other meta or options, and Password fields are left out. Posts, terms and users follow the core REST API's visibility rules. Options page fields need the page's capability unless their group is shown in REST; the `wpff/acf_to_rest_api/public_options` filter makes them public, as in the original. Filter: `wpff/emulate_acf_to_rest_api`.

### Front-end forms

`acf_form()` takes ACF's settings: `id`, `post_id` (`'new_post'` with `new_post` args creates a post), `field_groups`, `fields`, `post_title`, `post_content`, `return` (`%post_id%`, `%post_url%`), `html_before_fields`, `html_after_fields`, `submit_value`, `updated_message`, `honeypot`, `kses` and others. Submissions are saved on `template_redirect`, so `acf_form_head()` is optional.

The form settings travel signed: a visitor cannot point the form at another post. As in ACF, the template decides who sees the form. Validation runs on the server, and rejected fields are highlighted on the returned page.

## Hooks

| Hook | Type | Purpose |
|---|---|---|
| `wpff/init` | action | Register groups, options pages and blocks here. |
| `wpff/register_field_types` | action | Add your own field type, a subclass of `FreeformFields\Fields\AbstractField`: `$registry->register( new My_Field() )`. |
| `wpff/load_value`, `wpff/format_value`, `wpff/update_value` | filter | `( $value, $post_id, $field )`. Also `/type=…`, `/name=…` and `/key=…` variants. |
| `wpff/validate_value` | filter | `( $valid, $value, $field, $post_id )`. Return a message string to reject the value. Also `/type=`, `/name=` and `/key=` variants. |
| `wpff/prepare_field` | filter | `( $field )`, before the field is rendered. Return `false` to hide the field. |
| `wpff/pre_update_value` | filter | Return `false` to skip saving a value. |
| `wpff/save_post` | action | `( $post_id, $errors )`, after a form is saved. |
| `wpff/save_field_group`, `wpff/delete_field_group` | action | After a group is saved or deleted in the admin. |
| `wpff/load_field_groups` | filter | All groups, before they are normalized. |
| `wpff/location/matchers` | filter | Custom location rules: `'param' => fn( $rule, $screen ) => bool`. |
| `wpff/settings/save_json`, `wpff/settings/load_json` | filter | Local JSON folders. |
| `wpff/settings/{name}` | filter | A setting value (see below). |
| `wpff/options_autoload` | filter | Whether options page values are autoloaded. |
| `wpff/capability` | filter | Capability needed to manage field groups. Default: `manage_options`. |
| `wpff/acf_compat` | filter | Whether to define functions with ACF's names. |
| `wpff/fields/google_map/api_key` | filter | Google Maps API key. Return `''` to use OpenStreetMap. |
| `wpff/fields/google_map/tiles` | filter | Tile server: `[ 'url' => …, 'attribution' => … ]`. |
| `wpff/fields/google_map/geocoder_url` | filter | A Nominatim-compatible geocoder. |

## Map field (`google_map`)

The value is an array, as in ACF:

- `address`, `lat`, `lng` and `zoom`;
- address parts: `city`, `country`, `post_code`, `place_id` and so on.

Which map is used depends on the API key:

- **No key:** OpenStreetMap with Nominatim search.
- **With a key:** Google Maps and the Geocoding API.

Set the key in any of these ways:

```php
define( 'WPFF_GOOGLE_MAPS_API_KEY', '…' );   // or
wpff_update_setting( 'google_api_key', '…' ); // or acf_update_setting(), or the acf/fields/google_map/api filter
```

## Settings

`wpff_update_setting( $name, $value )` and `wpff_get_setting( $name, $default )` hold settings for the current request:

- call `wpff_update_setting()` from your theme or plugin on every load;
- a setting can be changed with the `wpff/settings/{name}` filter;
- without ACF, `acf_update_setting()` and `acf_get_setting()` are available too.

## Abilities API

On WordPress 6.9+, the plugin registers abilities in the `custom-fields` category. They are available through the `wp-abilities/v1` REST routes and to MCP clients.

| Ability | Returns | Permission |
|---|---|---|
| `wpff/list-field-groups` | Field groups with their fields and location rules. | Manage field groups. |
| `wpff/get-field-values` | An object's raw values. Input: `{ "object_id": 12 }`, `"term_5"`, `"user_3"` or `"option"`. | Edit the object. |

Both abilities are read-only.

## REST API

**Reading.** Groups with "Show in REST API" add an `acf` property to posts, terms and users in `wp/v2`.

- By default, values are raw (IDs), typed as in ACF: numbers and booleans as such, attachment and object IDs as integers, empty ones as `null`.
- With `?acf_format=standard`, values are formatted but never leak private data:
  - relations include only posts the requester can read (`ID`, `post_title`, `post_type`, `permalink`);
  - users include `ID` and `display_name`, and their email only for those with `list_users`;
  - fields of password-protected posts are hidden.

**Writing.** Send `POST /wp/v2/posts/12` with `{"acf": {"rating": 4, "steps": [{"step": "One"}]}}`. Values are validated as in forms; unknown fields and invalid values return 400.

**Internal routes.** The `wpff/v1/*` routes (groups, options pages, post types, tools) are for administrators only.

## JavaScript: the `wpff/fields` store

On screens with fields, including the block editor:

```js
const price = wp.data.select( 'wpff/fields' ).getFieldValue( 'price' );
wp.data.dispatch( 'wpff/fields' ).setFieldValue( 'price', 1500 );
wp.data.subscribe( () => console.log( wp.data.select( 'wpff/fields' ).getFieldValues() ) );
```

`setFieldValue` supports simple fields: text, number, choices, checkboxes, radios, true/false, dates, color and the visual editor.

Field types with their own scripts can start in newly added rows by listening for the `wpff:init` jQuery event on `document`. The event's argument is the new container.

## WP-CLI

```
wp wpff list
wp wpff export [<key>...] [--format=json|php] [--file=<file>]
wp wpff import <file>
wp wpff sync [--all]
wp wpff import-acf [--dry-run]
```

`import-acf` imports what ACF stored in the database, in this order: post types, taxonomies, options pages, then field groups.

## Performance

These numbers come from `bin/benchmark.php`: 100 fields plus a repeater with 1,000 rows × 3 sub fields, on the SQLite emulation of the dev environment. MySQL writes faster.

| Operation | Time | Queries |
|---|---|---|
| Save 1,000 new rows | 2.6 s | 6,003 (1 INSERT per value) |
| Save again, nothing changed | 26 ms | 1 |
| Change 2 values | 24 ms | 5 |
| `get_fields()` with a cold cache | 39 ms | 1 |
| `have_rows()` over 1,000 rows with 3 `get_sub_field()` | 45 ms | 1 |
| Render a meta box with 1,000 rows | 110 ms, 2.4 MB of HTML | 1 |

To run the benchmark after `composer test`:

```bash
WPFF_DB_FILE=wpff-tests.sqlite wp --path=.dev/wp eval-file bin/benchmark.php
```

## Translations

The template is `languages/freeform-fields.pot`. A Russian translation ships with the plugin: `freeform-fields-ru_RU.po`, `.mo`, and JSON files for scripts.

After you change strings:

1. Run `npm run build && npm run i18n`.
2. Translate the new strings in the `.po` file.
3. Run `npm run i18n` again.
