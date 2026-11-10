<?php
/**
 * Example: a field group with every built-in field type, shown on posts.
 *
 * Copy into a theme's functions.php or load from a mu-plugin.
 *
 * @package FreeformFields
 */

add_action(
	'wpff/init',
	static function () {
		wpff_add_local_field_group(
			array(
				'key'      => 'group_demo',
				'title'    => 'Demo fields',
				'position' => 'normal',
				'fields'   => array(
					array(
						'key'          => 'field_demo_subtitle',
						'name'         => 'subtitle',
						'label'        => 'Subtitle',
						'type'         => 'text',
						'required'     => 1,
						'instructions' => 'Shown under the title.',
						'wrapper'      => array( 'width' => 50 ),
					),
					array(
						'key'     => 'field_demo_price',
						'name'    => 'price',
						'label'   => 'Price',
						'type'    => 'number',
						'min'     => 0,
						'append'  => '₸',
						'wrapper' => array( 'width' => 50 ),
					),
					array(
						'key'       => 'field_demo_summary',
						'name'      => 'summary',
						'label'     => 'Summary',
						'type'      => 'textarea',
						'rows'      => 3,
						'new_lines' => 'wpautop',
					),
					array(
						'key'     => 'field_demo_email',
						'name'    => 'contact_email',
						'label'   => 'Contact email',
						'type'    => 'email',
						'wrapper' => array( 'width' => 50 ),
					),
					array(
						'key'      => 'field_demo_topics',
						'name'     => 'topics',
						'label'    => 'Topics',
						'type'     => 'select',
						'multiple' => 1,
						'choices'  => array(
							'design'      => 'Design',
							'development' => 'Development',
							'marketing'   => 'Marketing',
							'seo'         => 'SEO',
							'content'     => 'Content',
							'analytics'   => 'Analytics',
							'security'    => 'Security',
							'performance' => 'Performance',
							'hosting'     => 'Hosting',
							'ecommerce'   => 'E-commerce',
						),
					),
					array(
						'key'     => 'field_demo_url',
						'name'    => 'website',
						'label'   => 'Website',
						'type'    => 'url',
						'wrapper' => array( 'width' => 50 ),
					),
					array(
						'key'           => 'field_demo_city',
						'name'          => 'city',
						'label'         => 'City',
						'type'          => 'select',
						'choices'       => "almaty : Almaty\nastana : Astana\nshymkent : Shymkent",
						'allow_null'    => 1,
						'return_format' => 'label',
						'wrapper'       => array( 'width' => 33 ),
					),
					array(
						'key'     => 'field_demo_amenities',
						'name'    => 'amenities',
						'label'   => 'Amenities',
						'type'    => 'checkbox',
						'choices' => array(
							'wifi'    => 'Wi-Fi',
							'parking' => 'Parking',
							'pool'    => 'Pool',
						),
						'wrapper' => array( 'width' => 33 ),
					),
					array(
						'key'     => 'field_demo_size',
						'name'    => 'size',
						'label'   => 'Size',
						'type'    => 'radio',
						'choices' => array(
							's' => 'Small',
							'm' => 'Medium',
							'l' => 'Large',
						),
						'layout'  => 'horizontal',
						'wrapper' => array( 'width' => 33 ),
					),
					array(
						'key'     => 'field_demo_featured',
						'name'    => 'featured',
						'label'   => 'Featured',
						'type'    => 'true_false',
						'ui'      => 1,
						'message' => 'Show on the home page',
					),
					array(
						'key'   => 'field_demo_cover',
						'name'  => 'cover',
						'label' => 'Cover image',
						'type'  => 'image',
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'post',
						),
					),
				),
			)
		);
	}
);

