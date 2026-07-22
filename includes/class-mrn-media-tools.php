<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Coordinates the modules that make up MRN Media Tools.
 */
final class MRN_Media_Tools {
	const VERSION = '0.12.1';

	/**
	 * Initialize plugin modules.
	 */
	public static function init() {
		MRN_Media_Bulk_Tools::init();
		MRN_Media_Attachment_Actions::init();
		MRN_Media_Dimensions_Column::init();
		MRN_Media_Folders_Column::init();
		MRN_Media_File_Size_Column::init();
		MRN_Media_Filters::init();
		MRN_Media_Image_Sizes::init();
		MRN_Media_Column_Controls::init();
		MRN_Media_Usage_Index::init();
	}
}
