<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Adds image dimensions to the Media Library list table.
 */
final class MRN_Media_Dimensions_Column {
	const COLUMN_KEY = 'mrn_media_dimensions';

	/**
	 * Register Media Library list-table hooks.
	 */
	public static function init() {
		add_filter('manage_media_columns', array(__CLASS__, 'add_dimensions_column'));
		add_action('manage_media_custom_column', array(__CLASS__, 'render_dimensions_column'), 10, 2);
	}

	/**
	 * Add the dimensions column before the date column when possible.
	 *
	 * @param array $columns Existing Media Library columns.
	 * @return array
	 */
	public static function add_dimensions_column($columns) {
		if (!is_array($columns) || isset($columns[self::COLUMN_KEY])) {
			return $columns;
		}

		$updated_columns = array();
		$column_added = false;

		foreach ($columns as $column_key => $column_label) {
			if ('date' === $column_key) {
				$updated_columns[self::COLUMN_KEY] = __('Dimensions', 'mrn-media-bulk-tools');
				$column_added = true;
			}

			$updated_columns[$column_key] = $column_label;
		}

		if (!$column_added) {
			$updated_columns[self::COLUMN_KEY] = __('Dimensions', 'mrn-media-bulk-tools');
		}

		return $updated_columns;
	}

	/**
	 * Render dimensions from WordPress attachment metadata.
	 *
	 * @param string $column_name Current column key.
	 * @param int    $attachment_id Attachment post ID.
	 */
	public static function render_dimensions_column($column_name, $attachment_id) {
		if (self::COLUMN_KEY !== $column_name) {
			return;
		}

		$dimensions = self::get_image_dimensions($attachment_id);

		if (empty($dimensions)) {
			echo '<span aria-hidden="true">&mdash;</span><span class="screen-reader-text">' . esc_html__('Image dimensions unavailable', 'mrn-media-bulk-tools') . '</span>';
			return;
		}

		echo esc_html(
			sprintf(
				/* translators: 1: image width in pixels, 2: image height in pixels. */
				__('%1$s × %2$s px', 'mrn-media-bulk-tools'),
				number_format_i18n($dimensions['width']),
				number_format_i18n($dimensions['height'])
			)
		);
	}

	/**
	 * Read dimensions from attachment metadata or an SVG header fallback.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return array{width:int,height:int}|array{}
	 */
	private static function get_image_dimensions($attachment_id) {
		$attachment_id = absint($attachment_id);

		if (!$attachment_id || !wp_attachment_is_image($attachment_id)) {
			return array();
		}

		$metadata = wp_get_attachment_metadata($attachment_id);
		$width = is_array($metadata) && isset($metadata['width']) ? absint($metadata['width']) : 0;
		$height = is_array($metadata) && isset($metadata['height']) ? absint($metadata['height']) : 0;

		if ($width && $height) {
			return array(
				'width' => $width,
				'height' => $height,
			);
		}

		return self::get_svg_dimensions($attachment_id);
	}

	/**
	 * Read SVG dimensions from a small, uploads-constrained file header.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return array{width:int,height:int}|array{}
	 */
	private static function get_svg_dimensions($attachment_id) {
		if ('image/svg+xml' !== get_post_mime_type($attachment_id)) {
			return array();
		}

		$file_path = get_attached_file($attachment_id);
		$uploads = wp_get_upload_dir();
		$uploads_path = isset($uploads['basedir']) ? realpath($uploads['basedir']) : false;
		$resolved_path = is_string($file_path) ? realpath($file_path) : false;

		if (false === $uploads_path || false === $resolved_path) {
			return array();
		}

		$uploads_path = trailingslashit(wp_normalize_path($uploads_path));
		$resolved_path = wp_normalize_path($resolved_path);

		if (0 !== strpos($resolved_path, $uploads_path) || !is_readable($resolved_path)) {
			return array();
		}

		// A bounded header read avoids loading large or malformed SVG files.
		$svg_header = file_get_contents($resolved_path, false, null, 0, 65536); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if (!is_string($svg_header) || !preg_match('/<svg\b[^>]*>/i', $svg_header, $svg_tag_match)) {
			return array();
		}

		$svg_tag = $svg_tag_match[0];
		$width = self::get_svg_numeric_attribute($svg_tag, 'width');
		$height = self::get_svg_numeric_attribute($svg_tag, 'height');

		if ($width && $height) {
			return array(
				'width' => $width,
				'height' => $height,
			);
		}

		if (!preg_match('/\bviewBox\s*=\s*(["\'])\s*([^"\']+)\s*\1/i', $svg_tag, $viewbox_match)) {
			return array();
		}

		$viewbox_values = preg_split('/[\s,]+/', trim($viewbox_match[2]));

		if (!is_array($viewbox_values) || 4 !== count($viewbox_values) || !is_numeric($viewbox_values[2]) || !is_numeric($viewbox_values[3])) {
			return array();
		}

		$width = absint(round((float) $viewbox_values[2]));
		$height = absint(round((float) $viewbox_values[3]));

		if (!$width || !$height) {
			return array();
		}

		return array(
			'width' => $width,
			'height' => $height,
		);
	}

	/**
	 * Extract a positive pixel dimension from an SVG element attribute.
	 *
	 * @param string $svg_tag SVG opening element.
	 * @param string $attribute Attribute name.
	 * @return int
	 */
	private static function get_svg_numeric_attribute($svg_tag, $attribute) {
		$pattern = '/\b' . preg_quote($attribute, '/') . '\s*=\s*(["\'])\s*([0-9]+(?:\.[0-9]+)?)\s*(?:px)?\s*\1/i';

		if (!preg_match($pattern, $svg_tag, $attribute_match)) {
			return 0;
		}

		return absint(round((float) $attribute_match[2]));
	}
}
