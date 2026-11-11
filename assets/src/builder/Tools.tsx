/**
 * Tools: export, import, migration from ACF and Local JSON status.
 */
import apiFetch from '@wordpress/api-fetch';
import { PageHeader } from './ui';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	Button,
	CheckboxControl,
	Notice,
	Spinner,
	TextareaControl,
} from '@wordpress/components';
import type { ChangeEvent, ReactNode } from 'react';
import type { GroupSummary } from '../types';

/** Outcome of an import (ImportExport::import(), import_acf_all()). */
interface ImportResult {
	imported: Array< { key: string; title: string } >;
	/** Item => message; an empty PHP array arrives as []. */
	errors?: Record< string, string > | [];
	warnings?: string[];
}

/** An ACF item that can be migrated (ToolsController::acf_preview()). */
interface AcfItem {
	key: string;
	title: string;
	/** Number of fields; null for post types, taxonomies and options pages. */
	fields: number | null;
	exists: boolean;
}

/** ACF use found in the theme (Tools\ThemeCheck::run()): name => locations. */
interface ThemeReport {
	functions?: Record< string, string[] > | [];
	hooks?: Record< string, string[] > | [];
	files: number;
}

const base = () => window.wpffBuilder!.restPath;

interface ResultNoticeProps {
	result: ImportResult | null;
	onClose: () => void;
}

function ResultNotice( { result, onClose }: ResultNoticeProps ) {
	if ( ! result ) {
		return null;
	}
	const errors = Object.entries( result.errors || {} );
	return (
		<Notice
			status={ errors.length ? 'warning' : 'success' }
			onRemove={ onClose }
		>
			<p>
				{ sprintf(
					/* translators: %d: number of imported items (field groups, options pages, post types). */
					_n(
						'%d item imported.',
						'%d items imported.',
						result.imported.length,
						'freeform-fields'
					),
					result.imported.length
				) }
			</p>
			{ result.imported.length > 0 && (
				<p>{ result.imported.map( ( g ) => g.title ).join( ', ' ) }</p>
			) }
			{ ( result.warnings || [] ).map( ( w ) => (
				<p key={ w }>{ w }</p>
			) ) }
			{ errors.map( ( [ key, message ] ) => (
				<p key={ key }>
					<code>{ key }</code>: { message }
				</p>
			) ) }
		</Notice>
	);
}

function Export() {
	const [ groups, setGroups ] = useState< GroupSummary[] | null >( null );
	const [ selected, setSelected ] = useState< string[] >( [] );
	const [ php, setPhp ] = useState( '' );

	useEffect( () => {
		apiFetch< GroupSummary[] >( { path: `${ base() }/field-groups` } ).then(
			setGroups
		);
	}, [] );

	const query = () =>
		selected
			.map( ( k ) => `keys[]=${ encodeURIComponent( k ) }` )
			.join( '&' );

	const download = () =>
		apiFetch< { json: unknown } >( {
			path: `${ base() }/tools/export?format=json&${ query() }`,
		} ).then( ( data ) => {
			const blob = new window.Blob(
				[ JSON.stringify( data.json, null, '\t' ) ],
				{ type: 'application/json' }
			);
			const link = document.createElement( 'a' );
			link.href = window.URL.createObjectURL( blob );
			link.download = `wpff-export-${ new Date()
				.toISOString()
				.slice( 0, 10 ) }.json`;
			link.click();
			window.URL.revokeObjectURL( link.href );
		} );

	const showPhp = () =>
		apiFetch< { php: string } >( {
			path: `${ base() }/tools/export?format=php&${ query() }`,
		} ).then( ( data ) => setPhp( data.php ) );

	if ( ! groups ) {
		return <Spinner />;
	}
	const all = groups.map( ( g ) => g.key );
	return (
		<>
			<p className="description">
				{ __(
					'Choose field groups, then download them as JSON (to import elsewhere) or get PHP code to register them in a theme.',
					'freeform-fields'
				) }
			</p>
			<CheckboxControl
				label={ __( 'Select all', 'freeform-fields' ) }
				checked={ selected.length === all.length && all.length > 0 }
				onChange={ ( on ) => setSelected( on ? all : [] ) }
				__nextHasNoMarginBottom
			/>
			<div className="wpff-tools__groups">
				{ groups.map( ( g ) => (
					<CheckboxControl
						key={ g.key }
						label={ g.title }
						checked={ selected.includes( g.key ) }
						onChange={ ( on ) =>
							setSelected(
								on
									? [ ...selected, g.key ]
									: selected.filter( ( k ) => k !== g.key )
							)
						}
						__nextHasNoMarginBottom
					/>
				) ) }
			</div>
			<div className="wpff-tools__actions">
				<Button
					variant="primary"
					onClick={ download }
					disabled={ ! selected.length }
				>
					{ __( 'Export as JSON', 'freeform-fields' ) }
				</Button>
				<Button
					variant="secondary"
					onClick={ showPhp }
					disabled={ ! selected.length }
				>
					{ __( 'Generate PHP', 'freeform-fields' ) }
				</Button>
			</div>
			{ php && (
				<TextareaControl
					label={ __( 'PHP code', 'freeform-fields' ) }
					help={ __(
						"Paste into your theme's functions.php. Delete the database copies afterwards to avoid duplicates.",
						'freeform-fields'
					) }
					value={ php }
					readOnly
					rows={ 16 }
					className="wpff-tools__code"
					onChange={ () => {} }
					__nextHasNoMarginBottom
				/>
			) }
		</>
	);
}

