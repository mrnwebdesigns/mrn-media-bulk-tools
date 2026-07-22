<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Adds reusable controls to the Media Library list table.
 */
final class MRN_Media_Column_Controls {
	const USER_META_KEY = '_mrn_media_list_column_widths';
	const NONCE_ACTION = 'mrn_media_save_column_widths';
	const MIN_WIDTH = 60;
	const MAX_WIDTH = 800;

	/**
	 * Register list-table assets and persistence handling.
	 */
	public static function init() {
		add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
		add_action('wp_ajax_mrn_media_save_column_widths', array(__CLASS__, 'save_column_widths'));
	}

	/**
	 * Load controls only on the Media Library list view.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_assets($hook_suffix) {
		if ('upload.php' !== $hook_suffix || !self::is_list_mode()) {
			return;
		}

		$plugin_file = dirname(__DIR__) . '/mrn-media-bulk-tools.php';

		wp_enqueue_style(
			'mrn-media-column-controls',
			plugins_url('assets/css/media-column-controls.css', $plugin_file),
			array(),
			MRN_Media_Tools::VERSION
		);

		wp_enqueue_script(
			'mrn-media-column-controls',
			plugins_url('assets/js/media-column-controls.js', $plugin_file),
			array(),
			MRN_Media_Tools::VERSION,
			true
		);

		wp_localize_script(
			'mrn-media-column-controls',
			'MRNMediaColumnControls',
			array(
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce(self::NONCE_ACTION),
				'widths' => self::get_column_widths(),
				'minWidth' => self::MIN_WIDTH,
				'maxWidth' => self::MAX_WIDTH,
				'i18n' => array(
					'columns' => __('Columns', 'mrn-media-bulk-tools'),
					'resizeColumn' => __('Resize column', 'mrn-media-bulk-tools'),
					'resizeHelp' => __('Drag the shared boundary to resize the column on its left. Double-click or press Enter to fit that column.', 'mrn-media-bulk-tools'),
					'resizeTableEdgeHelp' => __('Drag to resize the last column and table width. Double-click or press Enter to fit the last column.', 'mrn-media-bulk-tools'),
					'horizontalScroll' => __('Media table horizontal scroll', 'mrn-media-bulk-tools'),
				),
			)
		);
	}

	/**
	 * Persist sanitized widths for the current user.
	 */
	public static function save_column_widths() {
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can('upload_files')) {
			wp_send_json_error(array('message' => __('You are not allowed to customize Media Library columns.', 'mrn-media-bulk-tools')), 403);
		}

		$widths_json = isset($_POST['widths']) ? sanitize_text_field(wp_unslash($_POST['widths'])) : '';
		$submitted_widths = json_decode($widths_json, true);

		if (!is_array($submitted_widths)) {
			wp_send_json_error(array('message' => __('Invalid column widths.', 'mrn-media-bulk-tools')), 400);
		}

		$column_widths = array();

		foreach (array_slice($submitted_widths, 0, 30, true) as $column_key => $width) {
			$column_key = sanitize_key($column_key);
			$width = absint($width);

			if ('' === $column_key || $width < self::MIN_WIDTH || $width > self::MAX_WIDTH) {
				continue;
			}

			$column_widths[$column_key] = $width;
		}

		update_user_meta(get_current_user_id(), self::USER_META_KEY, $column_widths);
		wp_send_json_success(array('widths' => $column_widths));
	}

	/**
	 * Determine whether the current Media Library request uses list mode.
	 *
	 * @return bool
	 */
	private static function is_list_mode() {
		if (isset($_GET['mode'])) {
			return 'grid' !== sanitize_key(wp_unslash($_GET['mode']));
		}

		$mode = get_user_option('media_library_mode');

		return 'grid' !== $mode;
	}

	/**
	 * Get valid saved column widths for the current user.
	 *
	 * @return array<string,int>
	 */
	private static function get_column_widths() {
		$saved_widths = get_user_meta(get_current_user_id(), self::USER_META_KEY, true);

		if (!is_array($saved_widths)) {
			return array();
		}

		$column_widths = array();

		foreach ($saved_widths as $column_key => $width) {
			$column_key = sanitize_key($column_key);
			$width = absint($width);

			if ('' !== $column_key && $width >= self::MIN_WIDTH && $width <= self::MAX_WIDTH) {
				$column_widths[$column_key] = $width;
			}
		}

		return $column_widths;
	}
}
