<?php
/**
 * Settings Page class file.
 *
 * This file defines the Settings Page for Sybgo plugin configuration.
 *
 * @package Sybgo\Admin
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Sybgo\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Sybgo\Database\DB_Stats;
use Sybgo\Events\Event_Registry;

/**
 * Settings Page class.
 *
 * Manages plugin settings and configuration options.
 *
 * @package Sybgo\Admin
 * @since   1.0.0
 */
class Settings_Page {
	/**
	 * Settings option name.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'sybgo_settings';

	/**
	 * Legacy option name for email recipients (pre-1.0 setting, kept for backward compatibility).
	 *
	 * @var string
	 */
	const LEGACY_OPTION_EMAIL_RECIPIENTS = 'sybgo_email_recipients';

	/**
	 * Default data retention period in days.
	 *
	 * @var int
	 */
	const DEFAULT_RETENTION_DAYS = 90;

	/**
	 * Event registry instance.
	 *
	 * @var Event_Registry
	 */
	private Event_Registry $event_registry;

	/**
	 * DB stats instance.
	 *
	 * @var DB_Stats
	 */
	private DB_Stats $db_stats;

	/**
	 * Get all WordPress option names owned by the plugin.
	 *
	 * Single source of truth for option names, used both during normal operation
	 * and during uninstall cleanup.
	 *
	 * @return array<string> List of WordPress option names.
	 * @since 1.0.0
	 */
	public static function get_option_names(): array {
		return array(
			self::OPTION_NAME,
			self::LEGACY_OPTION_EMAIL_RECIPIENTS,
		);
	}

	/**
	 * Constructor.
	 *
	 * @param Event_Registry $event_registry Event registry.
	 * @param DB_Stats       $db_stats       DB stats instance.
	 */
	public function __construct( Event_Registry $event_registry, DB_Stats $db_stats ) {
		$this->event_registry = $event_registry;
		$this->db_stats       = $db_stats;
	}

