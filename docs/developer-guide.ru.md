# WP Freeform Fields — руководство разработчика

Все функции существуют в двух вариантах: с префиксом `wpff_` и, если ACF не активен, под именами ACF (`get_field()`, `have_rows()`…). Отключить имена ACF: `define( 'WPFF_ACF_COMPAT', false );` или фильтр `wpff/acf_compat`.

Аргумент `$post_id` везде понимает форматы ACF: ID записи, `'term_5'` (или `'category_5'`), `'user_3'`, `'comment_7'`, `'option'` / `'options'`, свой префикс страницы настроек (`'site_settings'`), объекты `WP_Post` / `WP_Term` / `WP_User`, а без аргумента — текущую запись (внутри шаблона блока — текущий блок).

## Регистрация из кода

```php
add_action( 'wpff/init', function () {
	wpff_add_local_field_group( array(
		'key'      => 'group_book',
		'title'    => 'Книга',
		'fields'   => array(
			array( 'key' => 'field_book_isbn', 'name' => 'isbn', 'label' => 'ISBN', 'type' => 'text', 'required' => 1 ),
			array(
				'key'        => 'field_book_chapters',
				'name'       => 'chapters',
				'label'      => 'Главы',
				'type'       => 'repeater',
				'sub_fields' => array(
					array( 'key' => 'field_book_ch_title', 'name' => 'title', 'label' => 'Название', 'type' => 'text' ),
				),
			),
		),
		'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'book' ) ) ),
	) );

	wpff_add_options_page( array( 'page_title' => 'Настройки темы', 'menu_slug' => 'theme-settings' ) );

	wpff_register_block_type( array(
		'name'            => 'hero',
		'title'           => 'Hero',
		'render_template' => get_template_directory() . '/blocks/hero.php',
	) );
} );
```

Ключ поля (`key`) можно не указывать: он будет сгенерирован стабильно из ключа группы и имени. Но если поле уже хранит данные в ACF, укажите прежний ключ.

Формат групп совпадает с экспортом ACF, поэтому PHP-код из ACF («Tools → Generate PHP») работает как есть.

## Чтение и запись значений

| Функция | Что делает |
|---|---|
| `get_field( $name, $post_id = false, $format = true )` | значение с форматированием (возвращаемый формат, абзацы и т. д.) |
| `the_field( $name, $post_id )` | выводит значение, экранированное `wp_kses_post()` |
| `get_field_object( $name, $post_id )` | настройки поля + `value` |
| `get_fields( $post_id )` | все значения объекта (`имя => значение`) |
| `update_field( $name_or_key, $value, $post_id )` | записывает значение; для повторителя — список строк `[ [ 'sub' => … ], … ]`, для гибкого содержимого — строки с `acf_fc_layout` |
| `delete_field( $name, $post_id )` | удаляет значение (и подзначения строк) |

Значения хранятся как в ACF: `{имя}` + `_{имя}` = ключ поля; подполя группы — `{группа}_{подполе}`; строки — `{имя}` = число строк (у гибкого содержимого — список макетов) и `{имя}_{i}_{подполе}`; страницы настроек — `options_{имя}` в `wp_options`.

## Циклы по строкам

```php
while ( have_rows( 'sections' ) ) : the_row();
	if ( 'gallery' === get_row_layout() ) {
		while ( have_rows( 'slides' ) ) : the_row();   // вложенный повторитель
			echo esc_html( get_sub_field( 'caption' ) );
		endwhile;
	}
	echo get_row_index(); // 1, 2, …
endwhile;
```

Также: `the_sub_field()`, `get_sub_field_object()`, `get_row( $format )`, `reset_rows()`, `add_row( $name, $row, $post_id )`, `update_row( $name, $i, $row, $post_id )`, `delete_row( $name, $i, $post_id )` (номер строки с 1).

## Блоки

Шаблон получает `$block` (настройки и атрибуты, `$block['id']`), `$content`, `$is_preview`, `$post_id`, `$wp_block`, `$context`. `get_field()` / `have_rows()` без `$post_id` читают данные текущего блока.

`block.json` в формате ACF:

```json
{
	"apiVersion": 3,
	"name": "acf/testimonial",
	"title": "Testimonial",
	"acf": { "mode": "preview", "renderTemplate": "testimonial.php" }
}
```

