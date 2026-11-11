/**
 * Shapes shared by the builder, the block editor script and the field inputs.
 *
 * They follow the PHP side: field and group arrays (FieldRegistry / GroupStore), and the
 * settings each screen prints into `window.*` (see globals.d.ts).
 */

/** A choice list as stored: value => label. */
export type Choices = Record< string, string >;

/** One condition of a field's conditional logic. */
export interface Condition {
	field: string;
	operator: string;
	value?: string;
}

/** Conditional logic: OR of AND-groups, or off. */
export type ConditionalLogic = Condition[][] | 0 | false | '' | null;

/** One location rule. */
export interface LocationRule {
	param: string;
	operator: '==' | '!=' | string;
	value: string;
}

/** Location: OR of AND-groups. */
export type Location = LocationRule[][];

/** A flexible content layout. */
export interface Layout {
	key: string;
	name: string;
	label: string;
	display?: string;
	min?: number | string;
	max?: number | string;
	sub_fields: Field[];
	[ setting: string ]: unknown;
}

/**
 * A field. Core settings are typed; type-specific settings (choices, return_format, min, …)
 * vary by type and are reached through the index signature.
 */
export interface Field {
	key: string;
	label: string;
	name: string;
	type: string;
	instructions?: string;
	required?: number | boolean;
	conditional_logic?: ConditionalLogic;
	wrapper?: { width?: string; class?: string; id?: string };
	default_value?: unknown;
	sub_fields?: Field[];
	layouts?: Layout[];
	parent?: string;
	menu_order?: number;
	[ setting: string ]: unknown;
}

/** A field group. */
export interface FieldGroup {
	key: string;
	title: string;
	fields: Field[];
	location: Location;
	menu_order?: number;
	position?: string;
	style?: string;
	label_placement?: string;
	instruction_placement?: string;
	hide_on_screen?: string[] | '';
	active?: boolean | number;
	description?: string;
	show_in_rest?: boolean | number;
	[ setting: string ]: unknown;
}

/** A group as listed on the field groups screen (BuilderData::summary()). */
export interface GroupSummary {
	key: string;
	title: string;
	description: string;
	active: boolean;
	source: 'php' | 'json' | 'acf' | 'db';
	sync: boolean;
	field_count: number;
	location: Location;
	menu_order: number;
	translation?: string;
	[ key: string ]: unknown;
}

/** A type-specific setting shown in the builder (AbstractField::setting()). */
export interface TypeSetting {
	name: string;
	label: string;
	control: string;
	help?: string;
	options?: Choices;
	show_if?: Record< string, unknown >;
	[ key: string ]: unknown;
}

/** A field type as described to the builder (BuilderData::field_types()). */
export interface FieldType {
	name: string;
	label: string;
	category: string;
	group: string;
	settings: TypeSetting[];
	hasValue: boolean;
	hasSubs: boolean;
	compound: 'group' | 'rows' | 'layouts' | null;
	defaults: Record< string, unknown >;
}

/** A location parameter (BuilderData::location_params()). */
export interface LocationParam {
	name: string;
	group: string;
	label: string;
	values: Choices | null;
}

/** Error body of a failed REST call. */
export interface RestError {
	code?: string;
	message?: string;
	data?: {
		status?: number;
		/** Validation problems, by field path. */
		errors?: Array< { path: string; message: string } >;
		[ key: string ]: unknown;
	};
}