	/**
	 * Initialize the settings page.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Add settings page to admin menu.
	 *
	 * @return void
	 */
	public function add_settings_page(): void {
		add_options_page(
			__( 'Sybgo Settings', 'sybgo' ),
			__( 'Sybgo', 'sybgo' ),
			'manage_options',
			'sybgo-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register plugin settings.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		// Register settings group.
		register_setting(
			'sybgo_settings_group',
			self::OPTION_NAME,
			array(
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);

		// Email Settings Section.
		add_settings_section(
			'sybgo_email_section',
			__( 'Email Configuration', 'sybgo' ),
			array( $this, 'render_email_section_description' ),
			'sybgo-settings'
		);

		add_settings_field(
			'email_recipients',
			__( 'Email Recipients', 'sybgo' ),
			array( $this, 'render_email_recipients_field' ),
			'sybgo-settings',
			'sybgo_email_section'
		);

		add_settings_field(
			'from_name',
			__( 'From Name', 'sybgo' ),
			array( $this, 'render_from_name_field' ),
			'sybgo-settings',
			'sybgo_email_section'
		);

		add_settings_field(
			'from_email',
			__( 'From Email', 'sybgo' ),
			array( $this, 'render_from_email_field' ),
			'sybgo-settings',
			'sybgo_email_section'
		);

		// Event Tracking Section.
		add_settings_section(
			'sybgo_tracking_section',
			__( 'Event Tracking', 'sybgo' ),
			array( $this, 'render_tracking_section_description' ),
			'sybgo-settings'
		);

		add_settings_field(
			'enabled_event_types',
			__( 'Enabled Event Types', 'sybgo' ),
			array( $this, 'render_enabled_event_types_field' ),
			'sybgo-settings',
			'sybgo_tracking_section'
		);

		add_settings_field(
			'edit_magnitude_threshold',
			__( 'Edit Magnitude Threshold', 'sybgo' ),
			array( $this, 'render_edit_threshold_field' ),
			'sybgo-settings',
			'sybgo_tracking_section'
		);

		// Report Settings Section.
		add_settings_section(
			'sybgo_report_section',
			__( 'Report Settings', 'sybgo' ),
			array( $this, 'render_report_section_description' ),
			'sybgo-settings'
		);

		add_settings_field(
			'send_empty_reports',
			__( 'Send Empty Reports', 'sybgo' ),
			array( $this, 'render_send_empty_reports_field' ),
			'sybgo-settings',
			'sybgo_report_section'
		);

		// AI Settings Section.
		add_settings_section(
			'sybgo_ai_section',
			__( 'AI Summary Settings', 'sybgo' ),
			array( $this, 'render_ai_section_description' ),
			'sybgo-settings'
		);

		add_settings_field(
			'anthropic_api_key',
			__( 'Anthropic API Key', 'sybgo' ),
			array( $this, 'render_anthropic_api_key_field' ),
			'sybgo-settings',
			'sybgo_ai_section'
		);

		// Database Management Section.
		add_settings_section(
			'sybgo_database_section',
			__( 'Database Management', 'sybgo' ),
			array( $this, 'render_database_section_description' ),
			'sybgo-settings'
		);

		add_settings_field(
			'retention_days',
			__( 'Data Retention Period', 'sybgo' ),
			array( $this, 'render_retention_days_field' ),
			'sybgo-settings',
			'sybgo_database_section'
		);
	}

	/**
	 * Sanitize settings before saving.
	 *
	 * @param array<string, mixed> $input Raw input data.
	 * @return array<string, mixed> Sanitized data.
	 */
	public function sanitize_settings( array $input ): array {
		$sanitized = array();

		// Sanitize email recipients.
		if ( isset( $input['email_recipients'] ) ) {
			$recipients = array();
			$lines      = explode( "\n", $input['email_recipients'] );

			foreach ( $lines as $line ) {
				$email = sanitize_email( trim( $line ) );
				if ( is_email( $email ) ) {
					$recipients[] = $email;
				}
			}

			$sanitized['email_recipients'] = implode( "\n", $recipients );
		}

		// Sanitize from name and email.
		$sanitized['from_name']  = isset( $input['from_name'] ) ? sanitize_text_field( $input['from_name'] ) : '';
		$sanitized['from_email'] = isset( $input['from_email'] ) ? sanitize_email( $input['from_email'] ) : '';

		// Sanitize enabled event types.
		if ( isset( $input['enabled_event_types'] ) && is_array( $input['enabled_event_types'] ) ) {
			$sanitized['enabled_event_types'] = array_map( 'sanitize_text_field', $input['enabled_event_types'] );
		} else {
			$sanitized['enabled_event_types'] = array();
		}

		// Sanitize edit threshold.
		$sanitized['edit_magnitude_threshold'] = isset( $input['edit_magnitude_threshold'] ) ? absint( $input['edit_magnitude_threshold'] ) : 5;
		$sanitized['edit_magnitude_threshold'] = min( 100, max( 0, $sanitized['edit_magnitude_threshold'] ) );

		// Sanitize boolean settings.
		$sanitized['send_empty_reports'] = isset( $input['send_empty_reports'] );

		// Sanitize AI API key.
		$sanitized['anthropic_api_key'] = isset( $input['anthropic_api_key'] ) ? sanitize_text_field( trim( $input['anthropic_api_key'] ) ) : '';

		// Sanitize retention days.
		$sanitized['retention_days'] = isset( $input['retention_days'] ) ? absint( $input['retention_days'] ) : self::DEFAULT_RETENTION_DAYS;
		$sanitized['retention_days'] = max( 1, $sanitized['retention_days'] );

		return $sanitized;
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Check if settings were saved.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WordPress core adds this param after settings save; nonce verified by options.php.
		if ( isset( $_GET['settings-updated'] ) ) {
			add_settings_error(
				'sybgo_messages',
				'sybgo_message',
				__( 'Settings saved successfully.', 'sybgo' ),
				'updated'
			);
		}

		// Check if cleanup was run.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display, no state change.
		if ( isset( $_GET['cleanup-done'] ) ) {
			$deleted = absint( $_GET['cleanup-done'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of cleanup result count; no state change occurs here.
			add_settings_error(
				'sybgo_messages',
				'sybgo_cleanup_done',
				sprintf(
					/* translators: %d: number of rows deleted */
					_n( 'Cleanup complete: %d row deleted.', 'Cleanup complete: %d rows deleted.', $deleted, 'sybgo' ),
					$deleted
				),
				'updated'
			);
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php settings_errors( 'sybgo_messages' ); ?>

			<form action="options.php" method="post">
				<?php
				settings_fields( 'sybgo_settings_group' );
				do_settings_sections( 'sybgo-settings' );
				submit_button( __( 'Save Settings', 'sybgo' ) );
				?>
			</form>

			<?php $this->render_database_stats_panel(); ?>

			<div class="sybgo-settings-help">
				<h2><?php esc_html_e( 'Quick Help', 'sybgo' ); ?></h2>
				<ul>
					<li><strong><?php esc_html_e( 'Email Recipients:', 'sybgo' ); ?></strong> <?php esc_html_e( 'Enter one email address per line. Weekly digests will be sent to all addresses.', 'sybgo' ); ?></li>
					<li><strong><?php esc_html_e( 'Event Types:', 'sybgo' ); ?></strong> <?php esc_html_e( 'Disable event types you don\'t want to track. Useful for reducing noise.', 'sybgo' ); ?></li>
					<li><strong><?php esc_html_e( 'Edit Threshold:', 'sybgo' ); ?></strong> <?php esc_html_e( 'Only track edits that change at least this percentage of content (0-100%).', 'sybgo' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Render email section description.
	 *
	 * @return void
	 */
	public function render_email_section_description(): void {
		?>
		<p><?php esc_html_e( 'Configure who receives the weekly activity digest emails.', 'sybgo' ); ?></p>
		<?php
	}

	/**
	 * Render tracking section description.
	 *
	 * @return void
	 */
	public function render_tracking_section_description(): void {
		?>
		<p><?php esc_html_e( 'Choose which events to track and how sensitive tracking should be.', 'sybgo' ); ?></p>
		<?php
	}

	/**
	 * Render report section description.
	 *
	 * @return void
	 */
	public function render_report_section_description(): void {
		?>
		<p><?php esc_html_e( 'Configure report generation and delivery behavior.', 'sybgo' ); ?></p>
		<?php
	}

	/**
	 * Render email recipients field.
	 *
	 * @return void
	 */
	public function render_email_recipients_field(): void {
		$settings   = $this->get_settings();
		$recipients = $settings['email_recipients'] ?? get_option( 'admin_email' );

		?>
		<textarea
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[email_recipients]"
			rows="5"
			cols="50"
			class="large-text code"
		><?php echo esc_textarea( $recipients ); ?></textarea>
		<p class="description">
			<?php esc_html_e( 'Enter one email address per line. Leave blank to use admin email.', 'sybgo' ); ?>
		</p>
		<?php
	}

	/**
	 * Render from name field.
	 *
	 * @return void
	 */
	public function render_from_name_field(): void {
		$settings  = $this->get_settings();
		$from_name = $settings['from_name'] ?? get_bloginfo( 'name' );

		?>
		<input
			type="text"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[from_name]"
			value="<?php echo esc_attr( $from_name ); ?>"
			class="regular-text"
		/>
		<p class="description">
			<?php esc_html_e( 'Name shown in the "From" field of emails.', 'sybgo' ); ?>
		</p>
		<?php
	}

	/**
	 * Render from email field.
	 *
	 * @return void
	 */
	public function render_from_email_field(): void {
		$settings   = $this->get_settings();
		$from_email = $settings['from_email'] ?? get_option( 'admin_email' );

		?>
		<input
			type="email"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[from_email]"
			value="<?php echo esc_attr( $from_email ); ?>"
			class="regular-text"
		/>
		<p class="description">
			<?php esc_html_e( 'Email address shown in the "From" field of emails.', 'sybgo' ); ?>
		</p>
		<?php
	}

	/**
	 * Render enabled event types field.
	 *
	 * @return void
	 */
	public function render_enabled_event_types_field(): void {
		$settings = $this->get_settings();
		$enabled  = $settings['enabled_event_types'] ?? $this->get_default_event_types();

		// Build event types dynamically from registry.
		$event_types = array();
		foreach ( $this->event_registry->get_registered_types() as $type ) {
			$event_types[ $type ] = $this->event_registry->get_stat_label( $type );
		}

		?>
		<fieldset>
			<?php foreach ( $event_types as $type => $label ) : ?>
				<label>
					<input
						type="checkbox"
						name="<?php echo esc_attr( self::OPTION_NAME ); ?>[enabled_event_types][]"
						value="<?php echo esc_attr( $type ); ?>"
						<?php checked( in_array( $type, $enabled, true ) ); ?>
					/>
					<?php echo esc_html( $label ); ?>
				</label>
				<br>
			<?php endforeach; ?>
		</fieldset>
		<p class="description">
			<?php esc_html_e( 'Select which event types should be tracked.', 'sybgo' ); ?>
		</p>
		<?php
	}

	/**
	 * Render edit threshold field.
	 *
	 * @return void
	 */
	public function render_edit_threshold_field(): void {
		$settings  = $this->get_settings();
		$threshold = $settings['edit_magnitude_threshold'] ?? 5;

		?>
		<input
			type="number"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[edit_magnitude_threshold]"
			value="<?php echo esc_attr( $threshold ); ?>"
			min="0"
			max="100"
			step="1"
			class="small-text"
		/> %
		<p class="description">
			<?php esc_html_e( 'Minimum percentage of content change to track an edit (0-100%). Default: 5%.', 'sybgo' ); ?>
		</p>
		<?php
	}

	/**
	 * Render send empty reports field.
	 *
	 * @return void
	 */
	public function render_send_empty_reports_field(): void {
		$settings   = $this->get_settings();
		$send_empty = $settings['send_empty_reports'] ?? false;

		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[send_empty_reports]"
				value="1"
				<?php checked( $send_empty ); ?>
			/>
			<?php esc_html_e( 'Send weekly digest even if no events occurred', 'sybgo' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Enable to receive "All quiet" emails when there\'s no activity.', 'sybgo' ); ?>
		</p>
		<?php
	}

	/**
	 * Get current settings.
	 *
	 * @return array<string, mixed> Settings array.
	 */
	public function get_settings(): array {
		$settings = get_option( self::OPTION_NAME, array() );

		// Set defaults if empty.
		$defaults = array(
			'email_recipients'         => get_option( 'admin_email' ),
			'from_name'                => get_bloginfo( 'name' ),
			'from_email'               => get_option( 'admin_email' ),
			'enabled_event_types'      => $this->get_default_event_types(),
			'edit_magnitude_threshold' => 5,
			'send_empty_reports'       => false,
			'retention_days'           => self::DEFAULT_RETENTION_DAYS,
		);

		return wp_parse_args( $settings, $defaults );
	}

	/**
	 * Get default event types (all enabled).
	 *
	 * @return array<int, string> Event types.
	 */
	private function get_default_event_types(): array {
		return array(
			'post_published',
			'post_edited',
			'post_deleted',
			'user_registered',
			'user_role_changed',
			'core_updated',
			'plugin_updated',
			'theme_updated',
			'comment_posted',
			'comment_approved',
		);
	}

	/**
	 * Get email recipients as array.
	 *
	 * @return array<int, string> Email addresses.
	 */
	public static function get_recipients(): array {
		$settings   = get_option( self::OPTION_NAME, array() );
		$recipients = $settings['email_recipients'] ?? get_option( 'admin_email' );

		if ( empty( $recipients ) ) {
			return array( get_option( 'admin_email' ) );
		}

		$emails = array();
		$lines  = explode( "\n", $recipients );

		foreach ( $lines as $line ) {
			$email = trim( $line );
			if ( is_email( $email ) ) {
				$emails[] = $email;
			}
		}

		return ! empty( $emails ) ? $emails : array( get_option( 'admin_email' ) );
	}

	/**
	 * Check if an event type is enabled.
	 *
	 * @param string $event_type Event type to check.
	 * @return bool True if enabled, false otherwise.
	 */
	public static function is_event_type_enabled( string $event_type ): bool {
		$settings = get_option( self::OPTION_NAME, array() );
		$enabled  = $settings['enabled_event_types'] ?? array();

		// If no settings, assume all enabled.
		if ( empty( $enabled ) ) {
			return true;
		}

		return in_array( $event_type, $enabled, true );
	}

	/**
	 * Get edit magnitude threshold.
	 *
	 * @return int Threshold percentage (0-100).
	 */
	public static function get_edit_threshold(): int {
		$settings = get_option( self::OPTION_NAME, array() );
		return $settings['edit_magnitude_threshold'] ?? 5;
	}

	/**
	 * Render AI section description.
	 *
	 * @return void
	 */
	public function render_ai_section_description(): void {
		?>
		<p>
			<?php
			echo wp_kses_post(
				sprintf(
					/* translators: %s: link to Anthropic console */
					__( 'Enable AI-powered summaries using Claude. Get your API key from <a href="%s" target="_blank" rel="noopener">Anthropic Console</a>.', 'sybgo' ),
					'https://console.anthropic.com/settings/keys'
				)
			);
			?>
		</p>
		<p>
			<strong><?php esc_html_e( 'Privacy Note:', 'sybgo' ); ?></strong>
			<?php esc_html_e( 'Event data (post titles, plugin names, etc.) is sent to Anthropic\'s API to generate summaries.', 'sybgo' ); ?>
		</p>
		<?php
	}

	/**
	 * Render Anthropic API key field.
	 *
	 * @return void
	 */
	public function render_anthropic_api_key_field(): void {
		$settings = $this->get_settings();
		$api_key  = $settings['anthropic_api_key'] ?? '';

		?>
		<input
			type="password"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[anthropic_api_key]"
			id="sybgo_anthropic_api_key"
			value="<?php echo esc_attr( $api_key ); ?>"
			class="regular-text"
			placeholder="sk-ant-..."
			autocomplete="off"
		/>
		<p class="description">
			<?php esc_html_e( 'Your Anthropic API key. When configured, AI summaries will appear in email digests and preview.', 'sybgo' ); ?>
		</p>
		<?php
	}

	/**
	 * Get Anthropic API key.
	 *
	 * @return string API key or empty string if not set.
	 */
	public static function get_anthropic_api_key(): string {
		$settings = get_option( self::OPTION_NAME, array() );
		return $settings['anthropic_api_key'] ?? '';
	}

	/**
	 * Render database section description.
	 *
	 * @return void
	 */
	public function render_database_section_description(): void {
		?>
		<p><?php esc_html_e( 'Control how long event data is retained in the database and monitor storage usage.', 'sybgo' ); ?></p>
		<?php
	}

	/**
	 * Render retention days field.
	 *
	 * @return void
	 */
	public function render_retention_days_field(): void {
		$settings       = $this->get_settings();
		$retention_days = $settings['retention_days'] ?? self::DEFAULT_RETENTION_DAYS;

		?>
		<input
			type="number"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[retention_days]"
			value="<?php echo esc_attr( $retention_days ); ?>"
			min="1"
			step="1"
			class="small-text"
		/> <?php esc_html_e( 'days', 'sybgo' ); ?>
		<p class="description">
			<?php esc_html_e( 'Events and aggregated data older than this number of days will be deleted during cleanup. Default: 90 days.', 'sybgo' ); ?>
		</p>
		<?php
	}

	/**
	 * Render database stats panel with per-table row counts and sizes.
	 *
	 * @return void
	 */
	public function render_database_stats_panel(): void {
		$stats    = $this->db_stats->get_table_stats();
		$total_mb = $this->db_stats->get_total_size_mb();

		?>
		<div class="sybgo-db-stats">
			<h2><?php esc_html_e( 'Database Footprint', 'sybgo' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Table', 'sybgo' ); ?></th>
						<th><?php esc_html_e( 'Rows', 'sybgo' ); ?></th>
						<th><?php esc_html_e( 'Estimated Size', 'sybgo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $stats as $table_stats ) : ?>
						<tr>
							<td><code><?php echo esc_html( $table_stats['table_name'] ); ?></code></td>
							<td><?php echo esc_html( number_format_i18n( $table_stats['row_count'] ) ); ?></td>
							<td>
								<?php
								if ( null !== $table_stats['size_mb'] ) {
									/* translators: %s: table size in MB */
									echo esc_html( sprintf( __( '%s MB', 'sybgo' ), number_format_i18n( $table_stats['size_mb'], 2 ) ) );
								} else {
									esc_html_e( 'N/A', 'sybgo' );
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr>
						<td colspan="2"><strong><?php esc_html_e( 'Total', 'sybgo' ); ?></strong></td>
						<td>
							<strong>
								<?php
								/* translators: %s: total size in MB */
								echo esc_html( sprintf( __( '%s MB', 'sybgo' ), number_format_i18n( $total_mb, 2 ) ) );
								?>
							</strong>
						</td>
					</tr>
				</tfoot>
			</table>

			<p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'sybgo_run_cleanup', 'sybgo_cleanup_nonce' ); ?>
					<input type="hidden" name="action" value="sybgo_run_cleanup">
					<button type="submit" class="button button-secondary">
						<?php esc_html_e( 'Run Cleanup Now', 'sybgo' ); ?>
					</button>
					<span class="description">
						<?php esc_html_e( 'Immediately delete all events and aggregated data older than the configured retention period.', 'sybgo' ); ?>
					</span>
				</form>
			</p>
		</div>
		<?php
	}

	/**
	 * Get data retention period in days.
	 *
	 * @return int Retention period in days.
	 * @since 1.1.0
	 */
	public static function get_retention_days(): int {
		$settings = get_option( self::OPTION_NAME, array() );
		return $settings['retention_days'] ?? self::DEFAULT_RETENTION_DAYS;
	}
}
