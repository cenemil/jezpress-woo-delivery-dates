<?php
/**
 * JWDD License Handler
 *
 * Handles license validation, activation, and deactivation.
 * Integrates with JezPress licensing system with anti-nulling protections.
 *
 * Based on the JezPress Secure License Handler pattern.
 *
 * @package Jezpress_Woo_Delivery_Dates
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JWDD_License {

	/** @var string JezPress License API URL. */
	private $api_url = 'https://updates.jezpress.com';

	/** @var string Plugin slug. */
	private $slug;

	/** @var string Plugin name for display. */
	private $plugin_name;

	/** @var string Plugin file path. */
	private $plugin_file;

	/** @var string Option name for storing license data. */
	private $option_name;

	/** @var string Menu slug for the license settings page. */
	private $menu_slug;

	/** @var bool Whether license is required for plugin to work. */
	private $required = true;

	/** @var int Cache duration in seconds (12 hours). */
	private $cache_duration = 43200;

	/** @var array|null Cached license data. */
	private $license_data = null;

	/** @var string Security salt for data encryption. */
	private $security_salt;

	/** @var JWDD_License|null Singleton instance. */
	private static $instance = null;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_file Plugin main file path.
	 * @param string $slug        Plugin slug on JezPress.
	 * @param string $plugin_name Plugin display name.
	 */
	public function __construct( $plugin_file, $slug, $plugin_name ) {
		$this->plugin_file   = $plugin_file;
		$this->slug          = sanitize_title( $slug );
		$this->plugin_name   = sanitize_text_field( $plugin_name );
		$this->option_name   = 'jzwb_lic_' . substr( md5( $this->slug ), 0, 8 );
		$this->menu_slug     = 'jwdd-delivery-dates';
		$this->security_salt = $this->generate_site_salt();
	}

	/**
	 * Get singleton instance.
	 *
	 * @param string $plugin_file Plugin main file path.
	 * @param string $slug        Plugin slug on JezPress.
	 * @param string $plugin_name Plugin display name.
	 * @return JWDD_License
	 */
	public static function get_instance( $plugin_file = '', $slug = '', $plugin_name = '' ) {
		if ( null === self::$instance && $plugin_file ) {
			self::$instance = new self( $plugin_file, $slug, $plugin_name );
		}
		return self::$instance;
	}

	/**
	 * Generate site-specific salt for encryption.
	 *
	 * @return string
	 */
	private function generate_site_salt() {
		$components = array(
			defined( 'AUTH_KEY' ) ? AUTH_KEY : 'jzwb',
			$this->slug,
			wp_parse_url( home_url(), PHP_URL_HOST ),
		);
		return hash( 'sha256', implode( '|', $components ) );
	}

	/**
	 * Initialize license handler — registers admin hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_init',    array( $this, 'handle_license_actions' ) );
		add_action( 'admin_init',    array( $this, 'verify_integrity' ), 1 );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );

		add_filter( 'plugin_action_links_' . plugin_basename( $this->plugin_file ), array( $this, 'plugin_action_links' ) );

		add_action( 'jwdd_license_check', array( $this, 'scheduled_license_check' ) );

		if ( ! wp_next_scheduled( 'jwdd_license_check' ) ) {
			wp_schedule_event( time(), 'daily', 'jwdd_license_check' );
		}
	}

	/**
	 * Verify plugin integrity on admin init.
	 *
	 * @return void
	 */
	public function verify_integrity() {
		if ( ! $this->verify_source() ) {
			$this->invalidate_license();
		}

		if ( ! $this->verify_stored_data() ) {
			$this->invalidate_license();
		}
	}

	/**
	 * Verify plugin source is from JezPress.
	 *
	 * @return bool
	 */
	private function verify_source() {
		$marker_file = dirname( $this->plugin_file ) . '/.jezpress';

		if ( ! file_exists( $marker_file ) ) {
			$update_check = get_site_transient( 'update_plugins' );
			if ( $update_check && isset( $update_check->response[ plugin_basename( $this->plugin_file ) ] ) ) {
				@file_put_contents( $marker_file, $this->generate_source_signature() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}

		return true; // Allow for initial installation.
	}

	/**
	 * Generate source signature.
	 *
	 * @return string
	 */
	private function generate_source_signature() {
		return hash( 'sha256', $this->slug . '|' . ( defined( 'JWDD_VERSION' ) ? JWDD_VERSION : '1.0.0' ) . '|jezpress' );
	}

	/**
	 * Verify stored license data integrity.
	 *
	 * @return bool
	 */
	private function verify_stored_data() {
		$data = get_option( $this->option_name );

		if ( empty( $data ) ) {
			return true;
		}

		if ( ! isset( $data['_sig'] ) ) {
			return false;
		}

		if ( isset( $data['_enc'] ) && $data['_enc'] ) {
			$data = $this->decrypt_license_data( $data );
		}

		$expected_sig = $this->generate_data_signature( $data );
		return hash_equals( $expected_sig, $data['_sig'] );
	}

	/**
	 * Generate data signature for integrity check.
	 *
	 * @param array $data License data.
	 * @return string
	 */
	private function generate_data_signature( $data ) {
		$to_sign = array(
			isset( $data['key'] ) ? $data['key'] : '',
			isset( $data['status'] ) ? $data['status'] : '',
			isset( $data['domain'] ) ? $data['domain'] : '',
			$this->security_salt,
		);
		return hash( 'sha256', implode( '|', $to_sign ) );
	}

	/**
	 * Invalidate and clear stored license data.
	 *
	 * @return void
	 */
	private function invalidate_license() {
		delete_option( $this->option_name );
		$this->license_data = null;
	}

	/**
	 * Check if license is valid.
	 *
	 * @param bool $force_check Force remote check.
	 * @return bool True if license is valid.
	 */
	public function is_valid( $force_check = false ) {
		$license_data = $this->get_license_data( $force_check );

		if ( empty( $license_data ) || empty( $license_data['key'] ) ) {
			return false;
		}

		if ( ! isset( $license_data['status'] ) || 'active' !== $license_data['status'] ) {
			return false;
		}

		if ( isset( $license_data['domain'] ) ) {
			$current_domain = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( $license_data['domain'] !== $current_domain ) {
				return false;
			}
		}

		if ( ! $this->verify_stored_data() ) {
			return false;
		}

		if ( isset( $license_data['expires'] ) && 'lifetime' !== $license_data['expires'] ) {
			$expires = strtotime( $license_data['expires'] );
			if ( $expires && $expires < time() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get stored license key.
	 *
	 * @return string License key or empty string.
	 */
	public function get_license_key() {
		$license_data = $this->get_license_data();
		return isset( $license_data['key'] ) ? $license_data['key'] : '';
	}

	/**
	 * Get license data from database.
	 *
	 * @param bool $force_check Force remote validation.
	 * @return array License data.
	 */
	public function get_license_data( $force_check = false ) {
		if ( null !== $this->license_data && ! $force_check ) {
			return $this->license_data;
		}

		$this->license_data = get_option( $this->option_name, array() );

		if ( isset( $this->license_data['_enc'] ) && $this->license_data['_enc'] ) {
			$this->license_data = $this->decrypt_license_data( $this->license_data );
		}

		if ( $force_check || $this->needs_revalidation() ) {
			$this->validate_license_remote();
		}

		return $this->license_data;
	}

	/**
	 * Check if license needs revalidation.
	 *
	 * @return bool
	 */
	private function needs_revalidation() {
		if ( empty( $this->license_data['key'] ) ) {
			return false;
		}

		$last_check = isset( $this->license_data['last_check'] ) ? $this->license_data['last_check'] : 0;
		return ( time() - $last_check ) > $this->cache_duration;
	}

	/**
	 * Encrypt license data for storage.
	 *
	 * @param array $data License data.
	 * @return array Encrypted data.
	 */
	private function encrypt_license_data( $data ) {
		$key_to_encrypt = isset( $data['key'] ) ? $data['key'] : '';

		if ( $key_to_encrypt ) {
			$data['key']  = base64_encode( $this->xor_encrypt( $key_to_encrypt ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			$data['_enc'] = true;
		}

		return $data;
	}

	/**
	 * Decrypt license data.
	 *
	 * @param array $data Encrypted license data.
	 * @return array Decrypted data.
	 */
	private function decrypt_license_data( $data ) {
		if ( isset( $data['key'] ) && isset( $data['_enc'] ) && $data['_enc'] ) {
			$data['key'] = $this->xor_encrypt( base64_decode( $data['key'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			unset( $data['_enc'] );
		}
		return $data;
	}

	/**
	 * Simple XOR encryption/decryption.
	 *
	 * @param string $data Data to encrypt/decrypt.
	 * @return string Result.
	 */
	private function xor_encrypt( $data ) {
		$key    = $this->security_salt;
		$result = '';

		for ( $i = 0; $i < strlen( $data ); $i++ ) {
			$result .= $data[ $i ] ^ $key[ $i % strlen( $key ) ];
		}

		return $result;
	}

	/**
	 * Activate license.
	 *
	 * @param string $license_key License key.
	 * @return array Result with 'success' and 'message'.
	 */
	public function activate( $license_key ) {
		$license_key = $this->sanitize_license_key( $license_key );

		if ( empty( $license_key ) ) {
			return array(
				'success' => false,
				'message' => __( 'Please enter a valid license key.', 'jezpress-woo-delivery-dates' ),
			);
		}

		$response = $this->api_request( 'activate', $license_key );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
			);
		}

		if ( isset( $response->success ) && $response->success ) {
			$current_domain = wp_parse_url( home_url(), PHP_URL_HOST );

			$this->license_data = array(
				'key'         => $license_key,
				'status'      => 'active',
				'domain'      => $current_domain,
				'customer'    => isset( $response->customer ) ? sanitize_text_field( $response->customer ) : '',
				'email'       => isset( $response->email ) ? sanitize_email( $response->email ) : '',
				'expires'     => isset( $response->expires ) ? sanitize_text_field( $response->expires ) : 'lifetime',
				'activations' => isset( $response->activations ) ? absint( $response->activations ) : 0,
				'limit'       => isset( $response->limit ) ? ( 'unlimited' === $response->limit ? 'unlimited' : absint( $response->limit ) ) : 0,
				'last_check'  => time(),
				'activated'   => time(),
			);

			$this->license_data['_sig'] = $this->generate_data_signature( $this->license_data );
			$data_to_save               = $this->encrypt_license_data( $this->license_data );
			update_option( $this->option_name, $data_to_save );

			return array(
				'success' => true,
				'message' => __( 'License activated successfully!', 'jezpress-woo-delivery-dates' ),
			);
		}

		$error_message = isset( $response->message ) ? $response->message : __( 'License activation failed.', 'jezpress-woo-delivery-dates' );

		return array(
			'success' => false,
			'message' => $error_message,
		);
	}

	/**
	 * Deactivate license.
	 *
	 * @return array Result with 'success' and 'message'.
	 */
	public function deactivate() {
		$license_key = $this->get_license_key();

		if ( empty( $license_key ) ) {
			return array(
				'success' => false,
				'message' => __( 'No license key to deactivate.', 'jezpress-woo-delivery-dates' ),
			);
		}

		$this->api_request( 'deactivate', $license_key );

		// Clear local data regardless of API response.
		$this->license_data = array();
		delete_option( $this->option_name );

		return array(
			'success' => true,
			'message' => __( 'License deactivated successfully.', 'jezpress-woo-delivery-dates' ),
		);
	}

	/**
	 * Validate license remotely.
	 *
	 * @return bool True if valid.
	 */
	private function validate_license_remote() {
		$license_key = isset( $this->license_data['key'] ) ? $this->license_data['key'] : '';

		if ( empty( $license_key ) ) {
			return false;
		}

		$response = $this->api_request( 'validate', $license_key );

		if ( is_wp_error( $response ) ) {
			// Keep existing status on network error, but update last check time.
			$this->license_data['last_check'] = time();
			$this->license_data['_sig']       = $this->generate_data_signature( $this->license_data );
			update_option( $this->option_name, $this->encrypt_license_data( $this->license_data ) );
			return isset( $this->license_data['status'] ) && 'active' === $this->license_data['status'];
		}

		if ( isset( $response->valid ) && $response->valid ) {
			$this->license_data['status']      = 'active';
			$this->license_data['last_check']  = time();
			$this->license_data['activations'] = isset( $response->activations ) ? absint( $response->activations ) : 0;
			$this->license_data['expires']     = isset( $response->expires ) ? sanitize_text_field( $response->expires ) : 'lifetime';
		} else {
			$this->license_data['status']     = 'inactive';
			$this->license_data['last_check'] = time();
		}

		$this->license_data['_sig'] = $this->generate_data_signature( $this->license_data );
		update_option( $this->option_name, $this->encrypt_license_data( $this->license_data ) );

		return 'active' === $this->license_data['status'];
	}

	/**
	 * Make API request to the JezPress license endpoint.
	 *
	 * @param string $action      API action (activate, deactivate, validate).
	 * @param string $license_key License key.
	 * @return object|WP_Error API response or error.
	 */
	private function api_request( $action, $license_key ) {
		$url = sprintf(
			'%s/api/v1/license/%s',
			$this->api_url,
			sanitize_key( $action )
		);

		$plugin_ver = defined( 'JWDD_VERSION' ) ? JWDD_VERSION : '1.0.0';

		$body = array(
			'license_key' => $license_key,
			'plugin'      => $this->slug,
			'site_url'    => home_url(),
			'site_name'   => get_bloginfo( 'name' ),
			'wp_version'  => get_bloginfo( 'version' ),
			'php_version' => PHP_VERSION,
			'plugin_ver'  => $plugin_ver,
		);

		$args = array(
			'timeout'   => 15,
			'sslverify' => true,
			'headers'   => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
				'User-Agent'   => 'JezPress/' . $plugin_ver . '; ' . home_url(),
			),
			'body'      => wp_json_encode( $body ),
		);

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'api_error',
				/* translators: %d: HTTP status code */
				sprintf( __( 'License server returned error code: %d', 'jezpress-woo-delivery-dates' ), $code )
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'json_error', __( 'Invalid response from license server.', 'jezpress-woo-delivery-dates' ) );
		}

		return $data;
	}

	/**
	 * Sanitize license key input.
	 *
	 * @param string $key License key.
	 * @return string Sanitized key.
	 */
	private function sanitize_license_key( $key ) {
		$key = strtoupper( trim( $key ) );
		$key = preg_replace( '/[^A-Z0-9\-]/', '', $key );
		return $key;
	}

	/**
	 * Handle license activate/deactivate form submissions.
	 *
	 * @return void
	 */
	public function handle_license_actions() {
		$this->check_transient_message();

		if ( ! isset( $_POST['jezweb_license_action'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$action = sanitize_key( $_POST['jezweb_license_action'] );

		if ( ! isset( $_POST['jezweb_license_nonce'] ) ||
			 ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['jezweb_license_nonce'] ) ), 'jezweb_license_' . $action ) ) {
			add_settings_error( $this->menu_slug, 'nonce_error', __( 'Security check failed.', 'jezpress-woo-delivery-dates' ), 'error' );
			return;
		}

		if ( ! isset( $_POST['jezweb_license_slug'] ) || $_POST['jezweb_license_slug'] !== $this->slug ) {
			return;
		}

		if ( 'activate' === $action ) {
			$license_key = isset( $_POST['jezweb_license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['jezweb_license_key'] ) ) : '';
			$result      = $this->activate( $license_key );

			if ( $result['success'] ) {
				set_transient( 'jezweb_license_message_' . $this->slug, array(
					'type'    => 'success',
					'message' => $result['message'],
				), 30 );
				wp_safe_redirect( admin_url( 'admin.php?page=jwdd-delivery-dates&tab=license&activated=1' ) );
				exit;
			} else {
				add_settings_error( $this->menu_slug, 'activation_failed', $result['message'], 'error' );
			}
		} elseif ( 'deactivate' === $action ) {
			$result = $this->deactivate();

			if ( $result['success'] ) {
				set_transient( 'jezweb_license_message_' . $this->slug, array(
					'type'    => 'success',
					'message' => $result['message'],
				), 30 );
				wp_safe_redirect( admin_url( 'admin.php?page=jwdd-delivery-dates&tab=license&deactivated=1' ) );
				exit;
			} else {
				add_settings_error( $this->menu_slug, 'deactivation_failed', $result['message'], 'error' );
			}
		}
	}

	/**
	 * Check for transient message after redirect.
	 *
	 * @return void
	 */
	private function check_transient_message() {
		$transient_message = get_transient( 'jezweb_license_message_' . $this->slug );
		if ( $transient_message ) {
			delete_transient( 'jezweb_license_message_' . $this->slug );
			add_settings_error(
				$this->menu_slug,
				'success' === $transient_message['type'] ? 'license_success' : 'license_error',
				$transient_message['message'],
				$transient_message['type']
			);
		}
	}

	/**
	 * Display admin notice when license is missing.
	 *
	 * @return void
	 */
	public function admin_notices() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// Suppress on the JWDD page — the License tab shows status inline.
		if ( false !== strpos( $screen->id, 'jwdd-delivery-dates' ) ) {
			return;
		}

		if ( $this->required && ! $this->is_valid() ) {
			$license_url = admin_url( 'admin.php?page=jwdd-delivery-dates&tab=license' );
			?>
			<div class="notice notice-error">
				<p>
					<strong><?php echo esc_html( $this->plugin_name ); ?>:</strong>
					<?php esc_html_e( 'License activation required to enable plugin features.', 'jezpress-woo-delivery-dates' ); ?>
					<a href="<?php echo esc_url( $license_url ); ?>">
						<?php esc_html_e( 'Activate License', 'jezpress-woo-delivery-dates' ); ?>
					</a>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Add Settings and License links to plugin action links.
	 *
	 * @param array $links Plugin action links.
	 * @return array Modified links.
	 */
	public function plugin_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			admin_url( 'admin.php?page=jwdd-delivery-dates&tab=settings' ),
			__( 'Settings', 'jezpress-woo-delivery-dates' )
		);
		$license_link = sprintf(
			'<a href="%s">%s</a>',
			admin_url( 'admin.php?page=jwdd-delivery-dates&tab=license' ),
			__( 'License', 'jezpress-woo-delivery-dates' )
		);

		array_unshift( $links, $license_link );
		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Render the license tab content (no page wrap — embedded in the JWDD admin tab).
	 *
	 * @return void
	 */
	public function render_tab_content() {
		$license_data = $this->get_license_data();
		$is_active    = $this->is_valid();
		$license_key  = isset( $license_data['key'] ) ? $license_data['key'] : '';
		?>
		<div style="max-width:800px; margin-top:20px;">

			<?php settings_errors( $this->menu_slug ); ?>

			<div style="background:#fff; border:1px solid #c3c4c7; padding:20px 24px; margin-top:16px;">
				<h2>
					<?php esc_html_e( 'License Status', 'jezpress-woo-delivery-dates' ); ?>
					<?php if ( $is_active ) : ?>
						<span style="display:inline-block;padding:2px 10px;border-radius:3px;font-size:12px;font-weight:600;background:#d1fae5;color:#065f46;margin-left:8px;"><?php esc_html_e( 'Active', 'jezpress-woo-delivery-dates' ); ?></span>
					<?php else : ?>
						<span style="display:inline-block;padding:2px 10px;border-radius:3px;font-size:12px;font-weight:600;background:#fee2e2;color:#991b1b;margin-left:8px;"><?php esc_html_e( 'Inactive', 'jezpress-woo-delivery-dates' ); ?></span>
					<?php endif; ?>
				</h2>

				<?php if ( $is_active ) : ?>
					<table class="form-table" style="margin-top:0;">
						<tr>
							<th><?php esc_html_e( 'License Key:', 'jezpress-woo-delivery-dates' ); ?></th>
							<td><code><?php echo esc_html( $this->mask_license_key( $license_key ) ); ?></code></td>
						</tr>
						<?php if ( ! empty( $license_data['customer'] ) ) : ?>
						<tr>
							<th><?php esc_html_e( 'Licensed to:', 'jezpress-woo-delivery-dates' ); ?></th>
							<td><?php echo esc_html( $license_data['customer'] ); ?></td>
						</tr>
						<?php endif; ?>
						<?php if ( ! empty( $license_data['email'] ) ) : ?>
						<tr>
							<th><?php esc_html_e( 'Email:', 'jezpress-woo-delivery-dates' ); ?></th>
							<td><?php echo esc_html( $license_data['email'] ); ?></td>
						</tr>
						<?php endif; ?>
						<?php if ( ! empty( $license_data['domain'] ) ) : ?>
						<tr>
							<th><?php esc_html_e( 'Domain:', 'jezpress-woo-delivery-dates' ); ?></th>
							<td><?php echo esc_html( $license_data['domain'] ); ?></td>
						</tr>
						<?php endif; ?>
						<?php if ( ! empty( $license_data['expires'] ) ) : ?>
						<tr>
							<th><?php esc_html_e( 'Expires:', 'jezpress-woo-delivery-dates' ); ?></th>
							<td>
								<?php
								if ( 'lifetime' === $license_data['expires'] ) {
									esc_html_e( 'Never (Lifetime)', 'jezpress-woo-delivery-dates' );
								} else {
									echo esc_html( $license_data['expires'] );
								}
								?>
							</td>
						</tr>
						<?php endif; ?>
					</table>

					<form method="post" style="margin-top:16px;">
						<?php wp_nonce_field( 'jezweb_license_deactivate', 'jezweb_license_nonce' ); ?>
						<input type="hidden" name="jezweb_license_action" value="deactivate">
						<input type="hidden" name="jezweb_license_slug" value="<?php echo esc_attr( $this->slug ); ?>">
						<button type="submit" class="button button-secondary">
							<?php esc_html_e( 'Deactivate License', 'jezpress-woo-delivery-dates' ); ?>
						</button>
					</form>

				<?php else : ?>
					<p><?php esc_html_e( 'Enter your license key to activate all plugin features.', 'jezpress-woo-delivery-dates' ); ?></p>

					<form method="post">
						<?php wp_nonce_field( 'jezweb_license_activate', 'jezweb_license_nonce' ); ?>
						<input type="hidden" name="jezweb_license_action" value="activate">
						<input type="hidden" name="jezweb_license_slug" value="<?php echo esc_attr( $this->slug ); ?>">

						<p>
							<input type="text"
								   name="jezweb_license_key"
								   class="regular-text"
								   placeholder="XXXX-XXXX-XXXX-XXXX"
								   value=""
								   autocomplete="off">
						</p>
						<p>
							<button type="submit" class="button button-primary">
								<?php esc_html_e( 'Activate License', 'jezpress-woo-delivery-dates' ); ?>
							</button>
						</p>
					</form>

					<p class="description">
						<?php esc_html_e( 'Your license key was provided when you purchased the plugin.', 'jezpress-woo-delivery-dates' ); ?>
						<a href="mailto:support@jezweb.net">support@jezweb.net</a>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Mask license key for display.
	 *
	 * @param string $key License key.
	 * @return string Masked key.
	 */
	private function mask_license_key( $key ) {
		if ( strlen( $key ) <= 8 ) {
			return str_repeat( '*', strlen( $key ) );
		}
		return substr( $key, 0, 4 ) . str_repeat( '*', strlen( $key ) - 8 ) . substr( $key, -4 );
	}

	/**
	 * Scheduled daily license check.
	 *
	 * @return void
	 */
	public function scheduled_license_check() {
		$this->validate_license_remote();
	}

	/**
	 * Clean up cron on plugin deactivation.
	 *
	 * @return void
	 */
	public function cleanup() {
		wp_clear_scheduled_hook( 'jwdd_license_check' );
	}

	/**
	 * Get license status string.
	 *
	 * @return string Status (active, inactive, expired).
	 */
	public function get_status() {
		if ( ! $this->is_valid() ) {
			$data = $this->get_license_data();
			if ( isset( $data['expires'] ) && 'lifetime' !== $data['expires'] ) {
				$expires = strtotime( $data['expires'] );
				if ( $expires && $expires < time() ) {
					return 'expired';
				}
			}
			return 'inactive';
		}
		return 'active';
	}
}