add_action(
	'wpff/init',
	static function () {
		// Stage 3 fields: tabs, conditions, group, relational, dates, color, media, editor.
		wpff_add_local_field_group(
			array(
				'key'      => 'group_demo_advanced',
				'title'    => 'Demo: advanced fields',
				'fields'   => array(
					array(
						'key'   => 'field_demo_tab_content',
						'label' => 'Content',
						'type'  => 'tab',
					),
					array(
						'key'     => 'field_demo_layout',
						'name'    => 'layout',
						'label'   => 'Layout',
						'type'    => 'button_group',
						'choices' => array(
							'simple' => 'Simple',
							'hero'   => 'With hero',
						),
					),
					array(
						'key'               => 'field_demo_hero',
						'name'              => 'hero',
						'label'             => 'Hero',
						'type'              => 'group',
						'conditional_logic' => array(
							array(
								array(
									'field'    => 'field_demo_layout',
									'operator' => '==',
									'value'    => 'hero',
								),
							),
						),
						'sub_fields'        => array(
							array(
								'key'      => 'field_demo_hero_title',
								'name'     => 'title',
								'label'    => 'Hero title',
								'type'     => 'text',
								'required' => 1,
							),
							array(
								'key'   => 'field_demo_hero_bg',
								'name'  => 'background',
								'label' => 'Background',
								'type'  => 'color_picker',
							),
						),
					),
					array(
						'key'   => 'field_demo_body',
						'name'  => 'body',
						'label' => 'Body',
						'type'  => 'wysiwyg',
					),
					array(
						'key'    => 'field_demo_location',
						'name'   => 'location',
						'label'  => 'Location',
						'type'   => 'google_map',
						'height' => 300,
					),
					array(
						'key'   => 'field_demo_tab_links',
						'label' => 'Links & dates',
						'type'  => 'tab',
					),
					array(
						'key'       => 'field_demo_related',
						'name'      => 'related',
						'label'     => 'Related pages',
						'type'      => 'relationship',
						'post_type' => array( 'page', 'post' ),
						'max'       => 3,
					),
					array(
						'key'   => 'field_demo_owner',
						'name'  => 'owner',
						'label' => 'Owner',
						'type'  => 'user',
					),
					array(
						'key'      => 'field_demo_topics',
						'name'     => 'topics',
						'label'    => 'Topics',
						'type'     => 'taxonomy',
						'taxonomy' => 'category',
					),
					array(
						'key'   => 'field_demo_cta',
						'name'  => 'cta',
						'label' => 'Call to action',
						'type'  => 'link',
					),
					array(
						'key'     => 'field_demo_starts',
						'name'    => 'starts',
						'label'   => 'Starts',
						'type'    => 'date_time_picker',
						'wrapper' => array( 'width' => 50 ),
					),
					array(
						'key'     => 'field_demo_rating',
						'name'    => 'rating',
						'label'   => 'Rating',
						'type'    => 'range',
						'max'     => 10,
						'wrapper' => array( 'width' => 50 ),
					),
					array(
						'key'   => 'field_demo_acc',
						'label' => 'Attachments',
						'type'  => 'accordion',
					),
					array(
						'key'        => 'field_demo_brochure',
						'name'       => 'brochure',
						'label'      => 'Brochure (PDF)',
						'type'       => 'file',
						'mime_types' => 'pdf',
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'post',
						),
					),
				),
			)
		);

		wpff_add_local_field_group(
			array(
				'key'      => 'group_demo_term',
				'title'    => 'Category extras',
				'fields'   => array(
					array(
						'key'   => 'field_demo_term_color',
						'name'  => 'category_color',
						'label' => 'Color',
						'type'  => 'color_picker',
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'taxonomy',
							'operator' => '==',
							'value'    => 'category',
						),
					),
				),
			)
		);

		wpff_add_local_field_group(
			array(
				'key'      => 'group_demo_user',
				'title'    => 'Profile extras',
				'fields'   => array(
					array(
						'key'   => 'field_demo_user_phone',
						'name'  => 'phone',
						'label' => 'Phone',
						'type'  => 'text',
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'user_form',
							'operator' => '==',
							'value'    => 'all',
						),
					),
				),
			)
		);
	}
);

add_action(
	'wpff/init',
	static function () {
		// Stage 4 fields: repeater (with a nested repeater), flexible content, gallery and clone.
		wpff_add_local_field_group(
			array(
				'key'      => 'group_demo_rows',
				'title'    => 'Demo: rows',
				'fields'   => array(
					array(
						'key'           => 'field_demo_team',
						'name'          => 'team',
						'label'         => 'Team',
						'type'          => 'repeater',
						'layout'        => 'table',
						'max'           => 12,
						'pagination'    => 1,
						'rows_per_page' => 3,
						'button_label'  => 'Add person',
						'collapsed'     => 'field_demo_team_name',
						'sub_fields'    => array(
							array(
								'key'      => 'field_demo_team_name',
								'name'     => 'name',
								'label'    => 'Name',
								'type'     => 'text',
								'required' => 1,
							),
							array(
								'key'     => 'field_demo_team_role',
								'name'    => 'role',
								'label'   => 'Role',
								'type'    => 'select',
								'choices' => array(
									'dev'    => 'Developer',
									'design' => 'Designer',
								),
							),
						),
					),
					array(
						'key'     => 'field_demo_sections',
						'name'    => 'sections',
						'label'   => 'Page sections',
						'type'    => 'flexible_content',
						'layouts' => array(
							array(
								'name'       => 'text',
								'label'      => 'Text block',
								'sub_fields' => array(
									array(
										'key'   => 'field_demo_sec_text',
										'name'  => 'text',
										'label' => 'Text',
										'type'  => 'textarea',
									),
								),
							),
							array(
								'name'       => 'faq',
								'label'      => 'FAQ',
								'sub_fields' => array(
									array(
										'key'        => 'field_demo_sec_items',
										'name'       => 'items',
										'label'      => 'Questions',
										'type'       => 'repeater',
										'layout'     => 'block',
										'sub_fields' => array(
											array(
												'key'   => 'field_demo_sec_q',
												'name'  => 'question',
												'label' => 'Question',
												'type'  => 'text',
											),
											array(
												'key'   => 'field_demo_sec_a',
												'name'  => 'answer',
												'label' => 'Answer',
												'type'  => 'textarea',
											),
										),
									),
								),
							),
						),
					),
					array(
						'key'   => 'field_demo_photos',
						'name'  => 'photos',
						'label' => 'Photos',
						'type'  => 'gallery',
					),
					array(
						'key'         => 'field_demo_contact_clone',
						'name'        => 'support',
						'label'       => 'Support contact',
						'type'        => 'clone',
						'clone'       => array( 'field_demo_email' ),
						'display'     => 'group',
						'prefix_name' => 0,
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'post',
						),
					),
				),
			)
		);
	}
);

