<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Displays HappyFiles folder assignments in the Media Library list table.
 */
final class MRN_Media_Folders_Column {
	const COLUMN_KEY = 'mrn_media_folders';
	const HAPPYFILES_TAXONOMY = 'happyfiles_category';

	/**
	 * Register Media Library list-table hooks.
	 */
	public static function init() {
		add_filter('manage_media_columns', array(__CLASS__, 'add_folders_column'), 30);
		add_action('manage_media_custom_column', array(__CLASS__, 'render_folders_column'), 10, 2);
	}

	/**
	 * Add the folders column before usage details when HappyFiles is available.
	 *
	 * @param array $columns Existing Media Library columns.
	 * @return array
	 */
	public static function add_folders_column($columns) {
		if (!is_array($columns) || isset($columns[self::COLUMN_KEY]) || !taxonomy_exists(self::HAPPYFILES_TAXONOMY)) {
			return $columns;
		}

		$updated_columns = array();
		$column_added = false;

		foreach ($columns as $column_key => $column_label) {
			if ('mrn_media_usage' === $column_key) {
				$updated_columns[self::COLUMN_KEY] = __('Folders', 'mrn-media-bulk-tools');
				$column_added = true;
			}

			$updated_columns[$column_key] = $column_label;
		}

		if (!$column_added) {
			$updated_columns[self::COLUMN_KEY] = __('Folders', 'mrn-media-bulk-tools');
		}

		return $updated_columns;
	}

	/**
	 * Render all HappyFiles folder paths assigned to an attachment.
	 *
	 * @param string $column_name Current column key.
	 * @param int    $attachment_id Attachment post ID.
	 */
	public static function render_folders_column($column_name, $attachment_id) {
		if (self::COLUMN_KEY !== $column_name) {
			return;
		}

		$terms = get_the_terms(absint($attachment_id), self::HAPPYFILES_TAXONOMY);

		if (is_wp_error($terms) || empty($terms)) {
			echo '<span class="description">' . esc_html__('Uncategorized', 'mrn-media-bulk-tools') . '</span>';
			return;
		}

		$folder_paths = array();

		foreach ($terms as $term) {
			$folder_paths[] = array(
				'name' => self::get_folder_path($term),
				'url' => add_query_arg(
					self::HAPPYFILES_TAXONOMY,
					absint($term->term_id),
					admin_url('upload.php')
				),
			);
		}

		usort($folder_paths, array(__CLASS__, 'sort_folder_paths'));

		echo '<span class="mrn-media-folder-list">';
		foreach ($folder_paths as $folder_path) {
			echo '<span class="mrn-media-folder"><a href="' . esc_url($folder_path['url']) . '">' . esc_html($folder_path['name']) . '</a></span>';
		}
		echo '</span>';
	}

	/**
	 * Sort folder path records naturally by their display names.
	 *
	 * @param array $first First folder path record.
	 * @param array $second Second folder path record.
	 * @return int
	 */
	private static function sort_folder_paths($first, $second) {
		return strnatcasecmp($first['name'], $second['name']);
	}

	/**
	 * Build a human-readable path for a hierarchical HappyFiles term.
	 *
	 * @param WP_Term $term Folder term.
	 * @return string
	 */
	private static function get_folder_path($term) {
		$path_parts = array();
		$ancestor_ids = array_reverse(get_ancestors($term->term_id, self::HAPPYFILES_TAXONOMY, 'taxonomy'));

		foreach ($ancestor_ids as $ancestor_id) {
			$ancestor = get_term($ancestor_id, self::HAPPYFILES_TAXONOMY);

			if ($ancestor instanceof WP_Term) {
				$path_parts[] = $ancestor->name;
			}
		}

		$path_parts[] = $term->name;

		return implode(' › ', $path_parts);
	}
}