function Import() {
	const [ result, setResult ] = useState< ImportResult | null >( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	const onFile = ( event: ChangeEvent< HTMLInputElement > ) => {
		// A file input always has a FileList.
		const file = event.target.files![ 0 ];
		if ( ! file ) {
			return;
		}
		setError( '' );
		file.text().then( ( text ) => {
			let groups: unknown;
			try {
				groups = JSON.parse( text );
			} catch {
				setError(
					__( 'This file is not valid JSON.', 'freeform-fields' )
				);
				return;
			}
			setBusy( true );
			apiFetch< ImportResult >( {
				path: `${ base() }/tools/import`,
				method: 'POST',
				data: { groups },
			} )
				.then( setResult )
				.catch( ( e ) => setError( e.message ) )
				.finally( () => {
					setBusy( false );
					event.target.value = '';
				} );
		} );
	};

	return (
		<>
			<p className="description">
				{ __(
					'Import a JSON export from WP Freeform Fields or from ACF. Groups with the same key are updated.',
					'freeform-fields'
				) }
			</p>
			<input
				type="file"
				accept=".json,application/json"
				onChange={ onFile }
				disabled={ busy }
				aria-label={ __( 'JSON file', 'freeform-fields' ) }
			/>
			{ busy && <Spinner /> }
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			<ResultNotice
				result={ result }
				onClose={ () => setResult( null ) }
			/>
		</>
	);
}

function ThemeCheck() {
	const [ report, setReport ] = useState< ThemeReport | null | false >(
		null
	);
	useEffect( () => {
		apiFetch< ThemeReport >( { path: `${ base() }/tools/acf-check` } )
			.then( setReport )
			.catch( () => setReport( false ) );
	}, [] );
	if ( null === report ) {
		return <Spinner />;
	}
	if ( ! report ) {
		return null;
	}
	const functions = Object.entries( report.functions || {} );
	const hooks = Object.entries( report.hooks || {} );
	if ( ! functions.length && ! hooks.length ) {
		return (
			<Notice status="success" isDismissible={ false }>
				{ sprintf(
					/* translators: %d: number of PHP files. */
					__(
						'Theme check: %d PHP files use no ACF functions or hooks that WP Freeform Fields lacks.',
						'freeform-fields'
					),
					report.files
				) }
			</Notice>
		);
	}
	const list = ( items: Array< [ string, string[] ] > ) => (
		<ul className="wpff-tools__acf">
			{ items.map( ( [ name, where ] ) => (
				<li key={ name }>
					<code>{ name }</code> — { where.join( ', ' ) }
				</li>
			) ) }
		</ul>
	);
	return (
		<Notice status="warning" isDismissible={ false }>
			<p>
				<strong>
					{ __(
						'Check your theme before deactivating ACF.',
						'freeform-fields'
					) }
				</strong>
			</p>
			{ functions.length > 0 && (
				<>
					<p>
						{ __(
							'These functions are not provided by WP Freeform Fields. Calling them without ACF causes a fatal error:',
							'freeform-fields'
						) }
					</p>
					{ list( functions ) }
				</>
			) }
			{ hooks.length > 0 && (
				<>
					<p>
						{ __(
							'These ACF hooks are not fired by WP Freeform Fields, so the theme code attached to them will stop running:',
							'freeform-fields'
						) }
					</p>
					{ list( hooks ) }
				</>
			) }
		</Notice>
	);
}

function AcfMigration() {
	const [ groups, setGroups ] = useState< AcfItem[] | null >( null );
	const [ result, setResult ] = useState< ImportResult | null >( null );
	const [ busy, setBusy ] = useState( false );

	const load = () =>
		apiFetch< AcfItem[] >( { path: `${ base() }/tools/acf` } ).then(
			setGroups
		);
	useEffect( () => {
		load();
	}, [] );

	const run = () => {
		setBusy( true );
		apiFetch< ImportResult >( {
			path: `${ base() }/tools/acf`,
			method: 'POST',
		} )
			.then( ( data ) => {
				setResult( data );
				load();
			} )
			.finally( () => setBusy( false ) );
	};

	if ( ! groups ) {
		return <Spinner />;
	}
	if ( ! groups.length ) {
		return (
			<>
				<ThemeCheck />
				<p className="description">
					{ __(
						'No field groups created with ACF were found in the database.',
						'freeform-fields'
					) }
				</p>
			</>
		);
	}
	return (
		<>
			<ThemeCheck />
			<p className="description">
				{ __(
					'These field groups were created with ACF. Importing keeps their keys, so values already saved by ACF keep working.',
					'freeform-fields'
				) }
			</p>
			<ul className="wpff-tools__acf">
				{ groups.map( ( g ) => (
					<li key={ g.key }>
						<strong>{ g.title }</strong>
						{ null !== g.fields &&
							' — ' +
								sprintf(
									/* translators: %d: number of fields. */ _n(
										'%d field',
										'%d fields',
										g.fields,
										'freeform-fields'
									),
									g.fields
								) }
						{ g.exists && (
							<span className="wpff-badge">
								{ __( 'already imported', 'freeform-fields' ) }
							</span>
						) }
					</li>
				) ) }
			</ul>
			<Button
				variant="primary"
				onClick={ run }
				isBusy={ busy }
				disabled={ busy }
			>
				{ __( 'Import from ACF', 'freeform-fields' ) }
			</Button>
			<ResultNotice
				result={ result }
				onClose={ () => setResult( null ) }
			/>
		</>
	);
}

function LocalJsonStatus() {
	const info = window.wpffBuilder!.localJson;
	return (
		<>
			{ info.savePath ? (
				<p>
					{ __(
						'Saved field groups are written to:',
						'freeform-fields'
					) }{ ' ' }
					<code>{ info.savePath }</code>
				</p>
			) : (
				<p>
					{ __(
						'Local JSON is off. Create this folder (writable by the web server) to save field groups as JSON files:',
						'freeform-fields'
					) }{ ' ' }
					<code>{ info.suggested }</code>
				</p>
			) }
			{ info.loadPaths.length > 0 && (
				<p>
					{ __( 'Field groups are loaded from:', 'freeform-fields' ) }{ ' ' }
					{ info.loadPaths.map( ( p ) => (
						<code key={ p }>{ p } </code>
					) ) }
				</p>
			) }
			<p className="description">
				{ __(
					'With WP-CLI: wp wpff sync, wp wpff export, wp wpff import <file>, wp wpff import-acf.',
					'freeform-fields'
				) }
			</p>
		</>
	);
}

export default function Tools() {
	const section = ( title: string, body: ReactNode ) => (
		<section className="wpff-panel">
			<div className="wpff-panel__header">
				<h2>{ title }</h2>
			</div>
			<div className="wpff-panel__body">{ body }</div>
		</section>
	);
	return (
		<div className="wpff-tools">
			<PageHeader
				title={ __( 'Tools', 'freeform-fields' ) }
				subtitle={ __(
					'Export, import and move field groups between sites, or switch from ACF.',
					'freeform-fields'
				) }
			/>
			{ section(
				__( 'Export Field Groups', 'freeform-fields' ),
				<Export />
			) }
			{ section(
				__( 'Import Field Groups', 'freeform-fields' ),
				<Import />
			) }
			{ section(
				__( 'Migrate from ACF', 'freeform-fields' ),
				<AcfMigration />
			) }
			{ section(
				__( 'Local JSON', 'freeform-fields' ),
				<LocalJsonStatus />
			) }
		</div>
	);
}