add_action(
	'wpff/init',
	static function () {
		// Stage 5: an options page with a sub page, and fields on them.
		wpff_add_options_page(
			array(
				'page_title' => 'Theme Settings',
				'menu_slug'  => 'theme-settings',
				'icon_url'   => 'dashicons-art',
			)
		);
		wpff_add_options_sub_page(
			array(
				'page_title'  => 'Header',
				'menu_slug'   => 'theme-settings-header',
				'parent_slug' => 'theme-settings',
			)
		);
		wpff_add_options_sub_page(
			array(
				'page_title'  => 'Footer',
				'menu_slug'   => 'theme-settings-footer',
				'parent_slug' => 'theme-settings',
			)
		);

		wpff_add_local_field_group(
			array(
				'key'      => 'group_demo_options_header',
				'title'    => 'Header',
				'fields'   => array(
					array(
						'key'   => 'field_demo_opt_logo',
						'name'  => 'logo',
						'label' => 'Logo',
						'type'  => 'image',
					),
					array(
						'key'      => 'field_demo_opt_phone',
						'name'     => 'phone',
						'label'    => 'Phone',
						'type'     => 'text',
						'required' => 1,
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'options_page',
							'operator' => '==',
							'value'    => 'theme-settings-header',
						),
					),
				),
			)
		);

		wpff_add_local_field_group(
			array(
				'key'      => 'group_demo_options_footer',
				'title'    => 'Footer links',
				'fields'   => array(
					array(
						'key'        => 'field_demo_opt_links',
						'name'       => 'footer_links',
						'label'      => 'Links',
						'type'       => 'repeater',
						'sub_fields' => array(
							array(
								'key'   => 'field_demo_opt_link',
								'name'  => 'link',
								'label' => 'Link',
								'type'  => 'link',
							),
						),
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'options_page',
							'operator' => '==',
							'value'    => 'theme-settings-footer',
						),
					),
				),
			)
		);
	}
);

// Stage 6: blocks. One from block.json (ACF format), one from PHP.
add_action(
	'init',
	static function () {
		register_block_type( __DIR__ . '/blocks/testimonial' );
	}
);

add_action(
	'wpff/init',
	static function () {
		wpff_register_block_type(
			array(
				'name'            => 'cta',
				'title'           => 'Call to action',
				'icon'            => 'megaphone',
				'category'        => 'design',
				'render_template' => __DIR__ . '/blocks/cta.php',
				// Lets the template place editable inner blocks with <InnerBlocks />.
				'supports'        => array( 'jsx' => true ),
			)
		);

		wpff_add_local_field_group(
			array(
				'key'      => 'group_demo_block_testimonial',
				'title'    => 'Testimonial',
				'fields'   => array(
					array(
						'key'      => 'field_demo_tm_quote',
						'name'     => 'quote',
						'label'    => 'Quote',
						'type'     => 'textarea',
						'required' => 1,
					),
					array(
						'key'   => 'field_demo_tm_author',
						'name'  => 'author',
						'label' => 'Author',
						'type'  => 'text',
					),
					array(
						'key'   => 'field_demo_tm_photo',
						'name'  => 'photo',
						'label' => 'Photo',
						'type'  => 'image',
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'block',
							'operator' => '==',
							'value'    => 'acf/testimonial',
						),
					),
				),
			)
		);

		wpff_add_local_field_group(
			array(
				'key'      => 'group_demo_block_cta',
				'title'    => 'Call to action',
				'fields'   => array(
					array(
						'key'   => 'field_demo_cta_heading',
						'name'  => 'heading',
						'label' => 'Heading',
						'type'  => 'text',
					),
					array(
						'key'          => 'field_demo_cta_buttons',
						'name'         => 'buttons',
						'label'        => 'Buttons',
						'type'         => 'repeater',
						'layout'       => 'block',
						'max'          => 3,
						'button_label' => 'Add button',
						'sub_fields'   => array(
							array(
								'key'   => 'field_demo_cta_link',
								'name'  => 'link',
								'label' => 'Link',
								'type'  => 'link',
							),
						),
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'block',
							'operator' => '==',
							'value'    => 'wpff/cta',
						),
					),
				),
			)
		);
	}
);

// Front-end form: [wpff_demo_form] creates a draft post with a few fields (ACF's acf_form()).
add_shortcode(
	'wpff_demo_form',
	static function (): string {
		ob_start();
		acf_form(
			array(
				'id'           => 'demo-submit',
				'post_id'      => 'new_post',
				'new_post'     => array(
					'post_type'   => 'post',
					'post_status' => 'draft',
				),
				'post_title'   => true,
				'fields'       => array( 'field_demo_subtitle', 'field_demo_price', 'field_demo_email' ),
				'submit_value' => 'Send',
			)
		);
		return (string) ob_get_clean();
	}
);
