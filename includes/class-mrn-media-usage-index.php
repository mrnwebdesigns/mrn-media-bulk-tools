<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Builds and serves a searchable index of detected attachment usage.
 */
final class MRN_Media_Usage_Index {
	const DB_VERSION = '1';
	const DB_VERSION_OPTION = 'mrn_media_usage_db_version';
	const SCAN_STATE_OPTION = 'mrn_media_usage_scan_state';
	const LAST_SCAN_OPTION = 'mrn_media_usage_last_scan';
	const SOURCE_IDS_META_KEY = '_mrn_media_usage_attachment_ids';
	const DAILY_CRON_HOOK = 'mrn_media_usage_daily_scan';
	const PROCESS_CRON_HOOK = 'mrn_media_usage_process_scan';
	const SCAN_LOCK_TRANSIENT = 'mrn_media_usage_scan_lock';
	const NONCE_ACTION = 'mrn_media_usage_admin';
	const BATCH_SIZE = 25;

	/**
	 * Register usage-index hooks.
	 */
	public static function init() {
		add_action('admin_init', array(__CLASS__, 'ensure_ready'));
		add_filter('manage_media_columns', array(__CLASS__, 'add_usage_column'), 20);
		add_action('manage_media_custom_column', array(__CLASS__, 'render_usage_column'), 10, 2);
		add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
		add_action('wp_ajax_mrn_media_usage_start_scan', array(__CLASS__, 'ajax_start_scan'));
		add_action('wp_ajax_mrn_media_usage_scan_batch', array(__CLASS__, 'ajax_scan_batch'));
		add_action('wp_ajax_mrn_media_usage_get', array(__CLASS__, 'ajax_get_usage'));
		add_action(self::DAILY_CRON_HOOK, array(__CLASS__, 'run_daily_scan'));
		add_action(self::PROCESS_CRON_HOOK, array(__CLASS__, 'run_scheduled_batch'));
		add_action('save_post', array(__CLASS__, 'update_saved_post_usage'), 20, 3);
		add_action('trashed_post', array(__CLASS__, 'remove_post_usage'));
		add_action('before_delete_post', array(__CLASS__, 'remove_post_usage'));
	}

	/**
	 * Install storage and schedule refreshes when the plugin activates.
	 */
	public static function activate() {
		self::install_schema();
		self::ensure_schedule();
	}

	/**
	 * Remove scheduled jobs while preserving the usage index.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook(self::DAILY_CRON_HOOK);
		wp_clear_scheduled_hook(self::PROCESS_CRON_HOOK);
		delete_transient(self::SCAN_LOCK_TRANSIENT);
	}

	/**
	 * Keep upgraded active installs ready without requiring reactivation.
	 */
	public static function ensure_ready() {
		if (self::DB_VERSION !== get_option(self::DB_VERSION_OPTION)) {
			self::install_schema();
		}

		self::ensure_schedule();
	}

	/**
	 * Add the usage column after Uploaded to when possible.
	 *
	 * @param array $columns Existing Media Library columns.
	 * @return array
	 */
	public static function add_usage_column($columns) {
		if (!is_array($columns) || isset($columns['mrn_media_usage'])) {
			return $columns;
		}

		$updated_columns = array();
		$column_added = false;

		foreach ($columns as $column_key => $column_label) {
			$updated_columns[$column_key] = $column_label;

			if ('parent' === $column_key) {
				$updated_columns['mrn_media_usage'] = __('Used In', 'mrn-media-bulk-tools');
				$column_added = true;
			}
		}

		if (!$column_added) {
			$updated_columns['mrn_media_usage'] = __('Used In', 'mrn-media-bulk-tools');
		}

		return $updated_columns;
	}

	/**
	 * Render a lightweight count linked to the usage-detail modal.
	 *
	 * @param string $column_name Current column key.
	 * @param int    $attachment_id Attachment post ID.
	 */
	public static function render_usage_column($column_name, $attachment_id) {
		if ('mrn_media_usage' !== $column_name) {
			return;
		}

		$counts = self::get_current_page_usage_counts();
		$count = isset($counts[$attachment_id]) ? absint($counts[$attachment_id]) : 0;

		if ($count > 0 && current_user_can('edit_post', $attachment_id)) {
			printf(
				'<button type="button" class="button-link mrn-media-usage-link" data-attachment-id="%1$d" data-attachment-title="%2$s">%3$s</button>',
				absint($attachment_id),
				esc_attr(get_the_title($attachment_id)),
				esc_html(
					sprintf(
						/* translators: %d: number of content items using an attachment. */
						_n('Used in %d place', 'Used in %d places', $count, 'mrn-media-bulk-tools'),
						$count
					)
				)
			);
			return;
		}

		$scan_state = self::get_scan_state();
		$has_completed_scan = (bool) get_option(self::LAST_SCAN_OPTION);

		if (!$has_completed_scan && 'running' !== $scan_state['status']) {
			echo '<span class="description">' . esc_html__('Not scanned', 'mrn-media-bulk-tools') . '</span>';
			return;
		}

		echo '<span class="description">' . esc_html__('No detected uses', 'mrn-media-bulk-tools') . '</span>';
	}

