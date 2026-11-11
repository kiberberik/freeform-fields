/**
 * Choices editor: free text while typing, stored as the text until saved.
 *
 * The server turns "value : Label" lines into a { value: label } map, so the text form is sent as is.
 */
import type { ComponentProps } from 'react';
import { TextControl, TextareaControl } from '@wordpress/components';
import { useState } from '@wordpress/element';

interface ChoiceFieldProps extends Omit<
	ComponentProps< typeof TextareaControl >,
	'value' | 'onChange'
> {
	value: unknown;
	onChange: ( text: string ) => void;
	toText: ( value: unknown ) => string;
}

export function ChoiceField( {
	value,
	onChange,
	toText,
	...props
}: ChoiceFieldProps ) {
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

interface NumberInputProps extends Omit<
	ComponentProps< typeof TextControl >,
	'value' | 'onChange' | 'type'
> {
	value: string | number | null | undefined;
	onChange: ( value: string ) => void;
}

/**
 * Number input built on the stable TextControl.
 *
 * @param props          Props.
 * @param props.value    Current value.
 * @param props.onChange Receives the new value as a string ('' when empty).
 */
export function NumberInput( { value, onChange, ...props }: NumberInputProps ) {
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
