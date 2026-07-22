<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Adds attachment file sizes to the Media Library list table.
 */
final class MRN_Media_File_Size_Column {
	const COLUMN_KEY = 'mrn_media_file_size';
	const META_KEY = '_mrn_media_file_size';

	/**
	 * Register Media Library list-table hooks.
	 */
	public static function init() {
		add_filter('manage_media_columns', array(__CLASS__, 'add_file_size_column'), 30);
		add_action('manage_media_custom_column', array(__CLASS__, 'render_file_size_column'), 10, 2);
		add_action('add_attachment', array(__CLASS__, 'index_attachment_file_size'));
		add_filter('wp_update_attachment_metadata', array(__CLASS__, 'index_updated_attachment_file_size'), 10, 2);
	}

	/**
	 * Add the file-size column before dimensions when possible.
	 *
	 * @param array $columns Existing Media Library columns.
	 * @return array
	 */
	public static function add_file_size_column($columns) {
		if (!is_array($columns) || isset($columns[self::COLUMN_KEY])) {
			return $columns;
		}

		$updated_columns = array();
		$column_added = false;

		foreach ($columns as $column_key => $column_label) {
			if ('mrn_media_dimensions' === $column_key) {
				$updated_columns[self::COLUMN_KEY] = __('File Size', 'mrn-media-bulk-tools');
				$column_added = true;
			}

			$updated_columns[$column_key] = $column_label;
		}

		if (!$column_added) {
			$updated_columns[self::COLUMN_KEY] = __('File Size', 'mrn-media-bulk-tools');
		}

		return $updated_columns;
	}

	/**
	 * Render a human-readable attachment file size.
	 *
	 * @param string $column_name Current column key.
	 * @param int    $attachment_id Attachment post ID.
	 */
	public static function render_file_size_column($column_name, $attachment_id) {
		if (self::COLUMN_KEY !== $column_name) {
			return;
		}

		$file_size = self::get_file_size($attachment_id);

		if (false === $file_size) {
			echo '<span aria-hidden="true">&mdash;</span><span class="screen-reader-text">' . esc_html__('File size unavailable', 'mrn-media-bulk-tools') . '</span>';
			return;
		}

		self::save_file_size($attachment_id, $file_size);

		$decimals = $file_size < KB_IN_BYTES ? 0 : 1;

		echo esc_html(size_format($file_size, $decimals));
	}

	/**
	 * Get the file size from attachment metadata or a safe uploads fallback.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return int|false
	 */
	public static function get_file_size($attachment_id) {
		$attachment_id = absint($attachment_id);

		if (!$attachment_id || 'attachment' !== get_post_type($attachment_id)) {
			return false;
		}

		$metadata = wp_get_attachment_metadata($attachment_id);

		if (is_array($metadata) && isset($metadata['filesize']) && is_numeric($metadata['filesize'])) {
			return absint($metadata['filesize']);
		}

		$file_path = get_attached_file($attachment_id);
		$uploads = wp_get_upload_dir();
		$uploads_path = isset($uploads['basedir']) ? realpath($uploads['basedir']) : false;
		$resolved_path = is_string($file_path) ? realpath($file_path) : false;

		if (false === $uploads_path || false === $resolved_path) {
			return false;
		}

		$uploads_path = trailingslashit(wp_normalize_path($uploads_path));
		$resolved_path = wp_normalize_path($resolved_path);

		if (0 !== strpos($resolved_path, $uploads_path) || !is_file($resolved_path) || !is_readable($resolved_path)) {
			return false;
		}

		$file_size = wp_filesize($resolved_path);

		return false === $file_size ? false : absint($file_size);
	}

	/**
	 * Save an attachment's file size for filtering.
	 *
	 * @param int $attachment_id Attachment post ID.
	 */
	public static function index_attachment_file_size($attachment_id) {
		$file_size = self::get_file_size($attachment_id);
		self::save_file_size($attachment_id, $file_size);
	}

	/**
	 * Refresh the size index after WordPress updates attachment metadata.
	 *
	 * @param array $metadata Attachment metadata.
	 * @param int   $attachment_id Attachment post ID.
	 * @return array
	 */
	public static function index_updated_attachment_file_size($metadata, $attachment_id) {
		if (is_array($metadata) && isset($metadata['filesize']) && is_numeric($metadata['filesize'])) {
			self::save_file_size($attachment_id, absint($metadata['filesize']));
		} else {
			self::index_attachment_file_size($attachment_id);
		}

		return $metadata;
	}

	/**
	 * Populate missing file-size index values before a size filter runs.
	 */
	public static function index_missing_file_sizes() {
		global $wpdb;

		$attachment_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT posts.ID
				FROM {$wpdb->posts} AS posts
				LEFT JOIN {$wpdb->postmeta} AS size_meta
					ON posts.ID = size_meta.post_id AND size_meta.meta_key = %s
				WHERE posts.post_type = 'attachment' AND size_meta.meta_id IS NULL",
				self::META_KEY
			)
		);

		foreach ($attachment_ids as $attachment_id) {
			self::index_attachment_file_size($attachment_id);
		}
	}

	/**
	 * Store a byte count, using -1 to avoid repeatedly checking unavailable files.
	 *
	 * @param int       $attachment_id Attachment post ID.
	 * @param int|false $file_size File size in bytes or false when unavailable.
	 */
	private static function save_file_size($attachment_id, $file_size) {
		$stored_size = false === $file_size ? -1 : absint($file_size);
		update_post_meta(absint($attachment_id), self::META_KEY, $stored_size);
	}
}