	/**
	 * Load usage controls only in Media Library list mode.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_assets($hook_suffix) {
		if ('upload.php' !== $hook_suffix || !self::is_list_mode()) {
			return;
		}

		$plugin_file = dirname(__DIR__) . '/mrn-media-bulk-tools.php';

		wp_enqueue_style(
			'mrn-media-usage',
			plugins_url('assets/css/media-usage.css', $plugin_file),
			array(),
			MRN_Media_Tools::VERSION
		);

		wp_enqueue_script(
			'mrn-media-usage',
			plugins_url('assets/js/media-usage.js', $plugin_file),
			array(),
			MRN_Media_Tools::VERSION,
			true
		);

		wp_localize_script(
			'mrn-media-usage',
			'MRNMediaUsage',
			array(
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce(self::NONCE_ACTION),
				'canScan' => current_user_can('manage_options'),
				'state' => self::get_scan_state(),
				'lastScan' => get_option(self::LAST_SCAN_OPTION, ''),
				'i18n' => array(
					'scanUsage' => __('Scan usage', 'mrn-media-bulk-tools'),
					'scanning' => __('Scanning media usage…', 'mrn-media-bulk-tools'),
					'scanComplete' => __('Usage scan complete.', 'mrn-media-bulk-tools'),
					'scanFailed' => __('The usage scan could not continue.', 'mrn-media-bulk-tools'),
					'loading' => __('Loading usage…', 'mrn-media-bulk-tools'),
					'close' => __('Close', 'mrn-media-bulk-tools'),
					'usedIn' => __('Used In', 'mrn-media-bulk-tools'),
					'noAccessibleUses' => __('No accessible usage records were found.', 'mrn-media-bulk-tools'),
					'detectedNotice' => __('Detected references may not include media generated dynamically by code or external systems.', 'mrn-media-bulk-tools'),
				),
			)
		);
	}

	/**
	 * Start or resume a manual scan.
	 */
	public static function ajax_start_scan() {
		self::verify_scan_request();
		wp_send_json_success(self::start_scan('manual'));
	}

	/**
	 * Process one manual scan batch.
	 */
	public static function ajax_scan_batch() {
		self::verify_scan_request();
		wp_send_json_success(self::process_scan_batch());
	}

