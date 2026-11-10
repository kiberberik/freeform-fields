<?php
/**
 * Call-to-action block template (registered with wpff_register_block_type()).
 *
 * @package FreeformFields
 */

$heading = get_field( 'heading' );
$buttons = get_field( 'buttons' ) ?: array();
?>
<section <?php echo get_block_wrapper_attributes( array( 'class' => 'demo-cta' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core escapes. ?> style="padding:24px;background:#f0f6fc">
	<h2><?php echo esc_html( $heading ? $heading : __( 'Call to action', 'freeform-fields' ) ); ?></h2>
	<?php
	// Replaced with the inner blocks on the site and with an editable area in the editor.
	$cta_allowed  = array( 'core/paragraph', 'core/list', 'core/image' );
	$cta_template = array( array( 'core/paragraph', array( 'placeholder' => 'Describe the offer…' ) ) );
	?>
	<InnerBlocks allowedBlocks="<?php echo esc_attr( wp_json_encode( $cta_allowed ) ); ?>" template="<?php echo esc_attr( wp_json_encode( $cta_template ) ); ?>" />
	<?php while ( have_rows( 'buttons' ) ) : ?>
		<?php the_row(); ?>
		<?php $cta_link = get_sub_field( 'link' ); ?>
		<?php if ( $cta_link ) : ?>
			<a class="wp-element-button" href="<?php echo esc_url( $cta_link['url'] ); ?>"<?php echo '_blank' === $cta_link['target'] ? ' target="_blank" rel="noopener"' : ''; ?>><?php echo esc_html( $cta_link['title'] ? $cta_link['title'] : $cta_link['url'] ); ?></a>
		<?php endif; ?>
	<?php endwhile; ?>
</section>