и `register_block_type( __DIR__ . '/blocks/testimonial' );`. Поля блоку назначаются группой с правилом «Block».

Имя без пространства имён получает префикс: `wpff_register_block_type( [ 'name' => 'hero' ] )` → `wpff/hero`, `acf_register_block_type()` → `acf/hero` (как в ACF, чтобы старый контент открывался).

### InnerBlocks

С `'supports' => [ 'jsx' => true ]` (или `"supports": { "jsx": true }` в `block.json`) шаблон может разместить вложенные блоки тегом `<InnerBlocks />`. На сайте тег заменяется сохранёнными блоками, в редакторе — редактируемой областью. Атрибуты как в ACF (массивы — в JSON):

```php
<InnerBlocks
	allowedBlocks="<?php echo esc_attr( wp_json_encode( [ 'core/paragraph', 'core/image' ] ) ); ?>"
	template="<?php echo esc_attr( wp_json_encode( [ [ 'core/paragraph', [ 'placeholder' => 'Текст…' ] ] ] ) ); ?>"
	templateLock="false"
/>
```

### Block Bindings

Источник `wpff/field` выводит значение поля в стандартных блоках без шаблона (WordPress 6.5+, в редакторе — 6.7+). У поля должна быть включена настройка «Allow in Block Bindings» (`'allow_in_bindings' => 1`): по умолчанию она выключена, чтобы служебные поля не попали на сайт.

```html
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"wpff/field","args":{"key":"subtitle"}}}}} -->
<p></p>
<!-- /wp:paragraph -->
```

Для картинок и ссылок атрибут `url` получает адрес, `alt` / `title` — соответствующие части массива. Значения выводятся только для записей, которые посетитель может читать.

## Совместимость с ACF

Пока активен ACF (ACF PRO или Secure Custom Fields), WP Freeform Fields ему не мешает:

- функции с именами ACF не объявляются, хуки `acf/…` не вызываются;
- папки `acf-json` не читаются, а блоки, у которых в `block.json` только ключ `acf`, остаются за ACF;
- уведомление в админке подсказывает, как завершить переход.

Без ACF слой совместимости включён (отключается `WPFF_ACF_COMPAT` или фильтром `wpff/acf_compat`):

- **Функции** объявляются на `setup_theme`, до `functions.php` темы. Если тема несёт свою копию ACF, они не объявляются вовсе, чтобы её ACF загрузился (фильтр `wpff/theme_bundles_acf`).
- **Хуки:** каждый из них срабатывает как `wpff/…`, затем как `acf/…`, с аргументами как в ACF и вариантами `/type=`, `/name=`, `/key=`, где они есть у ACF: `init`, `include_fields`, `load_field`, `load_value`, `format_value`, `update_value`, `validate_value`, `prepare_field`, `load_field_groups`, `save_post`, `pre_save_post`, `submit_form`, `settings/{имя}`, `fields/{post_object|relationship|page_link|taxonomy|user}/query`, `input/admin_enqueue_scripts`. Во время `acf/save_post` в `$_POST['acf']` лежат отправленные значения, как ожидают обработчики ACF.
- **Дополнительные функции:** `get_field_objects()`, `acf_get_field()`, `acf_get_fields()`, `acf_get_field_group()`, `acf_get_field_groups()`, `acf_add_local_field()`, `update_sub_field()`, `delete_sub_field()`, `acf_esc_html()`, `acf_get_options_page()`, `acf_get_options_pages()`, `acf_form_head()`, `acf_form()`. Версии с префиксом: `wpff_get_field_objects()`, `wpff_get_field_settings()`, `wpff_get_group_fields()`, `wpff_get_field_groups()`, `wpff_update_sub_field()`, `wpff_delete_sub_field()`.
- **Сырые значения** (`get_field( $name, $id, false )`, `get_fields( $id, false )`) имеют форму как в ACF: значения Group, Repeater и Flexible Content — с ключами полей, пустые строки — `false`.
- **До переноса**, пока ACF неактивен, группы полей и страницы настроек из таблиц ACF читаются оттуда (только чтение, `source` равен `acf`). Отключается фильтром `wpff/read_acf_groups`. Список кешируется в транзиенте, который меняется вместе с группами ACF.
- **ACF to REST API:** пока плагин `acf-to-rest-api` активен, а ACF нет, его маршруты `acf/v3/{rest_base}/{id}[/{field}]` и `acf/v3/options/{id}[/{field}]` и свойство `acf` в `wp/v2` обслуживает WP Freeform Fields. Отдаются только поля групп, показанных на объекте, — никакой другой меты и опций; поля Password не отдаются. Записи, рубрики и пользователи видны по правилам REST API ядра. Поля страниц настроек требуют права страницы, если их группа не показана в REST; фильтр `wpff/acf_to_rest_api/public_options` делает их публичными, как в оригинале. Фильтр: `wpff/emulate_acf_to_rest_api`.

