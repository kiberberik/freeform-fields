/**
 * Settings the PHP side prints into `window`, and globals shared between bundles.
 */
import type { Choices, FieldType, LocationParam } from './types';

/** Field group builder (Admin\FieldGroupsPage::enqueue()). */
export interface BuilderSettings {
	route: string;
	groupKey: string;
	optionsKey: string;
	optionsUrl: string;
	options: {
		parents: Choices;
		capabilities: Choices;
	};
	content: {
		kind: 'post_type' | 'taxonomy';
		item: string;
		listUrl: string;
		postTypes: Choices;
		menuNames: Choices;
		taxonomies: Choices;
		supports: Choices;
	};
	localJson: {
		savePath: string;
		loadPaths: string[];
		suggested: string;
	};
	listUrl: string;
	newUrl: string;
	restPath: string;
	fieldTypes: FieldType[];
	location: LocationParam[];
	/** Translation setup; null without a multilingual plugin. */
	multilingual: { provider: string; textTypes: string[] } | null;
}

/** A field that block bindings may read (BlockEditor::bindable_fields()). */
export interface BindableField {
	key: string;
	label: string;
	/** '' for the current post, 'term' for the archive term, else an options page storage key. */
	object: string;
	postTypes: string[];
}

/** Block editor (Blocks\BlockEditor). */
export interface BlocksSettings {
	blocks: Array< {
		name: string;
		mode: string;
		hasFields: boolean;
		innerBlocks: boolean;
	} >;
	restPath: string;
	bindings: BindableField[];
}

/** Map field (Fields\Types\GoogleMap::enqueue_assets()). */
export interface MapSettings {
	provider: 'osm' | 'google';
	/** '' without a key. */
	googleKey: string;
	language: string;
	tiles: { url: string; attribution: string };
	geocoderUrl: string;
}

/**
 * Field forms (Admin\Assets::enqueue_form()). Printed with wp_localize_script(), which turns
 * every scalar into a string: `postId` 0 arrives as "0", `blockEditor` false as "".
 */
export interface AdminSettings {
	ajaxUrl: string;
	nonce: string;
	restUrl: string;
	restNonce: string;
	/** Validation object id. */
	object: string;
	formSelector: string;
	postId: string;
	/** "1" in the block editor, "" otherwise. */
	blockEditor: string;
}

/** What the field form script (build/admin.js) lends to the block editor script. */
export interface FieldsApi {
	/** Starts every widget inside a newly added piece of form. */
	initFieldsIn: ( root: Element ) => void;
	/** Re-evaluates conditional logic on the whole page. */
	applyConditions: () => void;
	/** Shows messages keyed by field path; `reveal` (default true) opens and scrolls to the first. */
	showErrors: ( errors: Record< string, string >, reveal?: boolean ) => void;
	/** Clears every field error on the page. */
	clearErrors: () => void;
	syncEditors: () => void;
	removeEditors: ( root: ParentNode ) => void;
}

declare global {
	interface Window {
		wpffBuilder?: BuilderSettings;
		wpffBlocks?: BlocksSettings;
		wpffMap?: MapSettings;
		wpffAdmin?: AdminSettings;
		wpff?: FieldsApi;
		wpffGoogleMapsReady?: () => void;
		/** WordPress globals not bundled (media, editor, data store in the form script). */
		wp: any; // WordPress runtime namespace: no complete types.
		jQuery: JQueryStatic;
		google?: any; // Google Maps JS API, loaded on demand.
	}
}