	/**
	 * Return usage records the current user may inspect.
	 */
	public static function ajax_get_usage() {
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		$attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;

		if (!$attachment_id || !current_user_can('edit_post', $attachment_id)) {
			wp_send_json_error(array('message' => __('You are not allowed to inspect this attachment.', 'mrn-media-bulk-tools')), 403);
		}

		global $wpdb;
		$table_name = self::get_table_name();
		$usage_query = $wpdb->prepare(
			// The table name is derived from the trusted WordPress database prefix.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT source_post_id, contexts FROM {$table_name} WHERE attachment_id = %d ORDER BY source_post_id DESC",
				$attachment_id
		);
		$rows = $wpdb->get_results($usage_query, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$records = array();

		foreach ($rows as $row) {
			$post_id = absint($row['source_post_id']);
			$post = get_post($post_id);

			if (!$post instanceof WP_Post || !current_user_can('edit_post', $post_id)) {
				continue;
			}

			$post_type_object = get_post_type_object($post->post_type);
			$contexts = json_decode((string) $row['contexts'], true);
			$records[] = array(
				'id' => $post_id,
				'title' => get_the_title($post_id) ?: sprintf(__('Untitled #%d', 'mrn-media-bulk-tools'), $post_id),
				'editUrl' => get_edit_post_link($post_id, 'raw'),
				'postType' => $post_type_object ? $post_type_object->labels->singular_name : $post->post_type,
				'status' => get_post_status_object($post->post_status) ? get_post_status_object($post->post_status)->label : $post->post_status,
				'contexts' => is_array($contexts) ? array_values(array_map('sanitize_text_field', $contexts)) : array(),
			);
		}

		wp_send_json_success(array('records' => $records));
	}

	/**
	 * Start the daily background refresh.
	 */
	public static function run_daily_scan() {
		self::start_scan('cron');
		self::process_scan_batch();
	}

	/**
	 * Continue a background refresh batch.
	 */
	public static function run_scheduled_batch() {
		self::process_scan_batch();
	}

	/**
	 * Refresh one post immediately after it changes.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post Post object.
	 * @param bool    $update Whether this is an update.
	 */
	public static function update_saved_post_usage($post_id, $post, $update) {
		unset($update);

		if (self::DB_VERSION !== get_option(self::DB_VERSION_OPTION) || !$post instanceof WP_Post || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
			return;
		}

		if ('trash' === $post->post_status || !in_array($post->post_type, self::get_scannable_post_types(), true)) {
			self::remove_post_usage($post_id);
			return;
		}

		self::scan_post($post_id, self::get_current_scan_id());
	}

	/**
	 * Remove all indexed references originating from a post.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function remove_post_usage($post_id) {
		$post_id = absint($post_id);

		if (!$post_id || self::DB_VERSION !== get_option(self::DB_VERSION_OPTION)) {
			return;
		}

		global $wpdb;
		$table_name = self::get_table_name();
		$wpdb->delete($table_name, array('source_post_id' => $post_id), array('%d'));
		delete_post_meta($post_id, self::SOURCE_IDS_META_KEY);
	}

	/**
	 * Create or update the custom usage-index table.
	 */
	private static function install_schema() {
		global $wpdb;
		$table_name = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) unsigned NOT NULL,
			source_post_id bigint(20) unsigned NOT NULL,
			contexts text NOT NULL,
			scan_id varchar(32) NOT NULL DEFAULT '',
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY attachment_source (attachment_id, source_post_id),
			KEY source_post_id (source_post_id),
			KEY scan_id (scan_id)
		) {$charset_collate};";

