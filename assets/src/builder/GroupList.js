/**
 * Field group list screen.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	Button,
	DropdownMenu,
	MenuGroup,
	MenuItem,
	Notice,
	SearchControl,
	Spinner,
} from '@wordpress/components';
import {
	copy,
	moreVertical,
	pencil,
	plus,
	trash,
	backup as restoreIcon,
	update as syncIcon,
	listView,
	search as searchIcon,
} from '@wordpress/icons';
import {
	listGroups,
	trashGroup,
	restoreGroup,
	listTrash,
	deleteGroup,
	duplicateGroup,
	syncGroup,
} from './api';
import { describeLocation } from './LocationRules';
import { modeLabels, multilingual } from './MultilingualSetup';
import { EmptyState, PageHeader, Pill } from './ui';

export default function GroupList() {
	const cfg = window.wpffBuilder;
	const [ groups, setGroups ] = useState( null );
	const [ trashed, setTrashed ] = useState( [] );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ filter, setFilter ] = useState( 'all' );
	const [ search, setSearch ] = useState( '' );

	const load = () =>
		Promise.all( [ listGroups(), listTrash() ] )
			.then( ( [ list, inTrash ] ) => {
				setGroups( list );
				setTrashed( inTrash );
				if ( ! inTrash.length ) {
					setFilter( ( f ) => ( 'trash' === f ? 'all' : f ) );
				}
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: e.message } )
			);

	useEffect( () => {
		load();
	}, [] );

	const run = ( key, action, success ) => {
		setBusy( key );
		action( key )
			.then( ( result ) => {
				setNotice( success( result ) );
				return load();
			} )
			.catch( ( e ) => setNotice( { status: 'error', text: e.message } ) )
			.finally( () => setBusy( '' ) );
	};

	const actions = {
		trash: ( group ) =>
			run( group.key, trashGroup, () => ( {
				status: 'success',
				text: sprintf(
					/* translators: %s: field group title. */
					__( '“%s” moved to the Trash.', 'freeform-fields' ),
					group.title
				),
				undoKey: group.key,
			} ) ),
		duplicate: ( group ) =>
			run( group.key, duplicateGroup, ( result ) => ( {
				status: 'success',
				text: sprintf(
					/* translators: %s: field group title. */
					__( 'Created “%s”.', 'freeform-fields' ),
					result.title
				),
			} ) ),
		sync: ( group ) =>
			run( group.key, syncGroup, () => ( {
				status: 'success',
				text: sprintf(
					/* translators: %s: field group title. */
					__( '“%s” synced from its JSON file.', 'freeform-fields' ),
					group.title
				),
			} ) ),
	};

	const trashActions = {
		restore: ( group ) =>
			run( group.key, restoreGroup, () => ( {
				status: 'success',
				text: sprintf(
					/* translators: %s: field group title. */
					__( '“%s” restored.', 'freeform-fields' ),
					group.title
				),
			} ) ),
		remove: ( group ) => {
			// eslint-disable-next-line no-alert
			const sure = window.confirm(
				sprintf(
					/* translators: %s: field group title. */
					__(
						'Delete “%s” permanently? This cannot be undone. Values saved in posts are kept.',
						'freeform-fields'
					),
					group.title
				)
			);
			if ( sure ) {
				run( group.key, deleteGroup, () => ( {
					status: 'success',
					text: sprintf(
						/* translators: %s: field group title. */
						__( '“%s” deleted permanently.', 'freeform-fields' ),
						group.title
					),
				} ) );
			}
		},
	};

	const onUndo = ( key ) =>
		run( key, restoreGroup, () => ( {
			status: 'success',
			text: __( 'Field group restored.', 'freeform-fields' ),
		} ) );

	const filters = groups
		? [
				[ 'all', __( 'All', 'freeform-fields' ), groups.length ],
				[
					'active',
					__( 'Active', 'freeform-fields' ),
					groups.filter( ( g ) => g.active ).length,
				],
				[
					'inactive',
					__( 'Inactive', 'freeform-fields' ),
					groups.filter( ( g ) => ! g.active ).length,
				],
				[
					'sync',
					__( 'Sync available', 'freeform-fields' ),
					groups.filter( ( g ) => g.sync ).length,
				],
				[ 'trash', __( 'Trash', 'freeform-fields' ), trashed.length ],
			].filter( ( [ id, , count ] ) => 'all' === id || count > 0 )
		: [];

	const query = search.trim().toLowerCase();
	const visible = ( groups || [] ).filter( ( g ) => {
		if ( 'active' === filter && ! g.active ) {
			return false;
		}
		if ( 'inactive' === filter && g.active ) {
			return false;
		}
		if ( 'sync' === filter && ! g.sync ) {
			return false;
		}
		return (
			! query ||
			g.title.toLowerCase().includes( query ) ||
			g.key.includes( query ) ||
			( g.description || '' ).toLowerCase().includes( query )
		);
	} );

	return (
		<div className="wpff-list">
			<PageHeader
				title={ __( 'Field Groups', 'freeform-fields' ) }
				subtitle={ __(
					'Sets of custom fields and the screens they appear on.',
					'freeform-fields'
				) }
				actions={
					<Button
						variant="primary"
						icon={ plus }
						href={ cfg.newUrl }
						__next40pxDefaultSize
					>
						{ __( 'Add field group', 'freeform-fields' ) }
					</Button>
				}
			/>

			{ notice && (
				<Notice
					className="wpff-notice"
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
					actions={
						notice.undoKey
							? [
									{
										label: __( 'Undo', 'freeform-fields' ),
										onClick: () => onUndo( notice.undoKey ),
									},
								]
							: []
					}
				>
					{ notice.text }
				</Notice>
			) }

			{ null === groups && (
				<div className="wpff-loading">
					<Spinner />
				</div>
			) }

			{ groups && 0 === groups.length && 0 === trashed.length && (
				<div className="wpff-panel">
					<EmptyState
						icon={ listView }
						title={ __( 'No field groups yet', 'freeform-fields' ) }
						text={ __(
							'A field group is a set of fields — price, gallery, author bio — shown on the screens you choose.',
							'freeform-fields'
						) }
					>
						<Button
							variant="primary"
							icon={ plus }
							href={ cfg.newUrl }
						>
							{ __(
								'Create your first field group',
								'freeform-fields'
							) }
						</Button>
					</EmptyState>
				</div>
			) }

			{ groups && ( groups.length > 0 || trashed.length > 0 ) && (
				<div className="wpff-panel">
					<div className="wpff-toolbar">
						<div
							className="wpff-filters"
							role="tablist"
							aria-label={ __(
								'Filter field groups',
								'freeform-fields'
							) }
						>
							{ filters.map( ( [ id, label, count ] ) => (
								<button
									key={ id }
									type="button"
									role="tab"
									aria-selected={ filter === id }
									className={
										filter === id ? 'is-active' : ''
									}
									onClick={ () => setFilter( id ) }
								>
									{ label }
									<span className="wpff-count">
										{ count }
									</span>
								</button>
							) ) }
						</div>
						<SearchControl
							className="wpff-toolbar__search"
							value={ search }
							onChange={ setSearch }
							placeholder={ __(
								'Search groups',
								'freeform-fields'
							) }
							__nextHasNoMarginBottom
						/>
					</div>

					{ 'trash' === filter && (
						<TrashTable
							items={ trashed.filter(
								( g ) =>
									! query ||
									g.title.toLowerCase().includes( query ) ||
									g.key.includes( query )
							) }
							busy={ busy }
							actions={ trashActions }
						/>
					) }
					{ 'trash' !== filter && 0 === visible.length && (
						<EmptyState
							icon={ searchIcon }
							title={ __( 'Nothing found', 'freeform-fields' ) }
							text={ __(
								'Try another search or filter.',
								'freeform-fields'
							) }
						/>
					) }
					{ 'trash' !== filter && visible.length > 0 && (
						<table className="wpff-table">
							<thead>
								<tr>
									<th>
										{ __(
											'Field group',
											'freeform-fields'
										) }
									</th>
									<th>
										{ __( 'Shown on', 'freeform-fields' ) }
									</th>
									<th className="wpff-table__num">
										{ __( 'Fields', 'freeform-fields' ) }
									</th>
									{ multilingual() && (
										<th>
											{ __(
												'Translation',
												'freeform-fields'
											) }
										</th>
									) }
									<th>
										{ __( 'Status', 'freeform-fields' ) }
									</th>
									<th>
										<span className="screen-reader-text">
											{ __(
												'Actions',
												'freeform-fields'
											) }
										</span>
									</th>
								</tr>
							</thead>
							<tbody>
								{ visible.map( ( group ) => (
									<GroupRow
										key={ group.key }
										group={ group }
										busy={ busy === group.key }
										actions={ actions }
									/>
								) ) }
							</tbody>
						</table>
					) }
				</div>
			) }
		</div>
	);
}

