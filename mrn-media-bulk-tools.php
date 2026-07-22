<?php
/**
 * Plugin Name: MRN Media Tools
 * Description: Provides extensible Media Library utilities, including bulk metadata updates.
 * Version: 0.12.1
 * Author: MRN Web Designs
 */

if (!defined('ABSPATH')) {
	exit;
}

require_once plugin_dir_path(__FILE__) . 'includes/class-mrn-media-bulk-tools.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-mrn-media-attachment-actions.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-mrn-media-dimensions-column.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-mrn-media-folders-column.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-mrn-media-file-size-column.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-mrn-media-filters.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-mrn-media-image-sizes.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-mrn-media-column-controls.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-mrn-media-usage-index.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-mrn-media-tools.php';

MRN_Media_Tools::init();

register_activation_hook(__FILE__, array('MRN_Media_Usage_Index', 'activate'));
register_deactivation_hook(__FILE__, array('MRN_Media_Usage_Index', 'deactivate'));
