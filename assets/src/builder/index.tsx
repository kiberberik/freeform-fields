/**
 * Field group builder entry point.
 */
import { createRoot } from '@wordpress/element';
import GroupList from './GroupList';
import GroupEditor from './GroupEditor';
import { OptionsPageEditor, OptionsPageList } from './OptionsPages';
import Tools from './Tools';
import { ContentTypeEditor, ContentTypeList } from './ContentTypes';
import './style.scss';

function App() {
	const { route, groupKey, optionsKey } = window.wpffBuilder!;
	if ( 'content' === route ) {
		return <ContentTypeList />;
	}
	if ( 'content-edit' === route ) {
		return <ContentTypeEditor />;
	}
	if ( 'tools' === route ) {
		return <Tools />;
	}
	if ( 'options' === route ) {
		return <OptionsPageList />;
	}
	if ( 'options-edit' === route ) {
		return <OptionsPageEditor slug={ optionsKey } />;
	}
	if ( 'list' === route ) {
		return <GroupList />;
	}
	return <GroupEditor groupKey={ 'edit' === route ? groupKey : '' } />;
}

const root = document.getElementById( 'wpff-builder' );
if ( root && window.wpffBuilder ) {
	createRoot( root ).render( <App /> );
}