/**
 * Trashed groups with Restore and Delete permanently.
 *
 * @param {Object} props
 * @param {Array}  props.items   Trashed groups: key, title, trashed (timestamp).
 * @param {string} props.busy    Key of the group being changed.
 * @param {Object} props.actions restore( group ), remove( group ).
 */
function TrashTable( { items, busy, actions } ) {
	if ( ! items.length ) {
		return (
			<EmptyState
				icon={ searchIcon }
				title={ __( 'Nothing found', 'freeform-fields' ) }
				text={ __(
					'Try another search or filter.',
					'freeform-fields'
				) }
			/>
		);
	}
	return (
		<table className="wpff-table">
			<thead>
				<tr>
					<th>{ __( 'Field group', 'freeform-fields' ) }</th>
					<th>{ __( 'Moved to Trash', 'freeform-fields' ) }</th>
					<th>
						<span className="screen-reader-text">
							{ __( 'Actions', 'freeform-fields' ) }
						</span>
					</th>
				</tr>
			</thead>
			<tbody>
				{ items.map( ( group ) => (
					<tr
						key={ group.key }
						className={ busy === group.key ? 'is-busy' : '' }
					>
						<td className="wpff-table__primary">
							<div className="wpff-group-title">
								<strong>{ group.title }</strong>
							</div>
							<code className="wpff-group-key">
								{ group.key }
							</code>
						</td>
						<td>
							{ group.trashed
								? new Date(
										Number( group.trashed ) * 1000
									).toLocaleString()
								: '—' }
						</td>
						<td className="wpff-table__actions">
							<Button
								icon={ restoreIcon }
								size="compact"
								variant="tertiary"
								disabled={ busy === group.key }
								onClick={ () => actions.restore( group ) }
							>
								{ __( 'Restore', 'freeform-fields' ) }
							</Button>
							<Button
								icon={ trash }
								size="compact"
								variant="tertiary"
								isDestructive
								disabled={ busy === group.key }
								onClick={ () => actions.remove( group ) }
							>
								{ __(
									'Delete permanently',
									'freeform-fields'
								) }
							</Button>
						</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

/**
 * Location rules as chips: one chip per OR-group.
 *
 * @param {Object} props
 * @param {Array}  props.location Rules.
 */
function LocationChips( { location } ) {
	const groups = ( location || [] )
		.map( ( rules ) => describeLocation( [ rules ] ) )
		.filter( Boolean );
	if ( ! groups.length ) {
		return <span className="wpff-muted">—</span>;
	}
	return (
		<span className="wpff-chips">
			{ groups.map( ( text, i ) => (
				<span className="wpff-chip" key={ i } title={ text }>
					{ text }
				</span>
			) ) }
		</span>
	);
}

function sourceHint( source ) {
	if ( 'acf' === source ) {
		return __(
			'Read from ACF (read-only). Migrate it in Tools.',
			'freeform-fields'
		);
	}
	if ( 'php' === source ) {
		return __( 'Registered in code (read-only)', 'freeform-fields' );
	}
	return __( 'Loaded from a JSON file', 'freeform-fields' );
}

function GroupRow( { group, busy, actions } ) {
	const cfg = window.wpffBuilder;
	const editUrl = `${ cfg.listUrl }&group=${ group.key }`;
	const isLocal = 'php' === group.source || 'acf' === group.source;
	const source = {
		php: __( 'PHP', 'freeform-fields' ),
		json: 'JSON',
		acf: 'ACF',
	}[ group.source ];

	return (
		<tr className={ busy ? 'is-busy' : '' }>
			<td className="wpff-table__primary">
				<div className="wpff-group-title">
					{ isLocal ? (
						<strong>{ group.title }</strong>
					) : (
						<a href={ editUrl }>
							<strong>{ group.title }</strong>
						</a>
					) }
					{ source && (
						<span
							className="wpff-tag"
							title={ sourceHint( group.source ) }
						>
							{ source }
						</span>
					) }
				</div>
				{ group.description && (
					<p className="wpff-group-desc">{ group.description }</p>
				) }
				<code className="wpff-group-key">{ group.key }</code>
			</td>
			<td>
				<LocationChips location={ group.location } />
			</td>
			<td className="wpff-table__num">{ group.field_count }</td>
			{ multilingual() && (
				<td>{ modeLabels()[ group.translation ] || '' }</td>
			) }
			<td>
				{ group.sync && (
					<Pill tone="warning">
						{ __( 'Sync available', 'freeform-fields' ) }
					</Pill>
				) }
				{ ! group.sync &&
					( group.active ? (
						<Pill tone="success">
							{ __( 'Active', 'freeform-fields' ) }
						</Pill>
					) : (
						<Pill>{ __( 'Inactive', 'freeform-fields' ) }</Pill>
					) ) }
			</td>
			<td className="wpff-table__actions">
				{ ! isLocal && (
					<Button
						icon={ pencil }
						label={ __( 'Edit', 'freeform-fields' ) }
						href={ editUrl }
						size="compact"
					/>
				) }
				<DropdownMenu
					icon={ moreVertical }
					label={ sprintf(
						/* translators: %s: field group title. */
						__( 'Actions for “%s”', 'freeform-fields' ),
						group.title
					) }
					toggleProps={ { size: 'compact', disabled: busy } }
				>
					{ ( { onClose } ) => (
						<>
							<MenuGroup>
								{ group.sync && (
									<MenuItem
										icon={ syncIcon }
										onClick={ () => {
											actions.sync( group );
											onClose();
										} }
									>
										{ __(
											'Sync from JSON',
											'freeform-fields'
										) }
									</MenuItem>
								) }
								<MenuItem
									icon={ copy }
									onClick={ () => {
										actions.duplicate( group );
										onClose();
									} }
								>
									{ isLocal
										? __(
												'Copy to an editable group',
												'freeform-fields'
											)
										: __( 'Duplicate', 'freeform-fields' ) }
								</MenuItem>
							</MenuGroup>
							{ ! isLocal && (
								<MenuGroup>
									<MenuItem
										icon={ trash }
										isDestructive
										onClick={ () => {
											actions.trash( group );
											onClose();
										} }
									>
										{ __(
											'Move to Trash',
											'freeform-fields'
										) }
									</MenuItem>
								</MenuGroup>
							) }
						</>
					) }
				</DropdownMenu>
				<span className="screen-reader-text">
					{ sprintf(
						/* translators: %d: number of fields. */
						_n(
							'%d field',
							'%d fields',
							group.field_count,
							'freeform-fields'
						),
						group.field_count
					) }
				</span>
			</td>
		</tr>
	);
}
