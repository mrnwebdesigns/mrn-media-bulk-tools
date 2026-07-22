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
		}

		return $actions;
	}
}
