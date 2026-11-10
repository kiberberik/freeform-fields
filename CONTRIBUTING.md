# Contributing to WP Freeform Fields

Thank you for helping! Bug reports, fixes, translations and documentation are all welcome.

## Ground rules

- **Do not copy code from Advanced Custom Fields or ACF PRO**, even though both are GPL. This project is an independent implementation. Keeping it clean of ACF code keeps the "written from scratch" statement true and avoids disputes.
  - Matching ACF's public behavior is fine: function names, hook names, the storage format, the export format.
  - Write the implementation yourself.
- Keep data compatible with ACF. A site must be able to switch between the two plugins without converting data.
- Every change that touches behavior needs a test.

## Setup

You need PHP 8.1+, Composer, Node 20+ and WP-CLI. Docker and MySQL are not needed.

```bash
composer install
```

```bash
npm install && npm run build
```

```bash
bin/setup-dev.sh
```

```bash
wp --path=.dev/wp server --port=8889
```

The admin login for the dev site is in `.dev/credentials`.

Use `npm start` to rebuild the builder and block scripts on every change.

## Checks

Run these before opening a pull request:

```bash
composer test
```

```bash
composer lint
```

```bash
npm run lint:js
```

```bash
npm run build
```

- `composer test` runs PHPUnit against a real WordPress, with a throwaway SQLite database.
- `composer lint` runs the WordPress Coding Standards (PHPCS).
- `npm run lint:js` runs ESLint and Prettier through `@wordpress/scripts`.

## Code style

- **PHP:**
  - WordPress Coding Standards, PHP 8.1 syntax;
  - classes under `src/`, PSR-4, namespace `FreeformFields\`;
  - global functions and hooks use the `wpff_` and `wpff/` prefixes.
- **Escaping and security:** escape on output, sanitize on input, and check capabilities and nonces on every form, AJAX call and REST route.
- **Strings:** user-facing strings are translatable with the `freeform-fields` text domain.
- **Commits:** write commit messages in English, in the imperative mood ("Add map field", not "Added map field").

## Translations

```bash
npm run build && npm run i18n
```

This updates `languages/freeform-fields.pot` and the `.po`, `.mo` and `.json` files. To add a language, copy the `.pot` file to `freeform-fields-{locale}.po`, translate it, and run `npm run i18n`.

## Reporting bugs

Please include:

- the WordPress, PHP and plugin versions;
- the steps to reproduce;
- what you expected to happen;
- what happened instead.

If the bug involves field settings, attach a JSON export of the field group (**Custom Fields → Tools**).

Report security issues privately; see [SECURITY.md](SECURITY.md).

## Releases

1. Update the version in `freeform-fields.php` (header and `WPFF_VERSION`), `readme.txt` (`Stable tag`) and `package.json`, and add a section to `CHANGELOG.md`.
2. Commit, then build the zip:

```bash
bin/build-zip.sh
```

The script checks that the versions match, builds the scripts, and writes `dist/freeform-fields-<version>.zip` from the committed files plus `build/`. Development files (tests, sources, configs) are left out through `export-ignore` in `.gitattributes`.

3. Tag the commit and push the tag. The **Release** workflow builds the zip and publishes a GitHub release with it:

```bash
git tag v1.2.0 && git push origin v1.2.0
```