### Формы на сайте

`acf_form()` принимает настройки ACF: `id`, `post_id` (`'new_post'` вместе с `new_post` создаёт запись), `field_groups`, `fields`, `post_title`, `post_content`, `return` (`%post_id%`, `%post_url%`), `html_before_fields`, `html_after_fields`, `submit_value`, `updated_message`, `honeypot`, `kses` и другие. Отправка сохраняется на `template_redirect`, поэтому `acf_form_head()` не обязателен.

Настройки формы передаются подписанными: посетитель не может направить форму на другую запись. Как и в ACF, кто видит форму, решает шаблон. Проверка выполняется на сервере, отклонённые поля подсвечиваются на возвращённой странице.

## Хуки

| Хук | Тип | Назначение |
|---|---|---|
| `wpff/init` | action | время регистрировать группы, страницы, блоки |
| `wpff/register_field_types` | action | `$registry->register( new My_Field() )` — свой тип поля (наследник `FreeformFields\Fields\AbstractField`) |
| `wpff/load_value`, `wpff/format_value`, `wpff/update_value` | filter | `( $value, $post_id, $field )`; варианты `/type=…`, `/name=…`, `/key=…` |
| `wpff/validate_value` | filter | `( $valid, $value, $field, $post_id )`; верните строку, чтобы отклонить значение; варианты `/type=`, `/name=`, `/key=` |
| `wpff/prepare_field` | filter | `( $field )` перед выводом; `false` скрывает поле |
| `wpff/pre_update_value` | filter | `false` — не сохранять значение |
| `wpff/save_post` | action | `( $post_id, $errors )` после сохранения формы |
| `wpff/save_field_group`, `wpff/delete_field_group` | action | после сохранения / удаления группы в админке |
| `wpff/load_field_groups` | filter | все группы до нормализации |
| `wpff/location/matchers` | filter | свои правила размещения: `'param' => fn( $rule, $screen ) => bool` |
| `wpff/settings/save_json`, `wpff/settings/load_json` | filter | папки Local JSON |
| `wpff/options_autoload` | filter | автозагрузка значений страницы настроек |
| `wpff/capability` | filter | право на управление группами (по умолчанию `manage_options`) |
| `wpff/acf_compat` | filter | включать ли функции с именами ACF |
| `wpff/form_lifetime` | filter | сколько секунд фронтенд-форма действительна после показа (по умолчанию двое суток) |
| `wpff/cache_values` | filter | `( $cache, $post_id )`; значения, прочитанные через `get_field()` / `get_fields()`, хранятся до конца запроса и сбрасываются при изменении meta и опций. Верните `false`, если ваш код меняет значения в обход хуков meta и опций WordPress |
| `wpff/cache_field_groups` | filter | хранить ли группы из админки (и файлы Local JSON) в объектном кеше между запросами (по умолчанию `true`; полезно с Redis или Memcached) |
| `wpff/format/readable_post` | filter | `( $readable, $post, $field )`; возвращать ли выбранную запись или вложение в полях Post Object, Relationship, Page Link, Image, File и Gallery. По умолчанию — опубликованные записи и вложения, не загруженные в приватную запись; остальные только если текущий пользователь может их читать |
| `wpff/updates/enabled` | filter | проверять ли обновления в релизах GitHub (по умолчанию `true`) |
| `wpff/updates/manifest_url` | filter | адрес манифеста обновлений (по умолчанию `update.json` последнего релиза) |

## Поле «Карта» (`google_map`)

Значение — массив как в ACF: `address`, `lat`, `lng`, `zoom` и части адреса (`city`, `country`, `post_code`, `place_id`…). Без ключа API используется OpenStreetMap и поиск Nominatim; с ключом — Google Maps и Geocoding API. Ключ:

```php
define( 'WPFF_GOOGLE_MAPS_API_KEY', '…' );            // или
wpff_update_setting( 'google_api_key', '…' );          // или acf_update_setting() / фильтр acf/fields/google_map/api
```

Фильтры: `wpff/fields/google_map/api_key`, `wpff/fields/google_map/tiles` (`[ 'url' => …, 'attribution' => … ]`), `wpff/fields/google_map/geocoder_url` (совместимый с Nominatim сервер).

## Настройки

`wpff_update_setting( $name, $value )` / `wpff_get_setting( $name, $default )` — настройки на время запроса (вызывайте в теме или плагине при каждой загрузке); фильтр `wpff/settings/{name}`. Без ACF доступны `acf_update_setting()` и `acf_get_setting()`.

## Abilities API

В WordPress 6.9+ плагин регистрирует способности категории `custom-fields` (доступны в REST `wp-abilities/v1` и для MCP-клиентов):

* `wpff/list-field-groups` — группы с полями и правилами размещения (право управления группами);
* `wpff/get-field-values` — значения объекта без форматирования, вход `{ "object_id": 12 }` или `"term_5"`, `"user_3"`, `"option"` (право редактировать объект).

Способности только читают данные.

## REST API

* Группы с настройкой «Show in REST API» добавляют свойство `acf` к записям, рубрикам и пользователям в `wp/v2`. По умолчанию значения без форматирования (ID), с типами как в ACF: числа и логические значения как есть, ID вложений и объектов — целые числа, пустые — `null`; `?acf_format=standard` — с форматированием, но без закрытых данных: связи только на доступные запрашивающему записи (`ID`, `post_title`, `post_type`, `permalink`), пользователи — `ID` и `display_name` (email — только с правом `list_users`), поля записей с паролем скрыты.
* Запись: `POST /wp/v2/posts/12` с `{"acf": {"rating": 4, "steps": [{"step": "Один"}]}}` — проверка как у форм; неизвестные поля и неверные значения дают 400.
* Служебные маршруты `wpff/v1/*` (группы, страницы настроек, типы записей, инструменты) доступны только администраторам.

## JavaScript: хранилище `wpff/fields`

На экранах с полями (в т. ч. в редакторе блоков):

```js
const price = wp.data.select( 'wpff/fields' ).getFieldValue( 'price' );
wp.data.dispatch( 'wpff/fields' ).setFieldValue( 'price', 1500 );
wp.data.subscribe( () => console.log( wp.data.select( 'wpff/fields' ).getFieldValues() ) );
```

`setFieldValue` поддерживает простые поля (текст, число, выбор, флажки, переключатели, да/нет, даты, цвет, визуальный редактор).

## WP-CLI

```
wp wpff list
wp wpff export [<key>...] [--format=json|php] [--file=<file>]
wp wpff import <file>
wp wpff sync [--all]
wp wpff import-acf [--dry-run]
```

`import-acf` переносит из базы ACF типы записей, таксономии, страницы настроек и группы полей (в этом порядке).

## Мультиязычные сайты (WPML, Polylang)

Если активен WPML или Polylang, поля работают с переводами так же, как ACF с ACFML. Настраивать что-то в коде не нужно. Настройки называются так же, как в ACFML, поэтому группы полей и значения переносятся между плагинами без изменений.

**Вариант перевода группы полей.** В редакторе группы есть блок «Настройка мультиязычности» с тремя вариантами (настройка группы `acfml_field_group_mode`):

| Вариант | Значение | Текстовые поля (`text`, `textarea`, `wysiwyg`) | Остальные поля |
|---|---|---|---|
| Одинаковые поля во всех языках | `translation` | Переводить | Копировать (с синхронизацией) |
| Разные поля в разных языках | `localization` | Переводить | Копировать один раз |
| Эксперт | `advanced` | Как задано у поля | Как задано у поля |

В варианте «Эксперт» у каждого поля есть настройка «Перевод» (`wpml_cf_preferences`): `0` не переводить, `1` копировать, `2` переводить, `3` копировать один раз. Группа без варианта считается «Экспертом», если у её полей заданы настройки (группы из старых версий ACFML), иначе — «Разными полями в разных языках»: группы, созданные до поддержки мультиязычности, не перезаписывают значения в существующих переводах, пока вы не выберете вариант. Новые группы создаются с «Одинаковыми полями».

