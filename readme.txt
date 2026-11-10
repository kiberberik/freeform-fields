=== WP Freeform Fields ===
Contributors: kiberberik
Tags: custom fields, meta box, repeater, options page, blocks
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free custom fields: repeaters, flexible content, options pages and PHP blocks. Compatible with ACF data and template functions.

== Description ==

WP Freeform Fields is a free, open-source custom fields plugin, inspired by ACF PRO. Everything is included: there is no paid version.

It stores values in the same format as Advanced Custom Fields and provides the same template functions (`get_field()`, `have_rows()`, `the_sub_field()`, …). Existing themes keep working, and a site can be moved over without converting data.

**Not affiliated with ACF or WP Engine.** This is an independent project. It is not affiliated with, endorsed by, or sponsored by WP Engine or the Advanced Custom Fields team. "Advanced Custom Fields", "ACF" and "ACF PRO" are trademarks of WP Engine, used only to describe compatibility. The plugin was written from scratch and contains no ACF code.

= Features =

* Field group builder with drag and drop, conditional logic and location rules.
* 35 field types, including Repeater (with pagination), Flexible Content, Gallery, Clone, Group, Relationship and a Map. The Map uses OpenStreetMap without a key, or Google Maps with one.
* Fields on posts, pages, custom post types, terms, users and options pages.
* Options pages, post types and taxonomies, created from code or in the admin.
* Blocks rendered by PHP templates, with InnerBlocks support and ACF-compatible block data.
* A Block Bindings source for showing field values in core blocks.
* Local JSON, JSON and PHP export and import, and migration from ACF: field groups, post types, taxonomies and options pages.
* REST API fields, WP-CLI commands, Abilities API support and a `wp.data` store.
* Translation-ready, with a full Russian translation included.

= Moving from ACF =

1. Back up your site.
2. Activate WP Freeform Fields.
3. Open Custom Fields → Tools → Migrate from ACF.
4. Deactivate ACF.

Field keys are kept, so saved values stay linked.

== External services ==

The Map field loads maps and searches addresses with outside services. They are contacted only from admin screens that show a Map field, and only for the map display and the searches an editor starts. The plugin itself sends nothing else.

* **OpenStreetMap tiles** (tile.openstreetmap.org) are used when no Google Maps API key is set. The browser loads map images for the area shown. See the [tile usage policy](https://operations.osmfoundation.org/policies/tiles/) and the [privacy policy](https://osmfoundation.org/wiki/Privacy_Policy).
* **Nominatim** (nominatim.openstreetmap.org) is used without a Google key. The browser sends the address an editor searches for, or the coordinates of a point clicked on the map. See the [usage policy](https://operations.osmfoundation.org/policies/nominatim/) and the [privacy policy](https://osmfoundation.org/wiki/Privacy_Policy).
* **Google Maps JavaScript API and Geocoding API** are used only when a site owner adds an API key. The browser loads Google's map script and sends searched addresses and clicked coordinates. See the [Google Maps Platform terms](https://cloud.google.com/maps-platform/terms) and the [privacy policy](https://policies.google.com/privacy).

Developers can change the tile server and the geocoder with the `wpff/fields/google_map/tiles` and `wpff/fields/google_map/geocoder_url` filters.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/freeform-fields` or install it from the Plugins screen.
2. Activate it.
3. Go to Custom Fields → Add New to create your first field group.

== Credits ==

* Map editor: Leaflet (BSD-2-Clause). Map data © OpenStreetMap contributors (ODbL).
* Builder drag and drop: dnd kit (MIT).
* License texts: THIRD-PARTY-NOTICES.txt in the plugin folder.

== Frequently Asked Questions ==

= Can I use it together with ACF? =

Both can be active, but then ACF's function names (`get_field()`, …) belong to ACF. In your own code, use the `wpff_` versions (`wpff_get_field()`, …). For a clean switch, migrate and deactivate ACF.

= Will my theme's get_field() calls keep working after switching? =

Yes. Values are read from the same meta keys, and the functions return the same formats.

= Do I need a Google Maps API key? =

No. Without a key the Map field uses OpenStreetMap. Add a key only if you prefer Google Maps.

== Changelog ==

= 1.2.1 =
* Block editor: values rejected by validation (for example an invalid email) are no longer dropped without a message; saving waits until they are fixed.
* Field group list: a Trash tab to restore or permanently delete groups; field counts include sub fields.
* Block Bindings: pick a field in the block's Attributes panel; WYSIWYG values no longer nest paragraphs.
* Field group editor: the field settings panel scrolls in Chrome.

= 1.2.0 =
* Migration from ACF PRO checked end to end: values, pages, REST output and admin screens stay the same after ACF is removed.
* Sites keep working if ACF is deactivated before migration; the ACF to REST API output is kept.
* Redesigned admin screens, drag and drop in the field builder, searchable selects.

= 1.1.0 =
* Safe to install next to ACF: ACF's fields, acf-json files and blocks are left to it.
* ACF hooks, acf_form() and more ACF functions work after switching; a theme check lists anything missing.

= 1.0.0 =
* First public release. See CHANGELOG.md for details.
