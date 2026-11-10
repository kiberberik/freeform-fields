<?php
/**
 * The block being rendered.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Stack of block ids, so get_field() without a post id reads the current block's data,
 * also for blocks rendered inside other blocks.
 */
final class BlockContext {

	/**
	 * @var string[]
	 */
	private static array $stack = array();

	public static function push( string $id ): void {
		self::$stack[] = $id;
	}

	public static function pop(): void {
		array_pop( self::$stack );
	}

	public static function current(): ?string {
		return array() === self::$stack ? null : (string) end( self::$stack );
	}
}