		dbDelta($sql);
		update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
	}

	/**
	 * Ensure the daily refresh exists.
	 */
	private static function ensure_schedule() {
		if (!wp_next_scheduled(self::DAILY_CRON_HOOK)) {
			wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::DAILY_CRON_HOOK);
		}
	}

	/**
	 * Validate a privileged manual scan request.
	 */
	private static function verify_scan_request() {
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('You are not allowed to scan media usage.', 'mrn-media-bulk-tools')), 403);
		}
	}

	/**
	 * Initialize a new scan unless one is already active.
	 *
	 * @param string $source Scan source.
	 * @return array
	 */
	private static function start_scan($source) {
		$state = self::get_scan_state();

		if ('running' === $state['status']) {
			return $state;
		}

		$query = new WP_Query(
			array(
				'post_type' => self::get_scannable_post_types(),
				'post_status' => self::get_scannable_post_statuses(),
				'posts_per_page' => 1,
				'fields' => 'ids',
				'no_found_rows' => false,
			)
		);

		$state = array(
			'status' => 'running',
			'source' => sanitize_key($source),
			'scan_id' => sanitize_key(wp_generate_password(24, false, false)),
			'offset' => 0,
			'processed' => 0,
			'total' => absint($query->found_posts),
			'started_at' => current_time('mysql', true),
			'completed_at' => '',
		);

		update_option(self::SCAN_STATE_OPTION, $state, false);
		self::schedule_processing();

		return $state;
	}

	/**
	 * Process a bounded batch and return current progress.
	 *
	 * @return array
	 */
	private static function process_scan_batch() {
		$state = self::get_scan_state();

		if ('running' !== $state['status']) {
			return $state;
		}

		if (get_transient(self::SCAN_LOCK_TRANSIENT)) {
			return $state;
		}

		set_transient(self::SCAN_LOCK_TRANSIENT, '1', 5 * MINUTE_IN_SECONDS);

		$query = new WP_Query(
			array(
				'post_type' => self::get_scannable_post_types(),
				'post_status' => self::get_scannable_post_statuses(),
				'posts_per_page' => self::BATCH_SIZE,
				'offset' => absint($state['offset']),
				'orderby' => 'ID',
				'order' => 'ASC',
				'fields' => 'ids',
				'no_found_rows' => true,
			)
		);

		foreach ($query->posts as $post_id) {
			self::scan_post($post_id, $state['scan_id']);
		}

		$batch_count = count($query->posts);
		$state['offset'] += $batch_count;
		$state['processed'] += $batch_count;

		if ($batch_count < self::BATCH_SIZE || $state['processed'] >= $state['total']) {
			global $wpdb;
			$table_name = self::get_table_name();
			// The table name is derived from the trusted WordPress database prefix.
			$cleanup_query = $wpdb->prepare("DELETE FROM {$table_name} WHERE scan_id <> %s", $state['scan_id']); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query($cleanup_query); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			$state['status'] = 'complete';
			$state['completed_at'] = current_time('mysql', true);
			update_option(self::LAST_SCAN_OPTION, $state['completed_at'], false);
			wp_clear_scheduled_hook(self::PROCESS_CRON_HOOK);
		} else {
			self::schedule_processing();
		}

		update_option(self::SCAN_STATE_OPTION, $state, false);
		delete_transient(self::SCAN_LOCK_TRANSIENT);

		return $state;
	}

	/**
	 * Schedule another background batch if one is not already pending.
	 */
	private static function schedule_processing() {
		if (!wp_next_scheduled(self::PROCESS_CRON_HOOK)) {
			wp_schedule_single_event(time() + 15, self::PROCESS_CRON_HOOK);
		}
	}

	/**
	 * Index detected attachment references for one post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $scan_id Current scan identifier.
	 */
	private static function scan_post($post_id, $scan_id) {
		$post = get_post($post_id);

		if (!$post instanceof WP_Post) {
			return;
		}

		$references = self::find_post_references($post);
		global $wpdb;
		$table_name = self::get_table_name();
		$wpdb->delete($table_name, array('source_post_id' => $post_id), array('%d'));

		foreach ($references as $attachment_id => $contexts) {
			$wpdb->insert(
				$table_name,
				array(
					'attachment_id' => absint($attachment_id),
					'source_post_id' => absint($post_id),
					'contexts' => wp_json_encode(array_values($contexts)),
					'scan_id' => sanitize_key($scan_id),
					'updated_at' => current_time('mysql', true),
				),
				array('%d', '%d', '%s', '%s', '%s')
			);
		}

		update_post_meta($post_id, self::SOURCE_IDS_META_KEY, array_map('absint', array_keys($references)));
	}

	/**
	 * Discover attachment references in content, featured images, and media-like fields.
	 *
	 * @param WP_Post $post Post object.
	 * @return array<int,array<string,string>>
	 */
	private static function find_post_references($post) {
		$references = array();
		$thumbnail_id = get_post_thumbnail_id($post->ID);

		if ($thumbnail_id) {
			self::add_reference($references, $thumbnail_id, __('Featured image', 'mrn-media-bulk-tools'));
		}

		self::collect_content_references($post->post_content, __('Content', 'mrn-media-bulk-tools'), $references);

		foreach (get_post_meta($post->ID) as $meta_key => $values) {
			if ('_thumbnail_id' === $meta_key || self::SOURCE_IDS_META_KEY === $meta_key || !self::is_media_like_field($meta_key)) {
				continue;
			}

			$context = sprintf(__('Custom field: %s', 'mrn-media-bulk-tools'), $meta_key);

			foreach ($values as $value) {
				self::collect_value_references(maybe_unserialize($value), $context, $references, 0);
			}
		}

		return $references;
	}

	/**
	 * Collect attachment IDs and upload URLs from content-like strings.
	 *
	 * @param string $content Content to inspect.
	 * @param string $context Usage context.
	 * @param array  $references Accumulated references.
	 */
	private static function collect_content_references($content, $context, &$references) {
		if (!is_string($content) || '' === $content) {
			return;
		}

		$patterns = array(
			'/\bwp-image-(\d+)\b/i',
			'/\bdata-(?:attachment-)?id=["\'](\d+)["\']/i',
			'/["\'](?:id|mediaId|imageId|backgroundImage|poster)["\']\s*:\s*(\d+)/i',
		);

		foreach ($patterns as $pattern) {
			if (preg_match_all($pattern, $content, $matches)) {
				foreach ($matches[1] as $attachment_id) {
					self::add_reference($references, $attachment_id, $context);
				}
			}
		}

		if (preg_match_all('~https?://[^\s"\'<>]+/wp-content/uploads/[^\s"\'<>]+~i', html_entity_decode($content), $url_matches)) {
			foreach ($url_matches[0] as $url) {
				$attachment_id = attachment_url_to_postid(esc_url_raw($url));

				if ($attachment_id) {
					self::add_reference($references, $attachment_id, $context);
				}
			}
		}
	}

	/**
	 * Recursively inspect a media-like field value.
	 *
	 * @param mixed  $value Value to inspect.
	 * @param string $context Usage context.
	 * @param array  $references Accumulated references.
	 * @param int    $depth Current recursion depth.
	 */
	private static function collect_value_references($value, $context, &$references, $depth) {
		if ($depth > 8) {
			return;
		}

		if (is_array($value)) {
			foreach ($value as $nested_value) {
				self::collect_value_references($nested_value, $context, $references, $depth + 1);
			}
			return;
		}

		if (is_numeric($value)) {
			self::add_reference($references, absint($value), $context);
			return;
		}

		if (is_string($value)) {
			self::collect_content_references($value, $context, $references);
		}
	}

	/**
	 * Add one validated attachment/context pair.
	 *
	 * @param array  $references Accumulated references.
	 * @param int    $attachment_id Possible attachment ID.
	 * @param string $context Usage context.
	 */
	private static function add_reference(&$references, $attachment_id, $context) {
		$attachment_id = absint($attachment_id);

		if (!$attachment_id || 'attachment' !== get_post_type($attachment_id)) {
			return;
		}

		if (!isset($references[$attachment_id])) {
			$references[$attachment_id] = array();
		}

		$references[$attachment_id][$context] = $context;
	}

	/**
	 * Identify field names that commonly store media IDs, URLs, or collections.
	 *
	 * @param string $meta_key Meta key.
	 * @return bool
	 */
	private static function is_media_like_field($meta_key) {
		return (bool) preg_match('/(?:^|[_-])(image|img|photo|logo|icon|media|file|gallery|thumbnail|background|video|audio|document)(?:$|[_-])/i', $meta_key);
	}

	/**
	 * Return post types that can contain editable site content.
	 *
	 * @return string[]
	 */
	private static function get_scannable_post_types() {
		$post_types = get_post_types(array('show_ui' => true), 'names');
		$excluded = array('attachment', 'revision', 'acf-field', 'acf-field-group', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page');

		return array_values(array_diff($post_types, $excluded));
	}

	/**
	 * Return content statuses included in the usage index.
	 *
	 * @return string[]
	 */
	private static function get_scannable_post_statuses() {
		return array('publish', 'private', 'draft', 'pending', 'future');
	}

	/**
	 * Get the scan ID used for immediate save-time updates.
	 *
	 * @return string
	 */
	private static function get_current_scan_id() {
		$state = self::get_scan_state();

		return 'running' === $state['status'] && !empty($state['scan_id']) ? $state['scan_id'] : 'live';
	}

	/**
	 * Get a normalized scan state.
	 *
	 * @return array
	 */
	private static function get_scan_state() {
		$defaults = array(
			'status' => 'idle',
			'source' => '',
			'scan_id' => '',
			'offset' => 0,
			'processed' => 0,
			'total' => 0,
			'started_at' => '',
			'completed_at' => '',
		);
		$state = get_option(self::SCAN_STATE_OPTION, array());

		return wp_parse_args(is_array($state) ? $state : array(), $defaults);
	}

	/**
	 * Load usage counts for attachments on the current Media Library page in one query.
	 *
	 * @return array<int,int>
	 */
	private static function get_current_page_usage_counts() {
		static $counts = null;

		if (null !== $counts) {
			return $counts;
		}

		$counts = array();
		global $wp_query, $wpdb;

		if (!$wp_query instanceof WP_Query || empty($wp_query->posts)) {
			return $counts;
		}

		$attachment_ids = array();

		foreach ($wp_query->posts as $post) {
			if ($post instanceof WP_Post && 'attachment' === $post->post_type) {
				$attachment_ids[] = absint($post->ID);
			}
		}

		if (empty($attachment_ids)) {
			return $counts;
		}

		$placeholders = implode(',', array_fill(0, count($attachment_ids), '%d'));
		$table_name = self::get_table_name();
		$sql = $wpdb->prepare(
			// The table name and placeholder list are generated internally.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT attachment_id, COUNT(*) AS usage_count FROM {$table_name} WHERE attachment_id IN ({$placeholders}) GROUP BY attachment_id",
			$attachment_ids
		);

		foreach ($wpdb->get_results($sql, ARRAY_A) as $row) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$counts[absint($row['attachment_id'])] = absint($row['usage_count']);
		}

		return $counts;
	}

	/**
	 * Determine whether Media Library list mode is active.
	 *
	 * @return bool
	 */
	private static function is_list_mode() {
		if (isset($_GET['mode'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display preference.
			return 'grid' !== sanitize_key(wp_unslash($_GET['mode'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display preference.
		}

		return 'grid' !== get_user_option('media_library_mode');
	}

	/**
	 * Get the current site's usage-index table name.
	 *
	 * @return string
	 */
	private static function get_table_name() {
		global $wpdb;

		return $wpdb->prefix . 'mrn_media_usage';
	}
}
