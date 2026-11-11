/**
 * Choices editor: free text while typing, stored as the text until saved.
 *
 * The server turns "value : Label" lines into a { value: label } map, so the text form is sent as is.
 */
import { TextControl, TextareaControl } from '@wordpress/components';
import { useState } from '@wordpress/element';

export function ChoiceField( { value, onChange, toText, ...props } ) {
	const [ text, setText ] = useState( toText( value ) );
	return (
		<TextareaControl
			{ ...props }
			value={ text }
			rows={ 4 }
			placeholder={ 'red : Red\ngreen : Green' }
			onChange={ ( t ) => {
				setText( t );
				onChange( t );
			} }
		/>
	);
}

/**
 * Number input built on the stable TextControl.
 *
 * @param {Object}   props          Props.
 * @param {*}        props.value    Current value.
 * @param {Function} props.onChange Receives the new value as a string ('' when empty).
 */
export function NumberInput( { value, onChange, ...props } ) {
	return (
		<TextControl
			{ ...props }
			type="number"
			value={ value ?? '' }
			onChange={ ( v ) => onChange( v ) }
			__nextHasNoMarginBottom
		/>
	);
}
