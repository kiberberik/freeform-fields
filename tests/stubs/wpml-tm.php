<?php
/**
 * Minimal stand-in for WPML's TranslationManagement settings (wpml_load_core_tm()).
 *
 * @package FreeformFields
 */

// phpcs:disable

class WPFF_Test_WPML_TM {
	public $settings = array();
	public int $saved = 0;

	public function get_settings() {
		return $this->settings;
	}

	public function save_settings() {
		++$this->saved;
	}
}

function wpml_load_core_tm() {
	static $tm = null;
	if ( null === $tm ) {
		$tm = new WPFF_Test_WPML_TM();
	}
	return $tm;
}
