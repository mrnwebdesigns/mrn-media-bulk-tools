<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Adds richer filters to the Media Library list view.
 */
final class MRN_Media_Filters {
	const TYPE_QUERY_VAR = 'mrn_media_type';
	const SIZE_QUERY_VAR = 'mrn_min_size';
	const USAGE_QUERY_VAR = 'mrn_usage';

	/**
	 * Register Media Library filter hooks.
	 */
	public static function init() {
		add_action('restrict_manage_posts', array(__CLASS__, 'render_filters'), 20, 2);
		add_action('pre_get_posts', array(__CLASS__, 'apply_filters'));
		add_filter('posts_where', array(__CLASS__, 'filter_where'), 20, 2);
	}

	/**
	 * Render filter controls above the list table.
	 *
	 * @param string $post_type Current post type.
	 * @param string $which Filter navigation position.
	 */
	public static function render_filters($post_type, $which) {
		if ('attachment' !== $post_type || !in_array($which, array('bar', 'top'), true)) {
			return;
		}

		$selected_type = self::get_request_value(self::TYPE_QUERY_VAR);
		$selected_size = self::get_request_value(self::SIZE_QUERY_VAR);
		$selected_usage = self::get_request_value(self::USAGE_QUERY_VAR);

		echo '<label class="screen-reader-text" for="mrn-media-type">' . esc_html__('Filter by file type', 'mrn-media-bulk-tools') . '</label>';
		echo '<select id="mrn-media-type" name="' . esc_attr(self::TYPE_QUERY_VAR) . '">';
		echo '<option value="">' . esc_html__('All file types', 'mrn-media-bulk-tools') . '</option>';
		foreach (self::get_available_mime_types() as $mime_type) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr($mime_type),
				selected($selected_type, $mime_type, false),
				esc_html(self::get_mime_type_label($mime_type))
			);
		}
		echo '</select>';

		echo '<label class="screen-reader-text" for="mrn-media-min-size">' . esc_html__('Filter by minimum file size', 'mrn-media-bulk-tools') . '</label>';
		echo '<select id="mrn-media-min-size" name="' . esc_attr(self::SIZE_QUERY_VAR) . '">';
		echo '<option value="">' . esc_html__('Any file size', 'mrn-media-bulk-tools') . '</option>';
		foreach (self::get_size_options() as $bytes => $label) {
			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				absint($bytes),
				selected($selected_size, (string) $bytes, false),
				esc_html(sprintf(__('Larger than %s', 'mrn-media-bulk-tools'), $label))
			);
		}
		echo '</select>';

		echo '<label class="screen-reader-text" for="mrn-media-usage-filter">' . esc_html__('Filter by detected usage', 'mrn-media-bulk-tools') . '</label>';
		echo '<select id="mrn-media-usage-filter" name="' . esc_attr(self::USAGE_QUERY_VAR) . '">';
		self::render_option('', __('Any usage', 'mrn-media-bulk-tools'), $selected_usage);
		self::render_option('used', __('Used at least once', 'mrn-media-bulk-tools'), $selected_usage);
		self::render_option('unused', __('No detected uses', 'mrn-media-bulk-tools'), $selected_usage);
		self::render_option('used_2', __('Used in 2+ places', 'mrn-media-bulk-tools'), $selected_usage);
		self::render_option('used_5', __('Used in 5+ places', 'mrn-media-bulk-tools'), $selected_usage);
		echo '</select>';
	}

	/**
	 * Apply validated filter values to the main attachment query.
	 *
	 * @param WP_Query $query Current query.
	 */
	public static function apply_filters($query) {
		if (!is_admin() || !$query->is_main_query() || 'attachment' !== $query->get('post_type')) {
			return;
		}

		$mime_type = sanitize_mime_type(self::get_request_value(self::TYPE_QUERY_VAR));
		if ($mime_type && in_array($mime_type, self::get_available_mime_types(), true)) {
			$query->set('post_mime_type', $mime_type);
		}

		$minimum_size = absint(self::get_request_value(self::SIZE_QUERY_VAR));
		if (isset(self::get_size_options()[$minimum_size])) {
			MRN_Media_File_Size_Column::index_missing_file_sizes();
			$query->set(self::SIZE_QUERY_VAR, $minimum_size);
		}

		$usage = self::get_request_value(self::USAGE_QUERY_VAR);
		if (in_array($usage, array('used', 'unused', 'used_2', 'used_5'), true)) {
			$query->set(self::USAGE_QUERY_VAR, $usage);
		}
	}

	/**
	 * Add indexed size and usage constraints to the attachment query.
	 *
	 * @param string   $where SQL WHERE clause.
	 * @param WP_Query $query Current query.
	 * @return string
	 */
	public static function filter_where($where, $query) {
		if (!is_admin() || !$query->is_main_query() || 'attachment' !== $query->get('post_type')) {
			return $where;
		}

		global $wpdb;

		$minimum_size = absint($query->get(self::SIZE_QUERY_VAR));
		if ($minimum_size) {
			$where .= $wpdb->prepare(
				" AND EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} AS mrn_size_meta
					WHERE mrn_size_meta.post_id = {$wpdb->posts}.ID
						AND mrn_size_meta.meta_key = %s
						AND mrn_size_meta.meta_value <> '-1'
						AND CAST(mrn_size_meta.meta_value AS UNSIGNED) > %d
				)",
				MRN_Media_File_Size_Column::META_KEY,
				$minimum_size
			);
		}

		$usage = $query->get(self::USAGE_QUERY_VAR);
		if (!$usage) {
			return $where;
		}

		$usage_table = $wpdb->prefix . 'mrn_media_usage';

		if ('unused' === $usage) {
			$where .= " AND NOT EXISTS (SELECT 1 FROM {$usage_table} AS mrn_usage WHERE mrn_usage.attachment_id = {$wpdb->posts}.ID)";
			return $where;
		}

		$minimum_uses = 'used_5' === $usage ? 5 : ('used_2' === $usage ? 2 : 1);
		$where .= $wpdb->prepare(
			// The usage table name is generated internally from the WordPress database prefix.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			" AND {$wpdb->posts}.ID IN (SELECT mrn_usage.attachment_id FROM {$usage_table} AS mrn_usage
				GROUP BY mrn_usage.attachment_id HAVING COUNT(*) >= %d
			)",
			$minimum_uses
		);

		return $where;
	}

	/**
	 * Get exact MIME types currently present in the Media Library.
	 *
	 * @return string[]
	 */
	private static function get_available_mime_types() {
		global $wpdb;

		static $mime_types = null;

		if (null === $mime_types) {
			$mime_types = $wpdb->get_col(
				"SELECT DISTINCT post_mime_type FROM {$wpdb->posts}
				WHERE post_type = 'attachment' AND post_mime_type <> ''
				ORDER BY post_mime_type ASC"
			);
		}

		return $mime_types;
	}

	/**
	 * Get a concise label for an exact MIME type.
	 *
	 * @param string $mime_type MIME type.
	 * @return string
	 */
	private static function get_mime_type_label($mime_type) {
		$parts = explode('/', $mime_type, 2);
		$subtype = isset($parts[1]) ? $parts[1] : $mime_type;
		$subtype = str_replace(array('x-', '+xml'), '', $subtype);

		return sprintf('%1$s (%2$s)', strtoupper($subtype), $mime_type);
	}

	/**
	 * Get the supported file-size thresholds.
	 *
	 * @return array<int,string>
	 */
	private static function get_size_options() {
		return array(
			100 * KB_IN_BYTES => __('100 KB', 'mrn-media-bulk-tools'),
			500 * KB_IN_BYTES => __('500 KB', 'mrn-media-bulk-tools'),
			MB_IN_BYTES => __('1 MB', 'mrn-media-bulk-tools'),
			2 * MB_IN_BYTES => __('2 MB', 'mrn-media-bulk-tools'),
			5 * MB_IN_BYTES => __('5 MB', 'mrn-media-bulk-tools'),
			10 * MB_IN_BYTES => __('10 MB', 'mrn-media-bulk-tools'),
		);
	}

	/**
	 * Render one select option.
	 *
	 * @param string $value Option value.
	 * @param string $label Option label.
	 * @param string $selected_value Current value.
	 */
	private static function render_option($value, $label, $selected_value) {
		printf(
			'<option value="%1$s"%2$s>%3$s</option>',
			esc_attr($value),
			selected($selected_value, $value, false),
			esc_html($label)
		);
	}

	/**
	 * Read and sanitize one filter value from the request.
	 *
	 * @param string $key Request key.
	 * @return string
	 */
	private static function get_request_value($key) {
		return isset($_GET[$key]) ? sanitize_text_field(wp_unslash($_GET[$key])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list-table filters do not change state.
	}
}
