<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Provides the dedicated inline attachment metadata editor.
 */
final class MRN_Media_Bulk_Editor {
	const MENU_SLUG = 'mrn-media-bulk-tools';
	const SAVE_ACTION = 'mrn_media_inline_save';
	const SAVE_NONCE_ACTION = 'mrn_media_inline_save';
	const ITEMS_PER_PAGE = 25;

	/**
	 * Register editor hooks.
	 */
	public static function init() {
		if (!is_admin()) {
			return;
		}

		add_action('admin_post_' . self::SAVE_ACTION, array(__CLASS__, 'handle_save_request'));
		add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
		add_filter('posts_where', array(__CLASS__, 'filter_attachment_metadata_where'), 10, 2);
	}

	/**
	 * Load the editor assets only on its admin screen.
	 *
	 * @param string $hook_suffix Current admin screen hook suffix.
	 */
	public static function enqueue_assets($hook_suffix) {
		if ('media_page_' . self::MENU_SLUG !== $hook_suffix) {
			return;
		}

		$plugin_file = dirname(__DIR__) . '/mrn-media-bulk-tools.php';

		wp_enqueue_style(
			'mrn-media-bulk-editor',
			plugins_url('assets/css/media-bulk-editor.css', $plugin_file),
			array(),
			MRN_Media_Tools::VERSION
		);

		wp_enqueue_script(
			'mrn-media-bulk-editor',
			plugins_url('assets/js/media-bulk-editor.js', $plugin_file),
			array(),
			MRN_Media_Tools::VERSION,
			true
		);

		wp_localize_script(
			'mrn-media-bulk-editor',
			'MRNMediaBulkEditor',
			array(
				'i18n' => array(
					'noneSelected' => __('No rows selected', 'mrn-media-bulk-tools'),
					'oneSelected' => __('1 row selected', 'mrn-media-bulk-tools'),
					'manySelected' => __('%d rows selected', 'mrn-media-bulk-tools'),
					'saved' => __('Saved', 'mrn-media-bulk-tools'),
					'unsaved' => __('Unsaved changes', 'mrn-media-bulk-tools'),
				),
			)
		);
	}

