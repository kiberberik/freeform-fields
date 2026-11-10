<?php
/**
 * Testimonial block template.
 *
 * Available: $block (settings and attributes), $content, $is_preview, $post_id, $wp_block, $context.
 * get_field() reads this block's fields.
 *
 * @package FreeformFields
 */

$quote  = get_field( 'quote' );
$author = get_field( 'author' );
$photo  = get_field( 'photo' );

if ( ! $quote && $is_preview ) {
	echo '<p style="opacity:.6">' . esc_html__( 'Write the quote in the block sidebar.', 'freeform-fields' ) . '</p>';
	return;
}
?>
<figure <?php echo get_block_wrapper_attributes( array( 'class' => 'demo-testimonial' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core escapes. ?>>
	<blockquote><?php echo wp_kses_post( wpautop( (string) $quote ) ); ?></blockquote>
	<figcaption>
		<?php if ( $photo ) : ?>
			<img src="<?php echo esc_url( $photo['sizes']['thumbnail'] ?? $photo['url'] ); ?>" alt="" width="48" height="48" style="border-radius:50%" />
		<?php endif; ?>
		<?php echo esc_html( (string) $author ); ?>
	</figcaption>
</figure>
