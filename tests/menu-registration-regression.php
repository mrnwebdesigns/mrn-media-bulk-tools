<?php
/**
 * Focused regression test for permanent Media submenu registration.
 *
 * Run: php tests/menu-registration-regression.php
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['mrn_test_actions']  = array();
$GLOBALS['mrn_test_filters']  = array();
$GLOBALS['mrn_test_submenus'] = array();

function is_admin() {
	return true;
}

function add_action($hook_name, $callback, $priority = 10, $accepted_args = 1) {
	$GLOBALS['mrn_test_actions'][$hook_name][] = array($callback, $priority, $accepted_args);
}

function add_filter($hook_name, $callback, $priority = 10, $accepted_args = 1) {
	$GLOBALS['mrn_test_filters'][$hook_name][] = array($callback, $priority, $accepted_args);
}

function __($text, $text_domain = 'default') {
	return $text;
}

function add_submenu_page($parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback) {
	$GLOBALS['mrn_test_submenus'][] = array(
		'parent_slug' => $parent_slug,
		'page_title'  => $page_title,
		'menu_title'  => $menu_title,
		'capability'  => $capability,
		'menu_slug'   => $menu_slug,
		'callback'    => $callback,
	);

	return 'media_page_' . $menu_slug;
}

function mrn_test_assert($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

require_once dirname(__DIR__) . '/includes/class-mrn-media-bulk-tools.php';

MRN_Media_Bulk_Tools::init();

mrn_test_assert(isset($GLOBALS['mrn_test_actions']['admin_menu']), 'Admin menu registration hook is missing.');
mrn_test_assert(!isset($GLOBALS['mrn_test_actions']['admin_head']), 'The submenu must not be removed from admin_head.');
mrn_test_assert(isset($GLOBALS['mrn_test_filters']['bulk_actions-upload']), 'Media bulk actions must remain registered.');
mrn_test_assert(isset($GLOBALS['mrn_test_filters']['handle_bulk_actions-upload']), 'The media bulk-action handler must remain registered.');

$menu_callback = $GLOBALS['mrn_test_actions']['admin_menu'][0][0];
call_user_func($menu_callback);

mrn_test_assert(1 === count($GLOBALS['mrn_test_submenus']), 'Exactly one submenu should be registered.');

$submenu = $GLOBALS['mrn_test_submenus'][0];
mrn_test_assert('upload.php' === $submenu['parent_slug'], 'The submenu must remain under Media.');
mrn_test_assert('Bulk Media Update' === $submenu['page_title'], 'The page title changed unexpectedly.');
mrn_test_assert('Bulk Media Update' === $submenu['menu_title'], 'The visible menu title changed unexpectedly.');
mrn_test_assert('upload_files' === $submenu['capability'], 'The upload_files capability must be preserved.');
mrn_test_assert('mrn-media-bulk-tools' === $submenu['menu_slug'], 'The direct URL slug must be preserved.');
mrn_test_assert(
	array('MRN_Media_Bulk_Tools', 'render_media_bulk_tools_page') === $submenu['callback'],
	'The standalone editor callback must be preserved.'
);
mrn_test_assert(
	'upload.php?page=mrn-media-bulk-tools' === $submenu['parent_slug'] . '?page=' . $submenu['menu_slug'],
	'The direct admin URL must be preserved.'
);

fwrite(STDOUT, "PASS: Bulk Media Update remains a visible Media submenu with its existing contract.\n");
