/**
 * Shapes used only inside the field group builder.
 */
import type { ReactNode } from 'react';
import type { Field, FieldGroup, FieldType, Layout } from '../types';

/** A group as the REST API returns it: settings plus where it comes from. */
export type EditorGroup = FieldGroup & {
	source?: 'php' | 'json' | 'acf' | 'db';
};

/** A trashed group (GroupStore::trashed()). */
export interface TrashedGroup {
	key: string;
	title: string;
	trashed: string;
}

/** Answer of a trash or permanent delete. */
export interface DeleteResult {
	key: string;
	trashed?: boolean;
	deleted?: boolean;
}

/** Validation errors by path: { 'title': msg, 'fields.0.name': msg }. */
export type BuilderErrors = Record< string, string >;

/** A list of fields inside a field (sub fields, or one layout's sub fields). */
export interface ChildList {
	path: string;
	fields: Field[];
	layout?: Layout;
	layoutIndex?: number;
}

/** Where a field sits in the tree. */
export interface FieldLocation {
	field: Field;
	listPath: string;
	index: number;
	path: string;
}

/** A place in a list: list path and position. */
export interface DropTarget {
	listPath: string;
	index: number;
}

/** Data of a draggable: a field of the tree, or a type from the palette. */
export type DragData =
	| { kind: 'field'; key: string; field: Field }
	| { kind: 'type'; type: FieldType };

/** Data of a droppable: a whole list, or a field row. */
export type DropData =
	| { kind: 'list'; listPath: string; length: number }
	| { kind: 'row'; key: string; listPath: string; index: number };

/** Shared state of the field tree: selection, errors, drop target and actions. */
export interface TreeState {
	selected: string;
	select: ( key: string ) => void;
	errors: BuilderErrors;
	drop: DropTarget | null;
	readOnly: boolean;
	collapsed: string[];
	toggleCollapsed: ( key: string ) => void;
	duplicate: ( key: string ) => void;
	remove: ( key: string ) => void;
	move: ( key: string, listPath: string, index: number ) => void;
	moveOut: ( key: string ) => void;
}

/** Props of the sub field list component passed into the settings forms. */
export interface SubFieldListProps {
	fields: Field[];
	errors: BuilderErrors;
	path: string;
	onChange: ( fields: Field[] ) => void;
}

/** One option of the custom Select. */
export interface SelectOption {
	value: string;
	label: string;
	group?: string;
	icon?: ReactNode;
	hint?: ReactNode;
	disabled?: boolean;
}
