<?php

if (!defined('ABSPATH')) {
	exit;
}

final class MRN_Media_Bulk_Tools {
	const REQUEST_TRANSIENT_PREFIX = 'mrn_media_bulk_tools_request_';

	public static function init() {
		if (!is_admin()) {
			return;
		}

		add_filter('bulk_actions-upload', array(__CLASS__, 'register_media_bulk_actions'));
		add_filter('handle_bulk_actions-upload', array(__CLASS__, 'handle_media_bulk_action'), 10, 3);
		add_action('admin_menu', array(__CLASS__, 'register_media_bulk_tools_page'));
		add_action('admin_head', array(__CLASS__, 'hide_media_bulk_tools_page'));
		add_action('admin_notices', array(__CLASS__, 'render_media_bulk_action_notice'));
	}

	public static function register_media_bulk_actions($actions) {
		if (!current_user_can('upload_files')) {
			return $actions;
		}

		$actions['mrn_media_bulk_update_title'] = __('Update title', 'mrn-media-bulk-tools');
		$actions['mrn_media_bulk_update_alt'] = __('Update alt text', 'mrn-media-bulk-tools');
		$actions['mrn_media_bulk_update_caption'] = __('Update caption', 'mrn-media-bulk-tools');
		$actions['mrn_media_bulk_update_all'] = __('Update title, alt text, and caption', 'mrn-media-bulk-tools');

		return $actions;
	}

	public static function register_media_bulk_tools_page() {
		add_submenu_page(
			'upload.php',
			__('Bulk Media Update', 'mrn-media-bulk-tools'),
			__('Bulk Media Update', 'mrn-media-bulk-tools'),
			'upload_files',
			'mrn-media-bulk-tools',
			array(__CLASS__, 'render_media_bulk_tools_page')
		);
	}

	public static function hide_media_bulk_tools_page() {
		remove_submenu_page('upload.php', 'mrn-media-bulk-tools');
	}

	public static function render_media_bulk_tools_page() {
		if (!current_user_can('upload_files')) {
			wp_die(esc_html__('You are not allowed to bulk edit media items.', 'mrn-media-bulk-tools'));
		}

		$bulk_action = isset($_GET['bulk_action']) ? sanitize_key(wp_unslash($_GET['bulk_action'])) : '';
		$request_token = isset($_GET['mrn_media_bulk_request']) ? sanitize_key(wp_unslash($_GET['mrn_media_bulk_request'])) : '';
		$request_data = self::get_media_bulk_request_data($request_token);
		$attachment_ids = isset($request_data['attachment_ids']) && is_array($request_data['attachment_ids']) ? $request_data['attachment_ids'] : array();
		$redirect_url = isset($request_data['redirect_url']) ? self::sanitize_media_bulk_redirect_url($request_data['redirect_url']) : self::get_media_bulk_redirect_url();
		$action_label = self::get_media_bulk_action_labels()[$bulk_action] ?? '';

		if (!self::is_custom_media_bulk_action($bulk_action) || empty($attachment_ids)) {
			wp_safe_redirect($redirect_url);
			exit;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html($action_label); ?></h1>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of selected media items. */
						_n('Prepare updates for %d selected media item.', 'Prepare updates for %d selected media items.', count($attachment_ids), 'mrn-media-bulk-tools'),
						count($attachment_ids)
					)
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url(admin_url('upload.php')); ?>">
				<?php wp_nonce_field('bulk-media'); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr($bulk_action); ?>" />
				<input type="hidden" name="mrn_media_bulk_redirect" value="<?php echo esc_attr($redirect_url); ?>" />
				<input type="hidden" name="mrn_media_bulk_request" value="<?php echo esc_attr($request_token); ?>" />
				<table class="form-table" role="presentation">
					<tbody>
						<?php if (in_array($bulk_action, array('mrn_media_bulk_update_title', 'mrn_media_bulk_update_all'), true)) : ?>
							<tr>
								<th scope="row">
									<label for="mrn-media-bulk-title-template"><?php echo esc_html__('Title template', 'mrn-media-bulk-tools'); ?></label>
								</th>
								<td>
									<input type="text" name="mrn_media_bulk_title_template" id="mrn-media-bulk-title-template" class="regular-text" />
								</td>
							</tr>
						<?php endif; ?>
						<?php if (in_array($bulk_action, array('mrn_media_bulk_update_alt', 'mrn_media_bulk_update_all'), true)) : ?>
							<tr>
								<th scope="row">
									<label for="mrn-media-bulk-alt-template"><?php echo esc_html__('Alt text template', 'mrn-media-bulk-tools'); ?></label>
								</th>
								<td>
									<input type="text" name="mrn_media_bulk_alt_template" id="mrn-media-bulk-alt-template" class="regular-text" />
								</td>
							</tr>
						<?php endif; ?>
						<?php if (in_array($bulk_action, array('mrn_media_bulk_update_caption', 'mrn_media_bulk_update_all'), true)) : ?>
							<tr>
								<th scope="row">
									<label for="mrn-media-bulk-caption-template"><?php echo esc_html__('Caption template', 'mrn-media-bulk-tools'); ?></label>
								</th>
								<td>
									<input type="text" name="mrn_media_bulk_caption_template" id="mrn-media-bulk-caption-template" class="regular-text" />
								</td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>
				<p class="description">
					<?php echo esc_html__('Tokens: {file_name}, {file_title}, {file_basename}, {file_extension}, {mime_type}, {mime_subtype}', 'mrn-media-bulk-tools'); ?>
				</p>
				<?php foreach ($attachment_ids as $attachment_id) : ?>
					<input type="hidden" name="media[]" value="<?php echo esc_attr((string) (int) $attachment_id); ?>" />
				<?php endforeach; ?>
				<?php submit_button(__('Apply Update', 'mrn-media-bulk-tools')); ?>
				<p><a href="<?php echo esc_url($redirect_url); ?>"><?php echo esc_html__('Cancel', 'mrn-media-bulk-tools'); ?></a></p>
			</form>
		</div>
		<?php
	}