	/**
	 * Render the inline editor workspace.
	 */
	public static function render_page() {
		if (!current_user_can('upload_files')) {
			wp_die(esc_html__('You are not allowed to bulk edit media items.', 'mrn-media-bulk-tools'));
		}

		$filters = self::get_current_filters();
		$query = self::get_attachment_query($filters);
		$attachments = array_filter($query->posts, static function ($post) {
			return $post instanceof WP_Post && 'attachment' === $post->post_type;
		});
		?>
		<div class="wrap mrn-media-bulk-editor-wrap">
			<h1><?php echo esc_html__('Bulk Media Update', 'mrn-media-bulk-tools'); ?></h1>
			<p class="mrn-media-bulk-editor-intro">
				<?php echo esc_html__('Review and edit attachment metadata in place. Changed rows are selected automatically and nothing is saved until you submit the form.', 'mrn-media-bulk-tools'); ?>
			</p>

			<?php self::render_save_notice(); ?>
			<?php self::render_filters($filters, (int) $query->found_posts); ?>

			<form id="mrn-media-bulk-editor-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_ACTION); ?>" />
				<?php wp_nonce_field(self::SAVE_NONCE_ACTION); ?>
				<?php self::render_return_fields($filters); ?>

				<div class="mrn-media-bulk-editor-actions mrn-media-bulk-editor-actions--top">
					<button type="submit" class="button button-primary mrn-media-bulk-editor-save">
						<?php echo esc_html__('Save changed rows', 'mrn-media-bulk-tools'); ?>
					</button>
					<span class="mrn-media-bulk-editor-selection" aria-live="polite">
						<?php echo esc_html__('No rows selected', 'mrn-media-bulk-tools'); ?>
					</span>
				</div>

				<div class="mrn-media-bulk-editor-table-wrap">
					<table class="wp-list-table widefat fixed striped table-view-list media mrn-media-bulk-editor-table">
						<caption class="screen-reader-text"><?php echo esc_html__('Editable Media Library attachment metadata', 'mrn-media-bulk-tools'); ?></caption>
						<thead>
							<tr>
								<td class="manage-column column-cb check-column">
									<input id="mrn-media-bulk-select-all" type="checkbox" />
									<label for="mrn-media-bulk-select-all"><span class="screen-reader-text"><?php echo esc_html__('Select all attachments on this page', 'mrn-media-bulk-tools'); ?></span></label>
								</td>
								<th scope="col" class="manage-column column-preview"><?php echo esc_html__('Preview', 'mrn-media-bulk-tools'); ?></th>
								<th scope="col" class="manage-column column-file"><?php echo esc_html__('File', 'mrn-media-bulk-tools'); ?></th>
								<th scope="col" class="manage-column column-title"><?php echo esc_html__('Title', 'mrn-media-bulk-tools'); ?></th>
								<th scope="col" class="manage-column column-alt"><?php echo esc_html__('Alt Text', 'mrn-media-bulk-tools'); ?></th>
								<th scope="col" class="manage-column column-caption"><?php echo esc_html__('Caption', 'mrn-media-bulk-tools'); ?></th>
								<th scope="col" class="manage-column column-description"><?php echo esc_html__('Description', 'mrn-media-bulk-tools'); ?></th>
								<th scope="col" class="manage-column column-status"><?php echo esc_html__('Status', 'mrn-media-bulk-tools'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if (empty($attachments)) : ?>
								<tr class="no-items">
									<td colspan="8"><?php echo esc_html__('No media items match these filters.', 'mrn-media-bulk-tools'); ?></td>
								</tr>
							<?php else : ?>
								<?php foreach ($attachments as $attachment) : ?>
									<?php self::render_attachment_row($attachment); ?>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>

				<div class="mrn-media-bulk-editor-actions mrn-media-bulk-editor-actions--bottom">
					<button type="submit" class="button button-primary mrn-media-bulk-editor-save">
						<?php echo esc_html__('Save changed rows', 'mrn-media-bulk-tools'); ?>
					</button>
					<span class="mrn-media-bulk-editor-selection" aria-live="polite">
						<?php echo esc_html__('No rows selected', 'mrn-media-bulk-tools'); ?>
					</span>
				</div>
			</form>

			<?php self::render_pagination($filters, (int) $query->max_num_pages, (int) $query->found_posts); ?>
		</div>
		<?php
	}

	/**
	 * Save selected attachment rows.
	 */
	public static function handle_save_request() {
		if (!current_user_can('upload_files')) {
			wp_die(esc_html__('You are not allowed to bulk edit media items.', 'mrn-media-bulk-tools'));
		}

		check_admin_referer(self::SAVE_NONCE_ACTION);

		$rows = isset($_POST['media']) && is_array($_POST['media'])
			? map_deep(wp_unslash($_POST['media']), 'sanitize_textarea_field')
			: array();
		$selected_ids = isset($_POST['selected_media']) && is_array($_POST['selected_media'])
			? wp_parse_id_list(wp_unslash($_POST['selected_media']))
			: array();
		$single_id = isset($_POST['save_media_id']) ? absint(wp_unslash($_POST['save_media_id'])) : 0;

		if ($single_id > 0) {
			$selected_ids = array($single_id);
		}

		$updated = 0;
		$unchanged = 0;
		$failed = 0;

		foreach ($selected_ids as $attachment_id) {
			$attachment_id = absint($attachment_id);

			if (!isset($rows[$attachment_id]) || !is_array($rows[$attachment_id])) {
				++$failed;
				continue;
			}

			$row = $rows[$attachment_id];
			$result = self::save_attachment_row($attachment_id, $row);

			if (is_wp_error($result)) {
				++$failed;
			} elseif ($result) {
				++$updated;
			} else {
				++$unchanged;
			}
		}

		$redirect_url = self::get_editor_url(self::get_return_filters());
		$redirect_url = add_query_arg(
			array(
				'mrn_media_inline_saved' => 1,
				'mrn_media_inline_updated' => $updated,
				'mrn_media_inline_unchanged' => $unchanged,
				'mrn_media_inline_failed' => $failed,
			),
			$redirect_url
		);

		wp_safe_redirect($redirect_url);
		exit;
	}

	/**
	 * Limit metadata-state queries to attachments with an empty requested field.
	 *
	 * @param string   $where Existing SQL WHERE fragment.
	 * @param WP_Query $query Query instance.
	 * @return string
	 */
	public static function filter_attachment_metadata_where($where, $query) {
		$metadata_filter = sanitize_key((string) $query->get('mrn_media_metadata_filter'));

		if (!in_array($metadata_filter, array('missing_title', 'missing_caption', 'missing_description'), true)) {
			return $where;
		}

		$post_type = $query->get('post_type');
		if ('attachment' !== $post_type && (!is_array($post_type) || !in_array('attachment', $post_type, true))) {
			return $where;
		}

		global $wpdb;

		if ('missing_title' === $metadata_filter) {
			$where .= " AND {$wpdb->posts}.post_title = ''";
		} elseif ('missing_caption' === $metadata_filter) {
			$where .= " AND {$wpdb->posts}.post_excerpt = ''";
		} else {
			$where .= " AND {$wpdb->posts}.post_content = ''";
		}

		return $where;
	}

	/**
	 * Save one attachment row.
	 *
	 * @param int   $attachment_id Attachment post ID.
	 * @param array $row Submitted row values.
	 * @return bool|WP_Error True when changed, false when unchanged, or error.
	 */
	private static function save_attachment_row($attachment_id, array $row) {
		$attachment = get_post($attachment_id);

		if (!$attachment instanceof WP_Post || 'attachment' !== $attachment->post_type || !current_user_can('edit_post', $attachment_id)) {
			return new WP_Error('invalid_attachment', __('This attachment cannot be edited.', 'mrn-media-bulk-tools'));
		}

		$post_update = array('ID' => $attachment_id);

		if (isset($row['title']) && !is_array($row['title'])) {
			$title = sanitize_text_field($row['title']);
		}

		if (isset($title) && $title !== (string) $attachment->post_title) {
			$post_update['post_title'] = $title;
		}

		if (isset($row['caption']) && !is_array($row['caption'])) {
			$caption = sanitize_textarea_field($row['caption']);
		}

		if (isset($caption) && $caption !== (string) $attachment->post_excerpt) {
			$post_update['post_excerpt'] = $caption;
		}

		if (isset($row['description']) && !is_array($row['description'])) {
			$description = sanitize_textarea_field($row['description']);
		}

		if (isset($description) && $description !== (string) $attachment->post_content) {
			$post_update['post_content'] = $description;
		}

		$changed = count($post_update) > 1;

		if ($changed) {
			$result = wp_update_post($post_update, true);

			if (is_wp_error($result)) {
				return $result;
			}
		}

		if (self::is_image_attachment($attachment) && isset($row['alt']) && !is_array($row['alt'])) {
			$alt = sanitize_text_field($row['alt']);
			$current_alt = (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true);

			if ($alt !== $current_alt) {
				update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);
				$changed = true;
			}
		}

		return $changed;
	}

