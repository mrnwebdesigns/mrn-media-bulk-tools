<?php
/**
 * Plugin Name: Media Bulk Tools
 * Description: Adds bulk metadata update tools to the WordPress Media Library list view.
 * Version: 0.1.0
 * Author: MRN
 */

if (!defined('ABSPATH')) {
	exit;
}

require_once plugin_dir_path(__FILE__) . 'includes/class-mrn-media-bulk-tools.php';

MRN_Media_Bulk_Tools::init();