	public static function handle_media_bulk_action($redirect_to, $bulk_action, $attachment_ids) {
		if (!self::is_custom_media_bulk_action($bulk_action)) {
			return $redirect_to;
		}

		if (!current_user_can('upload_files')) {
			wp_die(esc_html__('You are not allowed to bulk edit media items.', 'mrn-media-bulk-tools'));
		}

		check_admin_referer('bulk-media');

		$attachment_ids = wp_parse_id_list($attachment_ids);
		$request_token = isset($_REQUEST['mrn_media_bulk_request']) ? sanitize_key(wp_unslash($_REQUEST['mrn_media_bulk_request'])) : '';
		$request_data = self::get_media_bulk_request_data($request_token);
		$redirect_url = self::sanitize_media_bulk_redirect_url($redirect_to);

		if ('' !== $request_token && isset($request_data['redirect_url'])) {
			$redirect_url = self::sanitize_media_bulk_redirect_url($request_data['redirect_url']);
		}

		if (empty($attachment_ids) && isset($request_data['attachment_ids']) && is_array($request_data['attachment_ids'])) {
			$attachment_ids = wp_parse_id_list($request_data['attachment_ids']);
		}

		if (empty($attachment_ids)) {
			return add_query_arg('mrn_media_bulk_error', 'no-selection', $redirect_url);
		}

		if (!self::has_media_bulk_template_input()) {
			return self::get_media_bulk_configuration_url($bulk_action, $attachment_ids, $redirect_url);
		}

		$title_template = isset($_REQUEST['mrn_media_bulk_title_template']) ? trim((string) wp_unslash($_REQUEST['mrn_media_bulk_title_template'])) : '';
		$alt_template = isset($_REQUEST['mrn_media_bulk_alt_template']) ? trim((string) wp_unslash($_REQUEST['mrn_media_bulk_alt_template'])) : '';
		$caption_template = isset($_REQUEST['mrn_media_bulk_caption_template']) ? trim((string) wp_unslash($_REQUEST['mrn_media_bulk_caption_template'])) : '';
		$apply_title = in_array($bulk_action, array('mrn_media_bulk_update_title', 'mrn_media_bulk_update_all'), true);
		$apply_alt = in_array($bulk_action, array('mrn_media_bulk_update_alt', 'mrn_media_bulk_update_all'), true);
		$apply_caption = in_array($bulk_action, array('mrn_media_bulk_update_caption', 'mrn_media_bulk_update_all'), true);

		if ((!$apply_title || '' === $title_template) && (!$apply_alt || '' === $alt_template) && (!$apply_caption || '' === $caption_template)) {
			return add_query_arg('mrn_media_bulk_error', 'no-fields', $redirect_url);
		}

		$updated = 0;
		$skipped = 0;

		foreach ($attachment_ids as $attachment_id) {
			$attachment_id = (int) $attachment_id;
			$attachment = get_post($attachment_id);

			if (!$attachment instanceof WP_Post || 'attachment' !== $attachment->post_type || !current_user_can('edit_post', $attachment_id)) {
				++$skipped;
				continue;
			}

			$post_update = array(
				'ID' => $attachment_id,
			);
			$should_update_post = false;
			$changed = false;

			if ($apply_title && '' !== $title_template) {
				$title_value = self::expand_media_bulk_template($title_template, $attachment_id, 'single');

				if ('' !== $title_value && $title_value !== (string) $attachment->post_title) {
					$post_update['post_title'] = $title_value;
					$should_update_post = true;
				}
			}

			if ($apply_caption && '' !== $caption_template) {
				$caption_value = self::expand_media_bulk_template($caption_template, $attachment_id, 'textarea');

				if ('' !== $caption_value && $caption_value !== (string) $attachment->post_excerpt) {
					$post_update['post_excerpt'] = $caption_value;
					$should_update_post = true;
				}
			}

			if ($should_update_post) {
				$post_update_result = wp_update_post($post_update, true);

				if (is_wp_error($post_update_result)) {
					++$skipped;
					continue;
				}

				$changed = true;
			}

			if ($apply_alt && '' !== $alt_template && self::is_image_attachment($attachment)) {
				$alt_value = self::expand_media_bulk_template($alt_template, $attachment_id, 'single');
				$current_alt = trim((string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true));

				if ('' !== $alt_value && $alt_value !== $current_alt) {
					update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_value);
					$changed = true;
				}
			}

			if ($changed) {
				++$updated;
			} else {
				++$skipped;
			}
		}