	/**
	 * Build the attachment query for the current editor filters.
	 *
	 * @param array $filters Sanitized filters.
	 * @return WP_Query
	 */
	private static function get_attachment_query(array $filters) {
		$orderby = 'date';
		$order = 'DESC';

		if ('date_asc' === $filters['orderby']) {
			$order = 'ASC';
		} elseif ('title_asc' === $filters['orderby']) {
			$orderby = 'title';
			$order = 'ASC';
		} elseif ('title_desc' === $filters['orderby']) {
			$orderby = 'title';
		}

		$args = array(
			'post_type' => 'attachment',
			'post_status' => 'inherit',
			'posts_per_page' => self::ITEMS_PER_PAGE,
			'paged' => $filters['paged'],
			'orderby' => $orderby,
			'order' => $order,
			's' => $filters['search'],
			'mrn_media_metadata_filter' => $filters['metadata'],
		);

		if ('' !== $filters['media_type']) {
			$args['post_mime_type'] = $filters['media_type'];
		}

		if ('missing_alt' === $filters['metadata']) {
			$args['post_mime_type'] = 'image';
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				array(
					'key' => '_wp_attachment_image_alt',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key' => '_wp_attachment_image_alt',
					'value' => '',
					'compare' => '=',
				),
			);
		}

		return new WP_Query($args);
	}

	/**
	 * Read and sanitize editor filters from the URL.
	 *
	 * @return array
	 */
	private static function get_current_filters() {
		return self::sanitize_filters(array(
			'search' => isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'media_type' => isset($_GET['media_type']) ? sanitize_mime_type(wp_unslash($_GET['media_type'])) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'metadata' => isset($_GET['metadata']) ? sanitize_key(wp_unslash($_GET['metadata'])) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'orderby' => isset($_GET['orderby']) ? sanitize_key(wp_unslash($_GET['orderby'])) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'paged' => isset($_GET['paged']) ? absint(wp_unslash($_GET['paged'])) : 1, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		));
	}

	/**
	 * Read safe return filters from a save request.
	 *
	 * @return array
	 */
	private static function get_return_filters() {
		$return_filters = isset($_POST['return_filters']) && is_array($_POST['return_filters']) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? map_deep(wp_unslash($_POST['return_filters']), 'sanitize_text_field') // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: array();

		return self::sanitize_filters($return_filters);
	}

	/**
	 * Normalize editor filters.
	 *
	 * @param array $filters Raw filters.
	 * @return array
	 */
	private static function sanitize_filters(array $filters) {
		$media_type = isset($filters['media_type']) && !is_array($filters['media_type']) ? sanitize_mime_type($filters['media_type']) : '';
		$metadata = isset($filters['metadata']) && !is_array($filters['metadata']) ? sanitize_key($filters['metadata']) : '';
		$orderby = isset($filters['orderby']) && !is_array($filters['orderby']) ? sanitize_key($filters['orderby']) : '';

		if (!in_array($media_type, array('', 'image', 'audio', 'video', 'application'), true)) {
			$media_type = '';
		}

		if (!in_array($metadata, array('', 'missing_title', 'missing_alt', 'missing_caption', 'missing_description'), true)) {
			$metadata = '';
		}

		if (!in_array($orderby, array('date_desc', 'date_asc', 'title_asc', 'title_desc'), true)) {
			$orderby = 'date_desc';
		}

		return array(
			'search' => isset($filters['search']) && !is_array($filters['search']) ? sanitize_text_field($filters['search']) : '',
			'media_type' => $media_type,
			'metadata' => $metadata,
			'orderby' => $orderby,
			'paged' => max(1, isset($filters['paged']) ? absint($filters['paged']) : 1),
		);
	}

	/**
	 * Render filter controls.
	 *
	 * @param array $filters Sanitized filters.
	 * @param int   $found_items Total matching items.
	 */
	private static function render_filters(array $filters, $found_items) {
		?>
		<form class="mrn-media-bulk-editor-filters" method="get" action="<?php echo esc_url(admin_url('upload.php')); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr(self::MENU_SLUG); ?>" />
			<label>
				<span class="screen-reader-text"><?php echo esc_html__('Search media', 'mrn-media-bulk-tools'); ?></span>
				<input type="search" name="s" value="<?php echo esc_attr($filters['search']); ?>" placeholder="<?php echo esc_attr__('Search media', 'mrn-media-bulk-tools'); ?>" />
			</label>
			<label>
				<span class="screen-reader-text"><?php echo esc_html__('Filter by media type', 'mrn-media-bulk-tools'); ?></span>
				<select name="media_type">
					<option value=""><?php echo esc_html__('All media types', 'mrn-media-bulk-tools'); ?></option>
					<option value="image" <?php selected($filters['media_type'], 'image'); ?>><?php echo esc_html__('Images', 'mrn-media-bulk-tools'); ?></option>
					<option value="audio" <?php selected($filters['media_type'], 'audio'); ?>><?php echo esc_html__('Audio', 'mrn-media-bulk-tools'); ?></option>
					<option value="video" <?php selected($filters['media_type'], 'video'); ?>><?php echo esc_html__('Video', 'mrn-media-bulk-tools'); ?></option>
					<option value="application" <?php selected($filters['media_type'], 'application'); ?>><?php echo esc_html__('Documents', 'mrn-media-bulk-tools'); ?></option>
				</select>
			</label>
			<label>
				<span class="screen-reader-text"><?php echo esc_html__('Filter by missing metadata', 'mrn-media-bulk-tools'); ?></span>
				<select name="metadata">
					<option value=""><?php echo esc_html__('Any metadata status', 'mrn-media-bulk-tools'); ?></option>
					<option value="missing_title" <?php selected($filters['metadata'], 'missing_title'); ?>><?php echo esc_html__('Missing title', 'mrn-media-bulk-tools'); ?></option>
					<option value="missing_alt" <?php selected($filters['metadata'], 'missing_alt'); ?>><?php echo esc_html__('Missing alt text', 'mrn-media-bulk-tools'); ?></option>
					<option value="missing_caption" <?php selected($filters['metadata'], 'missing_caption'); ?>><?php echo esc_html__('Missing caption', 'mrn-media-bulk-tools'); ?></option>
					<option value="missing_description" <?php selected($filters['metadata'], 'missing_description'); ?>><?php echo esc_html__('Missing description', 'mrn-media-bulk-tools'); ?></option>
				</select>
			</label>
			<label>
				<span class="screen-reader-text"><?php echo esc_html__('Sort media', 'mrn-media-bulk-tools'); ?></span>
				<select name="orderby">
					<option value="date_desc" <?php selected($filters['orderby'], 'date_desc'); ?>><?php echo esc_html__('Newest first', 'mrn-media-bulk-tools'); ?></option>
					<option value="date_asc" <?php selected($filters['orderby'], 'date_asc'); ?>><?php echo esc_html__('Oldest first', 'mrn-media-bulk-tools'); ?></option>
					<option value="title_asc" <?php selected($filters['orderby'], 'title_asc'); ?>><?php echo esc_html__('Title A–Z', 'mrn-media-bulk-tools'); ?></option>
					<option value="title_desc" <?php selected($filters['orderby'], 'title_desc'); ?>><?php echo esc_html__('Title Z–A', 'mrn-media-bulk-tools'); ?></option>
				</select>
			</label>
			<button type="submit" class="button"><?php echo esc_html__('Filter', 'mrn-media-bulk-tools'); ?></button>
			<a class="button" href="<?php echo esc_url(self::get_editor_url()); ?>"><?php echo esc_html__('Reset', 'mrn-media-bulk-tools'); ?></a>
			<span class="mrn-media-bulk-editor-total">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: number of matching media items. */
						_n('%s matching item', '%s matching items', $found_items, 'mrn-media-bulk-tools'),
						number_format_i18n($found_items)
					)
				);
				?>
			</span>
		</form>
		<?php
	}

	/**
	 * Render one editable row.
	 *
	 * @param WP_Post $attachment Attachment post.
	 */
	private static function render_attachment_row($attachment) {
		$attachment_id = (int) $attachment->ID;
		$can_edit = current_user_can('edit_post', $attachment_id);
		$is_image = self::is_image_attachment($attachment);
		$alt = $is_image ? (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true) : '';
		$file_name = self::get_attachment_file_name($attachment_id);
		$edit_url = get_edit_post_link($attachment_id, 'raw');
		$file_url = wp_get_attachment_url($attachment_id);
		$disabled = $can_edit ? '' : ' disabled="disabled"';
		?>
		<tr class="mrn-media-bulk-editor-row" data-attachment-id="<?php echo esc_attr((string) $attachment_id); ?>">
			<th scope="row" class="check-column">
				<input id="mrn-media-select-<?php echo esc_attr((string) $attachment_id); ?>" class="mrn-media-row-select" type="checkbox" name="selected_media[]" value="<?php echo esc_attr((string) $attachment_id); ?>" <?php disabled(!$can_edit); ?> />
				<label for="mrn-media-select-<?php echo esc_attr((string) $attachment_id); ?>"><span class="screen-reader-text"><?php echo esc_html(sprintf(__('Select %s', 'mrn-media-bulk-tools'), $file_name)); ?></span></label>
			</th>
			<td class="column-preview"><?php self::render_attachment_preview($attachment_id, $file_name); ?></td>
			<td class="column-file">
				<strong><?php echo esc_html($file_name); ?></strong>
				<span>#<?php echo esc_html((string) $attachment_id); ?> · <?php echo esc_html((string) $attachment->post_mime_type); ?></span>
				<div class="row-actions">
					<?php if (is_string($edit_url) && '' !== $edit_url) : ?>
						<span class="edit"><a href="<?php echo esc_url($edit_url); ?>"><?php echo esc_html__('Edit details', 'mrn-media-bulk-tools'); ?></a></span>
					<?php endif; ?>
					<?php if (is_string($file_url) && '' !== $file_url) : ?>
						<span class="view"> | <a href="<?php echo esc_url($file_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('View file', 'mrn-media-bulk-tools'); ?><span class="screen-reader-text"> <?php echo esc_html__('(opens in a new tab)', 'mrn-media-bulk-tools'); ?></span></a></span>
					<?php endif; ?>
				</div>
			</td>
			<td class="column-title">
				<label class="screen-reader-text" for="mrn-media-title-<?php echo esc_attr((string) $attachment_id); ?>"><?php echo esc_html(sprintf(__('Title for %s', 'mrn-media-bulk-tools'), $file_name)); ?></label>
				<input id="mrn-media-title-<?php echo esc_attr((string) $attachment_id); ?>" class="widefat mrn-media-edit-field" type="text" name="media[<?php echo esc_attr((string) $attachment_id); ?>][title]" value="<?php echo esc_attr((string) $attachment->post_title); ?>"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
			</td>
			<td class="column-alt">
				<?php if ($is_image) : ?>
					<label class="screen-reader-text" for="mrn-media-alt-<?php echo esc_attr((string) $attachment_id); ?>"><?php echo esc_html(sprintf(__('Alt text for %s', 'mrn-media-bulk-tools'), $file_name)); ?></label>
					<textarea id="mrn-media-alt-<?php echo esc_attr((string) $attachment_id); ?>" class="widefat mrn-media-edit-field" name="media[<?php echo esc_attr((string) $attachment_id); ?>][alt]" rows="3"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_textarea($alt); ?></textarea>
				<?php else : ?>
					<span class="mrn-media-bulk-editor-not-applicable"><?php echo esc_html__('Not used for this file type', 'mrn-media-bulk-tools'); ?></span>
				<?php endif; ?>
			</td>
			<td class="column-caption">
				<label class="screen-reader-text" for="mrn-media-caption-<?php echo esc_attr((string) $attachment_id); ?>"><?php echo esc_html(sprintf(__('Caption for %s', 'mrn-media-bulk-tools'), $file_name)); ?></label>
				<textarea id="mrn-media-caption-<?php echo esc_attr((string) $attachment_id); ?>" class="widefat mrn-media-edit-field" name="media[<?php echo esc_attr((string) $attachment_id); ?>][caption]" rows="3"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_textarea((string) $attachment->post_excerpt); ?></textarea>
			</td>
			<td class="column-description">
				<label class="screen-reader-text" for="mrn-media-description-<?php echo esc_attr((string) $attachment_id); ?>"><?php echo esc_html(sprintf(__('Description for %s', 'mrn-media-bulk-tools'), $file_name)); ?></label>
				<textarea id="mrn-media-description-<?php echo esc_attr((string) $attachment_id); ?>" class="widefat mrn-media-edit-field" name="media[<?php echo esc_attr((string) $attachment_id); ?>][description]" rows="3"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_textarea((string) $attachment->post_content); ?></textarea>
			</td>
			<td class="column-status">
				<span class="mrn-media-row-status" aria-live="polite"><?php echo $can_edit ? esc_html__('Saved', 'mrn-media-bulk-tools') : esc_html__('Read only', 'mrn-media-bulk-tools'); ?></span>
				<?php if ($can_edit) : ?>
					<button type="submit" class="button button-small mrn-media-row-save" name="save_media_id" value="<?php echo esc_attr((string) $attachment_id); ?>"><?php echo esc_html__('Save row', 'mrn-media-bulk-tools'); ?></button>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render an attachment thumbnail or MIME icon.
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $file_name     Attachment filename for context.
	 */
	private static function render_attachment_preview($attachment_id, $file_name) {
		$preview = wp_get_attachment_image(
			$attachment_id,
			array(80, 80),
			true,
			array(
				'class' => 'mrn-media-bulk-editor-thumbnail',
				'alt' => '',
				'loading' => 'lazy',
			)
		);

		if (is_string($preview) && '' !== $preview) {
			echo $preview; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		$icon_url = wp_mime_type_icon($attachment_id);
		if (is_string($icon_url) && '' !== $icon_url) {
			printf(
				'<img class="mrn-media-bulk-editor-thumbnail" src="%1$s" alt="%2$s" loading="lazy" />',
				esc_url($icon_url),
				esc_attr(sprintf(__('File preview for %s', 'mrn-media-bulk-tools'), $file_name))
			);
			return;
		}

		echo '<span class="dashicons dashicons-media-default" aria-hidden="true"></span>';
	}

	/**
	 * Render hidden return-state inputs.
	 *
	 * @param array $filters Sanitized filters.
	 */
	private static function render_return_fields(array $filters) {
		foreach ($filters as $key => $value) {
			printf(
				'<input type="hidden" name="return_filters[%1$s]" value="%2$s" />',
				esc_attr($key),
				esc_attr((string) $value)
			);
		}
	}

	/**
	 * Render the result of the most recent save.
	 */
	private static function render_save_notice() {
		if (empty($_GET['mrn_media_inline_saved'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$updated = isset($_GET['mrn_media_inline_updated']) ? absint($_GET['mrn_media_inline_updated']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$unchanged = isset($_GET['mrn_media_inline_unchanged']) ? absint($_GET['mrn_media_inline_unchanged']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$failed = isset($_GET['mrn_media_inline_failed']) ? absint($_GET['mrn_media_inline_failed']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice_class = $failed > 0 ? 'notice notice-warning is-dismissible' : 'notice notice-success is-dismissible';
		$message = sprintf(
			/* translators: 1: updated count, 2: unchanged count, 3: failed count. */
			__('Saved %1$d changed row(s). %2$d unchanged. %3$d failed.', 'mrn-media-bulk-tools'),
			$updated,
			$unchanged,
			$failed
		);

		printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($notice_class), esc_html($message));
	}

	/**
	 * Render pagination links beneath the editor.
	 *
	 * @param array $filters       Sanitized filters.
	 * @param int   $total_pages   Total page count.
	 * @param int   $total_items   Total result count.
	 */
	private static function render_pagination(array $filters, $total_pages, $total_items) {
		if ($total_pages < 2) {
			return;
		}

		$base_filters = $filters;
		unset($base_filters['paged']);
		$base_url = self::get_editor_url($base_filters);
		$links = paginate_links(array(
			'base' => add_query_arg('paged', '%#%', $base_url),
			'format' => '',
			'current' => $filters['paged'],
			'total' => $total_pages,
			'prev_text' => __('‹ Previous', 'mrn-media-bulk-tools'),
			'next_text' => __('Next ›', 'mrn-media-bulk-tools'),
			'type' => 'list',
		));

		if (!is_string($links) || '' === $links) {
			return;
		}
		?>
		<div class="tablenav bottom mrn-media-bulk-editor-pagination">
			<div class="tablenav-pages">
				<span class="displaying-num">
					<?php echo esc_html(sprintf(_n('%s item', '%s items', $total_items, 'mrn-media-bulk-tools'), number_format_i18n($total_items))); ?>
				</span>
				<?php echo wp_kses_post($links); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Build a URL for this editor.
	 *
	 * @param array $filters Optional filter query arguments.
	 * @return string
	 */
	private static function get_editor_url(array $filters = array()) {
		$query_args = array('page' => self::MENU_SLUG);

		if (isset($filters['search']) && '' !== $filters['search']) {
			$query_args['s'] = $filters['search'];
		}
		if (isset($filters['media_type']) && '' !== $filters['media_type']) {
			$query_args['media_type'] = $filters['media_type'];
		}
		if (isset($filters['metadata']) && '' !== $filters['metadata']) {
			$query_args['metadata'] = $filters['metadata'];
		}
		if (isset($filters['orderby']) && 'date_desc' !== $filters['orderby']) {
			$query_args['orderby'] = $filters['orderby'];
		}
		if (isset($filters['paged']) && $filters['paged'] > 1) {
			$query_args['paged'] = $filters['paged'];
		}

		return add_query_arg($query_args, admin_url('upload.php'));
	}

	/**
	 * Resolve the stored filename for a row.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return string
	 */
	private static function get_attachment_file_name($attachment_id) {
		$file = get_attached_file($attachment_id);
		if (is_string($file) && '' !== $file) {
			return wp_basename($file);
		}

		$url = wp_get_attachment_url($attachment_id);
		if (is_string($url) && '' !== $url) {
			return wp_basename((string) wp_parse_url($url, PHP_URL_PATH));
		}

		return sprintf(__('Attachment #%d', 'mrn-media-bulk-tools'), $attachment_id);
	}

	/**
	 * Determine whether a post is an image attachment.
	 *
	 * @param WP_Post $attachment Attachment post.
	 * @return bool
	 */
	private static function is_image_attachment($attachment) {
		return strpos((string) $attachment->post_mime_type, 'image/') === 0;
	}
}
