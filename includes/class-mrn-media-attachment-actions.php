<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Adjusts attachment actions shown in the Media Library list table.
 */
final class MRN_Media_Attachment_Actions {
	/**
	 * Register Media Library row-action hooks.
	 */
	public static function init() {
		add_filter('media_row_actions', array(__CLASS__, 'remove_attachment_view_action'), 100, 3);
		add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
	}

	/**
	 * Load the compact attachment action menu styles on the Media Library list.
	 *
	 * @param string $hook_suffix Current admin screen hook.
	 */
	public static function enqueue_assets($hook_suffix) {
		if ('upload.php' !== $hook_suffix) {
			return;
		}

		$plugin_file = dirname(__DIR__) . '/mrn-media-bulk-tools.php';

		wp_enqueue_style(
			'mrn-media-attachment-actions',
			plugins_url('assets/css/media-attachment-actions.css', $plugin_file),
			array(),
			'0.12.1'
		);
	}

	/**
	 * Remove the attachment permalink action while preserving direct file actions.
	 *
	 * Attachment pages are not part of the MRN media workflow. The core Copy URL
	 * action remains available for opening or copying the underlying media file.
	 *
	 * @param array   $actions  Media row actions.
	 * @param WP_Post $post     Attachment post.
	 * @param bool    $detached Whether the attachment has no parent.
	 * @return array
	 */
	public static function remove_attachment_view_action($actions, $post, $detached) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if (is_array($actions)) {
			unset($actions['view']);
			$actions = self::build_action_menu($actions);
		}

		return $actions;
	}

	/**
	 * Put all attachment row actions in one accessible native menu.
	 *
	 * @param array $actions Media row actions.
	 * @return array
	 */
	private static function build_action_menu($actions) {
		$regular_actions     = array();
		$destructive_actions = array();
		$destructive_keys    = array('trash', 'delete', 'delete_permanent', 'delete_permanently');

		foreach ($actions as $key => $action) {
			if (!is_string($action) || '' === trim($action)) {
				continue;
			}

			$action_markup = sprintf(
				'<span class="mrn-media-action mrn-media-action-%1$s">%2$s</span>',
				sanitize_html_class($key),
				$action
			);

			if (in_array($key, $destructive_keys, true)) {
				$destructive_actions[] = $action_markup;
			} else {
				$regular_actions[] = $action_markup;
			}
		}

		$menu_items = $regular_actions;

		if (!empty($destructive_actions)) {
			$menu_items[] = '<span class="mrn-media-actions-menu__separator" aria-hidden="true"></span>';
			$menu_items   = array_merge($menu_items, $destructive_actions);
		}

		if (empty($menu_items)) {
			return $actions;
		}

		return array(
			'mrn_media_actions' => sprintf(
				'<details class="mrn-media-actions-menu"><summary>%1$s</summary><span class="mrn-media-actions-menu__content">%2$s</span></details>',
				esc_html__('Actions', 'mrn-media-bulk-tools'),
				implode('', $menu_items)
			),
		);
	}
}