		if ('' !== $request_token) {
			self::delete_media_bulk_request_data($request_token);
		}

		return add_query_arg(
			array(
				'mrn_media_bulk_action' => $bulk_action,
				'mrn_media_bulk_updated' => $updated,
				'mrn_media_bulk_skipped' => $skipped,
			),
			$redirect_url
		);
	}

	public static function render_media_bulk_action_notice() {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;

		if (!is_object($screen) || 'upload' !== $screen->id) {
			return;
		}

		if (!empty($_GET['mrn_media_bulk_error'])) {
			$error_code = sanitize_key(wp_unslash($_GET['mrn_media_bulk_error']));
			$message = '';

			if ('no-selection' === $error_code) {
				$message = __('Select one or more media items before running a bulk media action.', 'mrn-media-bulk-tools');
			} elseif ('no-fields' === $error_code) {
				$message = __('Enter at least one title, alt text, or caption template before applying updates.', 'mrn-media-bulk-tools');
			}

			if ('' !== $message) {
				printf('<div class="notice notice-error"><p>%s</p></div>', esc_html($message));
			}

			return;
		}

		if (empty($_GET['mrn_media_bulk_action'])) {
			return;
		}

		$action = sanitize_key(wp_unslash($_GET['mrn_media_bulk_action']));
		$updated = isset($_GET['mrn_media_bulk_updated']) ? absint($_GET['mrn_media_bulk_updated']) : 0;
		$skipped = isset($_GET['mrn_media_bulk_skipped']) ? absint($_GET['mrn_media_bulk_skipped']) : 0;

		if ('mrn_media_bulk_update_title' === $action) {
			$message = sprintf(
				/* translators: %d: number of updated media items. */
				_n('Updated the title on %d media item.', 'Updated the title on %d media items.', $updated, 'mrn-media-bulk-tools'),
				$updated
			);
		} elseif ('mrn_media_bulk_update_alt' === $action) {
			$message = sprintf(
				/* translators: %d: number of updated media items. */
				_n('Updated the alt text on %d media item.', 'Updated the alt text on %d media items.', $updated, 'mrn-media-bulk-tools'),
				$updated
			);
		} elseif ('mrn_media_bulk_update_caption' === $action) {
			$message = sprintf(
				/* translators: %d: number of updated media items. */
				_n('Updated the caption on %d media item.', 'Updated the caption on %d media items.', $updated, 'mrn-media-bulk-tools'),
				$updated
			);
		} else {
			$message = sprintf(
				/* translators: %d: number of updated media items. */
				_n('Updated the title, alt text, and caption on %d media item.', 'Updated the title, alt text, and caption on %d media items.', $updated, 'mrn-media-bulk-tools'),
				$updated
			);
		}

		if ($skipped > 0) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of skipped media items. */
				_n('Skipped %d selected item.', 'Skipped %d selected items.', $skipped, 'mrn-media-bulk-tools'),
				$skipped
			);
		}

		printf('<div class="notice notice-success"><p>%s</p></div>', esc_html($message));
	}

	private static function is_media_library_list_mode() {
		if (isset($_GET['mode'])) {
			return 'grid' !== sanitize_key(wp_unslash($_GET['mode']));
		}

		$mode = get_user_option('media_library_mode');
		$mode = is_string($mode) ? sanitize_key($mode) : '';

		return 'grid' !== $mode;
	}

	private static function get_media_bulk_redirect_url() {
		if (isset($_REQUEST['mrn_media_bulk_redirect'])) {
			$redirect_url = wp_unslash($_REQUEST['mrn_media_bulk_redirect']);
			$redirect_url = is_string($redirect_url) ? self::sanitize_media_bulk_redirect_url($redirect_url) : '';

			if ('' !== $redirect_url) {
				return $redirect_url;
			}
		}

		$redirect_url = wp_get_referer();

		if (!is_string($redirect_url) || '' === $redirect_url) {
			$redirect_url = admin_url('upload.php');
		}

		return self::sanitize_media_bulk_redirect_url($redirect_url);
	}

	private static function sanitize_media_bulk_redirect_url($redirect_url) {
		$redirect_url = wp_validate_redirect($redirect_url, admin_url('upload.php'));

		return remove_query_arg(
			array(
				'mrn_media_bulk_action',
				'mrn_media_bulk_updated',
				'mrn_media_bulk_skipped',
				'mrn_media_bulk_error',
			),
			$redirect_url
		);
	}

	private static function get_media_bulk_configuration_url($bulk_action, array $attachment_ids, $redirect_url) {
		$request_token = self::store_media_bulk_request_data($attachment_ids, $redirect_url);

		if ('' === $request_token) {
			return add_query_arg('mrn_media_bulk_error', 'no-selection', self::sanitize_media_bulk_redirect_url($redirect_url));
		}

		return add_query_arg(
			array(
				'page' => 'mrn-media-bulk-tools',
				'bulk_action' => $bulk_action,
				'mrn_media_bulk_request' => $request_token,
			),
			admin_url('upload.php')
		);
	}

	private static function get_media_bulk_request_transient_key($request_token) {
		$request_token = sanitize_key((string) $request_token);

		if ('' === $request_token) {
			return '';
		}

		return self::REQUEST_TRANSIENT_PREFIX . $request_token;
	}

	private static function store_media_bulk_request_data(array $attachment_ids, $redirect_url) {
		$attachment_ids = wp_parse_id_list($attachment_ids);
		$redirect_url = self::sanitize_media_bulk_redirect_url($redirect_url);

		if (empty($attachment_ids)) {
			return '';
		}

		$request_token = sanitize_key(wp_generate_password(20, false, false));
		$transient_key = self::get_media_bulk_request_transient_key($request_token);

		if ('' === $transient_key) {
			return '';
		}

		set_transient(
			$transient_key,
			array(
				'user_id' => get_current_user_id(),
				'attachment_ids' => $attachment_ids,
				'redirect_url' => $redirect_url,
			),
			15 * MINUTE_IN_SECONDS
		);

		return $request_token;
	}

	private static function get_media_bulk_request_data($request_token) {
		$transient_key = self::get_media_bulk_request_transient_key($request_token);

		if ('' === $transient_key) {
			return array();
		}

		$request_data = get_transient($transient_key);

		if (!is_array($request_data)) {
			return array();
		}

		$user_id = isset($request_data['user_id']) ? (int) $request_data['user_id'] : 0;
		if ($user_id !== get_current_user_id()) {
			return array();
		}

		return $request_data;
	}

	private static function delete_media_bulk_request_data($request_token) {
		$transient_key = self::get_media_bulk_request_transient_key($request_token);

		if ('' !== $transient_key) {
			delete_transient($transient_key);
		}
	}

	private static function get_current_media_bulk_action() {
		$action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '-1';

		if ('-1' === $action && isset($_REQUEST['action2'])) {
			$action = sanitize_key(wp_unslash($_REQUEST['action2']));
		}

		return $action;
	}

	private static function is_custom_media_bulk_action($action) {
		return isset(self::get_media_bulk_action_labels()[$action]);
	}

	private static function has_media_bulk_template_input() {
		return isset($_REQUEST['mrn_media_bulk_title_template']) || isset($_REQUEST['mrn_media_bulk_alt_template']) || isset($_REQUEST['mrn_media_bulk_caption_template']);
	}

	private static function get_media_bulk_action_labels() {
		return array(
			'mrn_media_bulk_update_title' => __('Update title', 'mrn-media-bulk-tools'),
			'mrn_media_bulk_update_alt' => __('Update alt text', 'mrn-media-bulk-tools'),
			'mrn_media_bulk_update_caption' => __('Update caption', 'mrn-media-bulk-tools'),
			'mrn_media_bulk_update_all' => __('Update title, alt text, and caption', 'mrn-media-bulk-tools'),
		);
	}

	private static function expand_media_bulk_template($template, $attachment_id, $context = 'single') {
		$template = (string) $template;

		if ('' === $template) {
			return '';
		}

		$value = strtr($template, self::get_media_bulk_file_tokens($attachment_id));
		$value = 'textarea' === $context ? sanitize_textarea_field($value) : sanitize_text_field($value);

		return trim($value);
	}

	private static function get_media_bulk_file_tokens($attachment_id) {
		$attachment_id = (int) $attachment_id;
		$file = get_attached_file($attachment_id);
		$file = is_string($file) ? $file : '';

		$basename = '' !== $file ? wp_basename($file) : '';
		$extension = '' !== $basename ? (string) pathinfo($basename, PATHINFO_EXTENSION) : '';
		$file_name = '' !== $basename ? (string) pathinfo($basename, PATHINFO_FILENAME) : '';
		$file_title = self::humanize_media_file_name($file_name);
		$mime_type = (string) get_post_mime_type($attachment_id);
		$mime_subtype = '';

		if (false !== strpos($mime_type, '/')) {
			$mime_parts = explode('/', $mime_type, 2);
			$mime_subtype = isset($mime_parts[1]) ? (string) $mime_parts[1] : '';
		}

		return array(
			'{file_name}' => $file_name,
			'{file_title}' => $file_title,
			'{file_basename}' => $basename,
			'{file_extension}' => $extension,
			'{mime_type}' => $mime_type,
			'{mime_subtype}' => $mime_subtype,
		);
	}

	private static function humanize_media_file_name($file_name) {
		$file_name = trim((string) $file_name);

		if ('' === $file_name) {
			return '';
		}

		$file_name = preg_replace('/[\-_]+/', ' ', $file_name);
		$file_name = preg_replace('/\s+/', ' ', (string) $file_name);

		return trim((string) $file_name);
	}

	private static function is_image_attachment($post) {
		if (is_object($post)) {
			$mime_type = (string) ($post->post_mime_type ?? '');
		} elseif (is_array($post)) {
			$mime_type = (string) ($post['post_mime_type'] ?? '');
		} else {
			return false;
		}

		return strpos($mime_type, 'image/') === 0;
	}
}
