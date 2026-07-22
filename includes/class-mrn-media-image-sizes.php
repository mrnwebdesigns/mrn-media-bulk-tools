<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Lists generated image derivatives from the Media Library list table.
 */
final class MRN_Media_Image_Sizes {
	const COLUMN_KEY = 'mrn_media_image_sizes';
	const NONCE_ACTION = 'mrn_media_image_sizes_admin';

	/**
	 * Register image-size hooks.
	 */
	public static function init() {
		add_filter('manage_media_columns', array(__CLASS__, 'add_image_sizes_column'), 30);
		add_action('manage_media_custom_column', array(__CLASS__, 'render_image_sizes_column'), 10, 2);
		add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
		add_action('wp_ajax_mrn_media_image_sizes_get', array(__CLASS__, 'ajax_get_image_sizes'));
	}

	/**
	 * Add the image-sizes column before dimensions when possible.
	 *
	 * @param array $columns Existing Media Library columns.
	 * @return array
	 */
	public static function add_image_sizes_column($columns) {
		if (!is_array($columns) || isset($columns[self::COLUMN_KEY])) {
			return $columns;
		}

		$updated_columns = array();
		$column_added = false;

		foreach ($columns as $column_key => $column_label) {
			if ('mrn_media_dimensions' === $column_key) {
				$updated_columns[self::COLUMN_KEY] = __('Image Sizes', 'mrn-media-bulk-tools');
				$column_added = true;
			}

			$updated_columns[$column_key] = $column_label;
		}

		if (!$column_added) {
			$updated_columns[self::COLUMN_KEY] = __('Image Sizes', 'mrn-media-bulk-tools');
		}

		return $updated_columns;
	}

	/**
	 * Render a compact link to the generated image-size list.
	 *
	 * @param string $column_name Current column key.
	 * @param int    $attachment_id Attachment post ID.
	 */
	public static function render_image_sizes_column($column_name, $attachment_id) {
		if (self::COLUMN_KEY !== $column_name) {
			return;
		}

		if (!wp_attachment_is_image($attachment_id)) {
			echo '<span aria-hidden="true">&mdash;</span><span class="screen-reader-text">' . esc_html__('Not an image', 'mrn-media-bulk-tools') . '</span>';
			return;
		}

		$metadata = wp_get_attachment_metadata($attachment_id);
		$sizes = is_array($metadata) && isset($metadata['sizes']) && is_array($metadata['sizes']) ? $metadata['sizes'] : array();
		$count = count($sizes);

		if (!$count) {
			echo '<span class="description">' . esc_html__('No generated sizes', 'mrn-media-bulk-tools') . '</span>';
			return;
		}

		printf(
			'<button type="button" class="button-link mrn-media-image-sizes-link" data-attachment-id="%1$d" data-attachment-title="%2$s">%3$s</button>',
			absint($attachment_id),
			esc_attr(get_the_title($attachment_id)),
			esc_html(
				sprintf(
					/* translators: %d: number of generated image sizes. */
					_n('View %d generated size', 'View %d generated sizes', $count, 'mrn-media-bulk-tools'),
					$count
				)
			)
		);
	}

	/**
	 * Load the generated-size modal on the Media Library list view.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_assets($hook_suffix) {
		if ('upload.php' !== $hook_suffix || !self::is_list_mode()) {
			return;
		}

		$plugin_file = dirname(__DIR__) . '/mrn-media-bulk-tools.php';

		wp_enqueue_style(
			'mrn-media-image-sizes',
			plugins_url('assets/css/media-image-sizes.css', $plugin_file),
			array(),
			MRN_Media_Tools::VERSION
		);

		wp_enqueue_script(
			'mrn-media-image-sizes',
			plugins_url('assets/js/media-image-sizes.js', $plugin_file),
			array(),
			MRN_Media_Tools::VERSION,
			true
		);

		wp_localize_script(
			'mrn-media-image-sizes',
			'MRNMediaImageSizes',
			array(
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce(self::NONCE_ACTION),
				'i18n' => array(
					'close' => __('Close', 'mrn-media-bulk-tools'),
					'generatedSizes' => __('Generated Sizes', 'mrn-media-bulk-tools'),
					'loading' => __('Loading generated sizes…', 'mrn-media-bulk-tools'),
					'noSizes' => __('No generated image sizes were found.', 'mrn-media-bulk-tools'),
					'view' => __('View', 'mrn-media-bulk-tools'),
					'opensNewTab' => __('opens in a new tab', 'mrn-media-bulk-tools'),
				),
			)
		);
	}

	/**
	 * Return generated image-size details for one attachment.
	 */
	public static function ajax_get_image_sizes() {
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		$attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;

		if (!$attachment_id || 'attachment' !== get_post_type($attachment_id) || !current_user_can('upload_files')) {
			wp_send_json_error(array('message' => __('You are not allowed to inspect this attachment.', 'mrn-media-bulk-tools')), 403);
		}

		$metadata = wp_get_attachment_metadata($attachment_id);
		$metadata_sizes = is_array($metadata) && isset($metadata['sizes']) && is_array($metadata['sizes']) ? $metadata['sizes'] : array();
		$records = array();

		foreach ($metadata_sizes as $size_name => $size_data) {
			$image = image_downsize($attachment_id, $size_name);

			if (!is_array($size_data) || !is_array($image) || empty($image[0])) {
				continue;
			}

			$file_size = self::get_generated_file_size($attachment_id, isset($size_data['file']) ? $size_data['file'] : '');
			$records[] = array(
				'name' => sanitize_key($size_name),
				'label' => ucwords(str_replace(array('-', '_'), ' ', sanitize_key($size_name))),
				'width' => isset($size_data['width']) ? absint($size_data['width']) : absint($image[1]),
				'height' => isset($size_data['height']) ? absint($size_data['height']) : absint($image[2]),
				'fileSize' => false === $file_size ? '' : size_format($file_size, $file_size < KB_IN_BYTES ? 0 : 1),
				'url' => esc_url_raw($image[0]),
			);
		}

		usort(
			$records,
			static function ($first, $second) {
				return ($first['width'] * $first['height']) <=> ($second['width'] * $second['height']);
			}
		);

		wp_send_json_success(array('records' => $records));
	}

	/**
	 * Safely read one generated file's size from the uploads directory.
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $filename Generated filename from attachment metadata.
	 * @return int|false
	 */
	private static function get_generated_file_size($attachment_id, $filename) {
		if (!$filename || wp_basename($filename) !== $filename) {
			return false;
		}

		$original_path = get_attached_file($attachment_id);
		$uploads = wp_get_upload_dir();
		$uploads_path = isset($uploads['basedir']) ? realpath($uploads['basedir']) : false;
		$generated_path = is_string($original_path) ? realpath(trailingslashit(dirname($original_path)) . $filename) : false;

		if (false === $uploads_path || false === $generated_path) {
			return false;
		}

		$uploads_path = trailingslashit(wp_normalize_path($uploads_path));
		$generated_path = wp_normalize_path($generated_path);

		if (0 !== strpos($generated_path, $uploads_path) || !is_file($generated_path) || !is_readable($generated_path)) {
			return false;
		}

		$file_size = wp_filesize($generated_path);

		return false === $file_size ? false : absint($file_size);
	}

	/**
	 * Determine whether the current Media Library request is list mode.
	 *
	 * @return bool
	 */
	private static function is_list_mode() {
		if (isset($_GET['mode'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display preference.
			return 'grid' !== sanitize_key(wp_unslash($_GET['mode'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display preference.
		}

		return 'grid' !== get_user_option('media_library_mode', get_current_user_id());
	}
}