- **Копировать:** значение оригинала попадает во все переводы и синхронизируется. Число строк повторителя и гибкого содержимого тоже копируется, поэтому структура у переводов одинаковая.
- **Копировать один раз:** копируется при создании перевода, дальше у каждого языка своё значение.
- **Переводить:** в WPML отправляется в редактор переводов и автоматический перевод. В Polylang копируется при создании перевода как основа, дальше редактируется в каждом языке.
- **Не переводить:** никогда не копируется.

Как это устроено:

- **WPML** хранит настройку перевода для каждого meta-ключа. Плагин записывает в настройки WPML каждый ключ, который сохраняет (`cards_0_title`, `_cards_0_title`, …): при сохранении значений, при сохранении группы и один раз после изменения групп. Дальше WPML сам копирует, синхронизирует и отправляет поля на перевод.
- **Polylang** спрашивает, какие meta-ключи копировать (`pll_copy_post_metas`, `pll_copy_term_metas`). Плагин отвечает своими ключами: при создании перевода — всеми, кроме «не переводить», при синхронизации — только «копировать».

**ID следуют за переводами.** Когда копируется значение поля «Изображение», «Файл», «Галерея», «Объект записи», «Ссылка на страницу», «Связь» или «Таксономия», каждый ID заменяется его переводом на нужный язык, если перевод есть. `get_field()` делает то же при выводе: значение, указывающее на запись другого языка, вернёт её перевод на текущем языке. Фильтры: `wpff/multilingual/id_types` (типы полей с ID), `wpff/multilingual/translate_ids_on_display` (верните false, чтобы получать сохранённые ID).

**Страницы настроек** хранят значения отдельно для каждого языка, как ACF: язык по умолчанию — `options_{name}`, остальные — `options_{lang}_{name}`. Язык берётся из переключателя в верхней панели; «Все языки» редактируют язык по умолчанию. Если у языка нет своего значения, показывается значение языка по умолчанию (отключается фильтром `wpff/multilingual/options_fallback`). Как и в ACF, по языкам разделяется только ключ хранения `options`; для других ключей это решает `wpff/multilingual/localize_options`.

**Подписи.** Заголовки групп, подписи полей, инструкции, подсказки, варианты выбора и названия макетов регистрируются как строки (WPML String Translation, «Перевод строк» в Polylang) в контексте `WP Freeform Fields: {ключ группы}` и показываются переведёнными на экранах редактирования.

**Правила местоположения** «Запись», «Страница» и «Родительская страница» срабатывают и для переводов этой записи.

Другие фильтры: `wpff/multilingual/provider` (верните `null`, чтобы выключить поддержку, или свой `FreeformFields\Multilingual\Provider`), `wpff/multilingual/text_types` (типы, которые переводятся как текст), `wpff/multilingual/field_preference` (настройка отдельного поля).

WPML хранит одну настройку на meta-ключ для всего сайта. Если в двух группах есть поле с одинаковым именем и разными вариантами перевода, для этого ключа действует группа, сохранённая последней. В ACFML то же ограничение. Дайте таким полям разные имена или выберите в обеих группах один вариант.

## Производительность

Замер `bin/benchmark.php` (100 полей + повторитель на 1000 строк × 3 подполя, SQLite-эмуляция в dev-окружении; на MySQL запись быстрее):

| Операция | Время | Запросов |
|---|---|---|
| Сохранение новых 1000 строк | 2,6 с | 6 003 (1 INSERT на значение) |
| Повторное сохранение без изменений | 26 мс | 1 |
| Изменение 2 значений | 24 мс | 5 |
| `get_fields()` всех значений (холодный кеш) | 39 мс | 1 |
| Цикл `have_rows()` по 1000 строкам с 3 `get_sub_field()` | 45 мс | 1 |
| Вывод метабокса с 1000 строками | 110 мс, 2,4 МБ HTML | 1 |

Запуск: `WPFF_DB_FILE=wpff-tests.sqlite wp --path=.dev/wp eval-file bin/benchmark.php` (после `composer test`).

## Переводы

Шаблон `languages/freeform-fields.pot` и русский перевод (`freeform-fields-ru_RU.po/.mo`, JSON для скриптов). После изменения строк: `npm run build && npm run i18n`, затем переведите новые строки в `.po` и снова `npm run i18n`.
