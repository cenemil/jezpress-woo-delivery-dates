<?php
/**
 * JWDD Admin
 *
 * Singleton admin class. Registers the WooCommerce submenu page and renders
 * the four tabs: General Settings, Carriers, Schedules, License.
 *
 * Non-license tabs are gated — if the license is not active, only the License
 * tab renders; all others show a redirect notice.
 *
 * @package Jezpress_Woo_Delivery_Dates
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JWDD_Admin {

	/** @var JWDD_Admin|null Singleton instance. */
	private static $instance = null;

	/** @var string Admin page hook suffix. */
	private $page_hook = '';

	/**
	 * Get singleton instance.
	 *
	 * @return JWDD_Admin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — registers admin hooks.
	 */
	private function __construct() {
		add_action( 'admin_menu',            array( $this, 'register_menu' ) );
		add_action( 'admin_init',            array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Register WooCommerce submenu page.
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->page_hook = add_submenu_page(
			'woocommerce',
			__( 'Delivery Dates', 'jezpress-woo-delivery-dates' ),
			__( 'Delivery Dates', 'jezpress-woo-delivery-dates' ),
			'manage_woocommerce',
			'jwdd-delivery-dates',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the general settings via Settings API.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting( 'jwdd_settings_group', 'jwdd_settings', array( $this, 'sanitize_settings' ) );
	}

	/**
	 * Sanitize general settings before saving.
	 *
	 * @param array $input Raw input.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		return array(
			'enabled'         => ! empty( $input['enabled'] ) ? 1 : 0,
			'required'        => ! empty( $input['required'] ) ? 1 : 0,
			'cutoff_days'     => isset( $input['cutoff_days'] ) ? absint( $input['cutoff_days'] ) : 1,
			'max_future_days' => isset( $input['max_future_days'] ) ? absint( $input['max_future_days'] ) : 30,
			'checkout_label'  => isset( $input['checkout_label'] ) ? sanitize_text_field( $input['checkout_label'] ) : 'Select Delivery Date & Time',
			'show_carrier'    => ! empty( $input['show_carrier'] ) ? 1 : 0,
		);
	}

	/**
	 * Enqueue admin scripts and styles only on the plugin page.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_scripts( $hook ) {
		if ( $hook !== $this->page_hook ) {
			return;
		}

		wp_enqueue_style(
			'jwdd-admin',
			JWDD_URL . 'assets/css/jwdd-admin.css',
			array(),
			JWDD_VERSION
		);

		wp_enqueue_script(
			'jwdd-admin',
			JWDD_URL . 'assets/js/jwdd-admin.js',
			array(),
			JWDD_VERSION,
			true
		);

		$carriers = JWDD_Carriers::get_all();

		wp_localize_script( 'jwdd-admin', 'jwdd_admin', array(
			'ajaxurl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'jwdd_admin_nonce' ),
			'carriers' => $carriers,
			'i18n'     => array(
				'confirm_delete_carrier'  => __( 'Delete this carrier and all its schedules? This cannot be undone.', 'jezpress-woo-delivery-dates' ),
				'confirm_delete_schedule' => __( 'Delete this schedule slot?', 'jezpress-woo-delivery-dates' ),
				'confirm_generate'        => __( 'Generate recurring slots for the selected date range and days?', 'jezpress-woo-delivery-dates' ),
				'saving'                  => __( 'Saving...', 'jezpress-woo-delivery-dates' ),
				'deleting'                => __( 'Deleting...', 'jezpress-woo-delivery-dates' ),
				'generating'              => __( 'Generating...', 'jezpress-woo-delivery-dates' ),
				'error'                   => __( 'An error occurred. Please try again.', 'jezpress-woo-delivery-dates' ),
				'no_carriers'             => __( 'No carriers yet. Add one below.', 'jezpress-woo-delivery-dates' ),
				'no_schedules'            => __( 'No schedule slots found.', 'jezpress-woo-delivery-dates' ),
			),
		) );
	}

	/**
	 * Render the admin page with tab navigation.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'jezpress-woo-delivery-dates' ) );
		}

		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
		$tabs        = array(
			'settings'  => __( 'General Settings', 'jezpress-woo-delivery-dates' ),
			'carriers'  => __( 'Carriers', 'jezpress-woo-delivery-dates' ),
			'schedules' => __( 'Schedules', 'jezpress-woo-delivery-dates' ),
			'license'   => __( 'License', 'jezpress-woo-delivery-dates' ),
		);

		$license     = JWDD_License::get_instance();
		$is_licensed = $license && $license->is_valid();

		echo '<div class="wrap jwdd-wrap">';
		echo '<h1>' . esc_html__( 'Jezpress Delivery Dates', 'jezpress-woo-delivery-dates' ) . '</h1>';

		// Tab navigation.
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			$url    = admin_url( 'admin.php?page=jwdd-delivery-dates&tab=' . $slug );
			$active = $current_tab === $slug ? ' nav-tab-active' : '';
			echo '<a href="' . esc_url( $url ) . '" class="nav-tab' . esc_attr( $active ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';

		echo '<div class="jwdd-tab-content">';

		// Gate non-license tabs.
		if ( 'license' !== $current_tab && ! $is_licensed ) {
			$license_url = admin_url( 'admin.php?page=jwdd-delivery-dates&tab=license' );
			echo '<div class="notice notice-warning inline"><p>';
			printf(
				/* translators: %s: link to license tab */
				esc_html__( 'A valid license is required to use this plugin. Please %s to enable all features.', 'jezpress-woo-delivery-dates' ),
				'<a href="' . esc_url( $license_url ) . '">' . esc_html__( 'activate your license', 'jezpress-woo-delivery-dates' ) . '</a>'
			);
			echo '</p></div>';
			echo '</div></div>';
			return;
		}

		switch ( $current_tab ) {
			case 'carriers':
				$this->render_tab_carriers();
				break;
			case 'schedules':
				$this->render_tab_schedules();
				break;
			case 'license':
				if ( $license ) {
					$license->render_tab_content();
				}
				break;
			default:
				$this->render_tab_settings();
				break;
		}

		echo '</div></div>';
	}

	// -------------------------------------------------------------------------
	// Tab renderers
	// -------------------------------------------------------------------------

	/**
	 * Render the General Settings tab.
	 *
	 * @return void
	 */
	private function render_tab_settings() {
		$settings = get_option( 'jwdd_settings', array() );
		?>
		<form method="post" action="options.php" style="max-width:800px; margin-top:20px;">
			<?php settings_fields( 'jwdd_settings_group' ); ?>

			<div class="jwdd-card">
				<h2><?php esc_html_e( 'General Settings', 'jezpress-woo-delivery-dates' ); ?></h2>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable Delivery Dates', 'jezpress-woo-delivery-dates' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="jwdd_settings[enabled]" value="1" <?php checked( 1, $settings['enabled'] ?? 1 ); ?>>
								<?php esc_html_e( 'Show delivery date selector on checkout', 'jezpress-woo-delivery-dates' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Required at Checkout', 'jezpress-woo-delivery-dates' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="jwdd_settings[required]" value="1" <?php checked( 1, $settings['required'] ?? 1 ); ?>>
								<?php esc_html_e( 'Customer must select a date and time slot to place an order', 'jezpress-woo-delivery-dates' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="jwdd_cutoff_days"><?php esc_html_e( 'Minimum Days in Advance', 'jezpress-woo-delivery-dates' ); ?></label>
						</th>
						<td>
							<input type="number" id="jwdd_cutoff_days" name="jwdd_settings[cutoff_days]"
								   value="<?php echo esc_attr( $settings['cutoff_days'] ?? 1 ); ?>"
								   min="0" max="365" class="small-text">
							<p class="description"><?php esc_html_e( 'Earliest selectable date is today + this many days. Use 0 to allow same-day selection.', 'jezpress-woo-delivery-dates' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="jwdd_max_future_days"><?php esc_html_e( 'Maximum Days Ahead', 'jezpress-woo-delivery-dates' ); ?></label>
						</th>
						<td>
							<input type="number" id="jwdd_max_future_days" name="jwdd_settings[max_future_days]"
								   value="<?php echo esc_attr( $settings['max_future_days'] ?? 30 ); ?>"
								   min="1" max="365" class="small-text">
							<p class="description"><?php esc_html_e( 'Latest selectable date is today + this many days.', 'jezpress-woo-delivery-dates' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="jwdd_checkout_label"><?php esc_html_e( 'Checkout Section Label', 'jezpress-woo-delivery-dates' ); ?></label>
						</th>
						<td>
							<input type="text" id="jwdd_checkout_label" name="jwdd_settings[checkout_label]"
								   value="<?php echo esc_attr( $settings['checkout_label'] ?? 'Select Delivery Date & Time' ); ?>"
								   class="regular-text">
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Show Carrier Selection', 'jezpress-woo-delivery-dates' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="jwdd_settings[show_carrier]" value="1" <?php checked( 1, $settings['show_carrier'] ?? 0 ); ?>>
								<?php esc_html_e( 'Let customers choose a delivery carrier at checkout', 'jezpress-woo-delivery-dates' ); ?>
							</label>
						</td>
					</tr>
				</table>
			</div>

			<?php submit_button( __( 'Save Settings', 'jezpress-woo-delivery-dates' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Render the Carriers management tab.
	 *
	 * @return void
	 */
	private function render_tab_carriers() {
		$carriers = JWDD_Carriers::get_all();
		?>
		<div style="max-width:960px; margin-top:20px;">

			<div class="jwdd-card">
				<h2><?php esc_html_e( 'Delivery Carriers', 'jezpress-woo-delivery-dates' ); ?></h2>

				<div id="jwdd-carriers-feedback" class="jwdd-feedback" style="display:none;"></div>

				<table class="widefat striped" id="jwdd-carriers-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Name', 'jezpress-woo-delivery-dates' ); ?></th>
							<th><?php esc_html_e( 'Code', 'jezpress-woo-delivery-dates' ); ?></th>
							<th><?php esc_html_e( 'Description', 'jezpress-woo-delivery-dates' ); ?></th>
							<th><?php esc_html_e( 'Order', 'jezpress-woo-delivery-dates' ); ?></th>
							<th><?php esc_html_e( 'Active', 'jezpress-woo-delivery-dates' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'jezpress-woo-delivery-dates' ); ?></th>
						</tr>
					</thead>
					<tbody id="jwdd-carriers-tbody">
						<?php if ( empty( $carriers ) ) : ?>
							<tr id="jwdd-carriers-empty">
								<td colspan="6"><?php esc_html_e( 'No carriers yet. Add one below.', 'jezpress-woo-delivery-dates' ); ?></td>
							</tr>
						<?php else : ?>
							<?php foreach ( $carriers as $carrier ) : ?>
								<tr id="jwdd-carrier-row-<?php echo esc_attr( $carrier->id ); ?>">
									<td><strong><?php echo esc_html( $carrier->name ); ?></strong></td>
									<td><code><?php echo esc_html( $carrier->code ); ?></code></td>
									<td><?php echo esc_html( $carrier->description ); ?></td>
									<td><?php echo esc_html( $carrier->sort_order ); ?></td>
									<td>
										<?php if ( $carrier->is_active ) : ?>
											<span class="jwdd-badge jwdd-badge-active"><?php esc_html_e( 'Active', 'jezpress-woo-delivery-dates' ); ?></span>
										<?php else : ?>
											<span class="jwdd-badge jwdd-badge-inactive"><?php esc_html_e( 'Inactive', 'jezpress-woo-delivery-dates' ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<button class="button button-small jwdd-edit-carrier"
												data-id="<?php echo esc_attr( $carrier->id ); ?>"
												data-name="<?php echo esc_attr( $carrier->name ); ?>"
												data-code="<?php echo esc_attr( $carrier->code ); ?>"
												data-description="<?php echo esc_attr( $carrier->description ); ?>"
												data-sort_order="<?php echo esc_attr( $carrier->sort_order ); ?>"
												data-is_active="<?php echo esc_attr( $carrier->is_active ); ?>">
											<?php esc_html_e( 'Edit', 'jezpress-woo-delivery-dates' ); ?>
										</button>
										<button class="button button-small jwdd-delete-carrier"
												data-id="<?php echo esc_attr( $carrier->id ); ?>"
												data-name="<?php echo esc_attr( $carrier->name ); ?>">
											<?php esc_html_e( 'Delete', 'jezpress-woo-delivery-dates' ); ?>
										</button>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<div class="jwdd-card" id="jwdd-carrier-form-wrap">
				<h2 id="jwdd-carrier-form-title"><?php esc_html_e( 'Add Carrier', 'jezpress-woo-delivery-dates' ); ?></h2>

				<table class="form-table" role="presentation">
					<tr>
						<th><label for="jwdd_carrier_name"><?php esc_html_e( 'Name', 'jezpress-woo-delivery-dates' ); ?> <span class="required">*</span></label></th>
						<td><input type="text" id="jwdd_carrier_name" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Australia Post', 'jezpress-woo-delivery-dates' ); ?>"></td>
					</tr>
					<tr>
						<th><label for="jwdd_carrier_code"><?php esc_html_e( 'Code', 'jezpress-woo-delivery-dates' ); ?></label></th>
						<td>
							<input type="text" id="jwdd_carrier_code" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. auspost', 'jezpress-woo-delivery-dates' ); ?>">
							<p class="description"><?php esc_html_e( 'Short identifier. Auto-generated from name if left blank.', 'jezpress-woo-delivery-dates' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="jwdd_carrier_description"><?php esc_html_e( 'Description', 'jezpress-woo-delivery-dates' ); ?></label></th>
						<td><textarea id="jwdd_carrier_description" class="regular-text" rows="2"></textarea></td>
					</tr>
					<tr>
						<th><label for="jwdd_carrier_sort_order"><?php esc_html_e( 'Sort Order', 'jezpress-woo-delivery-dates' ); ?></label></th>
						<td><input type="number" id="jwdd_carrier_sort_order" class="small-text" value="0" min="0"></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Active', 'jezpress-woo-delivery-dates' ); ?></th>
						<td>
							<label>
								<input type="checkbox" id="jwdd_carrier_is_active" value="1" checked>
								<?php esc_html_e( 'Enabled', 'jezpress-woo-delivery-dates' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<input type="hidden" id="jwdd_carrier_id" value="0">

				<p>
					<button type="button" class="button button-primary" id="jwdd-save-carrier"><?php esc_html_e( 'Add Carrier', 'jezpress-woo-delivery-dates' ); ?></button>
					<button type="button" class="button button-secondary" id="jwdd-cancel-carrier" style="display:none;"><?php esc_html_e( 'Cancel', 'jezpress-woo-delivery-dates' ); ?></button>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Schedules management tab.
	 *
	 * @return void
	 */
	private function render_tab_schedules() {
		$carriers = JWDD_Carriers::get_all();
		$today    = gmdate( 'Y-m-d' );
		$next30   = gmdate( 'Y-m-d', strtotime( '+30 days' ) );
		?>
		<div style="max-width:1100px; margin-top:20px;">

			<!-- Schedules table + filters -->
			<div class="jwdd-card">
				<h2><?php esc_html_e( 'Delivery Schedules', 'jezpress-woo-delivery-dates' ); ?></h2>

				<div class="jwdd-filter-row">
					<label><?php esc_html_e( 'Carrier:', 'jezpress-woo-delivery-dates' ); ?>
						<select id="jwdd-filter-carrier">
							<option value=""><?php esc_html_e( 'All carriers', 'jezpress-woo-delivery-dates' ); ?></option>
							<?php foreach ( $carriers as $c ) : ?>
								<option value="<?php echo esc_attr( $c->id ); ?>"><?php echo esc_html( $c->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label><?php esc_html_e( 'From:', 'jezpress-woo-delivery-dates' ); ?>
						<input type="date" id="jwdd-filter-date-from" value="<?php echo esc_attr( $today ); ?>">
					</label>
					<label><?php esc_html_e( 'To:', 'jezpress-woo-delivery-dates' ); ?>
						<input type="date" id="jwdd-filter-date-to" value="<?php echo esc_attr( $next30 ); ?>">
					</label>
					<button type="button" class="button" id="jwdd-filter-schedules"><?php esc_html_e( 'Filter', 'jezpress-woo-delivery-dates' ); ?></button>
				</div>

				<div id="jwdd-schedules-feedback" class="jwdd-feedback" style="display:none;"></div>

				<table class="widefat striped" id="jwdd-schedules-table" style="margin-top:12px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'jezpress-woo-delivery-dates' ); ?></th>
							<th><?php esc_html_e( 'Carrier', 'jezpress-woo-delivery-dates' ); ?></th>
							<th><?php esc_html_e( 'Time Slot', 'jezpress-woo-delivery-dates' ); ?></th>
							<th><?php esc_html_e( 'Max Orders', 'jezpress-woo-delivery-dates' ); ?></th>
							<th><?php esc_html_e( 'Booked', 'jezpress-woo-delivery-dates' ); ?></th>
							<th><?php esc_html_e( 'Active', 'jezpress-woo-delivery-dates' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'jezpress-woo-delivery-dates' ); ?></th>
						</tr>
					</thead>
					<tbody id="jwdd-schedules-tbody">
						<tr id="jwdd-schedules-loading">
							<td colspan="7"><?php esc_html_e( 'Loading...', 'jezpress-woo-delivery-dates' ); ?></td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Add / Edit schedule -->
			<div class="jwdd-card" id="jwdd-schedule-form-wrap">
				<h2 id="jwdd-schedule-form-title"><?php esc_html_e( 'Add Schedule Slot', 'jezpress-woo-delivery-dates' ); ?></h2>

				<table class="form-table" role="presentation">
					<tr>
						<th><label for="jwdd_schedule_carrier"><?php esc_html_e( 'Carrier', 'jezpress-woo-delivery-dates' ); ?></label></th>
						<td>
							<select id="jwdd_schedule_carrier">
								<option value="0"><?php esc_html_e( '— No specific carrier —', 'jezpress-woo-delivery-dates' ); ?></option>
								<?php foreach ( $carriers as $c ) : ?>
									<option value="<?php echo esc_attr( $c->id ); ?>"><?php echo esc_html( $c->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="jwdd_schedule_date"><?php esc_html_e( 'Date', 'jezpress-woo-delivery-dates' ); ?> <span class="required">*</span></label></th>
						<td><input type="date" id="jwdd_schedule_date" value="<?php echo esc_attr( $today ); ?>"></td>
					</tr>
					<tr>
						<th><label for="jwdd_schedule_start"><?php esc_html_e( 'Start Time', 'jezpress-woo-delivery-dates' ); ?> <span class="required">*</span></label></th>
						<td><input type="time" id="jwdd_schedule_start" value="09:00"></td>
					</tr>
					<tr>
						<th><label for="jwdd_schedule_end"><?php esc_html_e( 'End Time', 'jezpress-woo-delivery-dates' ); ?> <span class="required">*</span></label></th>
						<td><input type="time" id="jwdd_schedule_end" value="12:00"></td>
					</tr>
					<tr>
						<th><label for="jwdd_schedule_label"><?php esc_html_e( 'Label', 'jezpress-woo-delivery-dates' ); ?></label></th>
						<td>
							<input type="text" id="jwdd_schedule_label" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Morning (9am – 12pm)', 'jezpress-woo-delivery-dates' ); ?>">
							<p class="description"><?php esc_html_e( 'Auto-generated from times if left blank.', 'jezpress-woo-delivery-dates' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="jwdd_schedule_max"><?php esc_html_e( 'Max Orders', 'jezpress-woo-delivery-dates' ); ?></label></th>
						<td>
							<input type="number" id="jwdd_schedule_max" class="small-text" value="0" min="0">
							<p class="description"><?php esc_html_e( '0 = unlimited.', 'jezpress-woo-delivery-dates' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Active', 'jezpress-woo-delivery-dates' ); ?></th>
						<td>
							<label>
								<input type="checkbox" id="jwdd_schedule_is_active" value="1" checked>
								<?php esc_html_e( 'Enabled', 'jezpress-woo-delivery-dates' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<input type="hidden" id="jwdd_schedule_id" value="0">

				<p>
					<button type="button" class="button button-primary" id="jwdd-save-schedule"><?php esc_html_e( 'Add Slot', 'jezpress-woo-delivery-dates' ); ?></button>
					<button type="button" class="button button-secondary" id="jwdd-cancel-schedule" style="display:none;"><?php esc_html_e( 'Cancel', 'jezpress-woo-delivery-dates' ); ?></button>
				</p>
			</div>

			<!-- Generate recurring schedules -->
			<div class="jwdd-card">
				<h2><?php esc_html_e( 'Generate Recurring Slots', 'jezpress-woo-delivery-dates' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Bulk-create schedule slots for a date range and selected days of the week.', 'jezpress-woo-delivery-dates' ); ?></p>

				<div id="jwdd-recurring-feedback" class="jwdd-feedback" style="display:none;"></div>

				<table class="form-table" role="presentation">
					<tr>
						<th><label for="jwdd_rec_carrier"><?php esc_html_e( 'Carrier', 'jezpress-woo-delivery-dates' ); ?></label></th>
						<td>
							<select id="jwdd_rec_carrier">
								<option value="0"><?php esc_html_e( '— No specific carrier —', 'jezpress-woo-delivery-dates' ); ?></option>
								<?php foreach ( $carriers as $c ) : ?>
									<option value="<?php echo esc_attr( $c->id ); ?>"><?php echo esc_html( $c->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Date Range', 'jezpress-woo-delivery-dates' ); ?></th>
						<td>
							<label><?php esc_html_e( 'From:', 'jezpress-woo-delivery-dates' ); ?>
								<input type="date" id="jwdd_rec_start" value="<?php echo esc_attr( $today ); ?>">
							</label>
							&nbsp;
							<label><?php esc_html_e( 'To:', 'jezpress-woo-delivery-dates' ); ?>
								<input type="date" id="jwdd_rec_end" value="<?php echo esc_attr( $next30 ); ?>">
							</label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Days of Week', 'jezpress-woo-delivery-dates' ); ?></th>
						<td>
							<?php
							$dow_labels = array(
								0 => __( 'Sunday', 'jezpress-woo-delivery-dates' ),
								1 => __( 'Monday', 'jezpress-woo-delivery-dates' ),
								2 => __( 'Tuesday', 'jezpress-woo-delivery-dates' ),
								3 => __( 'Wednesday', 'jezpress-woo-delivery-dates' ),
								4 => __( 'Thursday', 'jezpress-woo-delivery-dates' ),
								5 => __( 'Friday', 'jezpress-woo-delivery-dates' ),
								6 => __( 'Saturday', 'jezpress-woo-delivery-dates' ),
							);
							foreach ( $dow_labels as $num => $lbl ) :
								?>
								<label style="margin-right:12px;">
									<input type="checkbox" class="jwdd-rec-dow" value="<?php echo esc_attr( $num ); ?>"
										<?php checked( in_array( $num, array( 1, 2, 3, 4, 5 ), true ) ); ?>>
									<?php echo esc_html( $lbl ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Time Slot', 'jezpress-woo-delivery-dates' ); ?></th>
						<td>
							<label><?php esc_html_e( 'Start:', 'jezpress-woo-delivery-dates' ); ?>
								<input type="time" id="jwdd_rec_start_time" value="09:00">
							</label>
							&nbsp;
							<label><?php esc_html_e( 'End:', 'jezpress-woo-delivery-dates' ); ?>
								<input type="time" id="jwdd_rec_end_time" value="17:00">
							</label>
						</td>
					</tr>
					<tr>
						<th><label for="jwdd_rec_label"><?php esc_html_e( 'Label', 'jezpress-woo-delivery-dates' ); ?></label></th>
						<td>
							<input type="text" id="jwdd_rec_label" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Business Hours (9am – 5pm)', 'jezpress-woo-delivery-dates' ); ?>">
						</td>
					</tr>
					<tr>
						<th><label for="jwdd_rec_max"><?php esc_html_e( 'Max Orders per Slot', 'jezpress-woo-delivery-dates' ); ?></label></th>
						<td><input type="number" id="jwdd_rec_max" class="small-text" value="0" min="0"></td>
					</tr>
				</table>

				<p>
					<button type="button" class="button button-primary" id="jwdd-generate-recurring"><?php esc_html_e( 'Generate Slots', 'jezpress-woo-delivery-dates' ); ?></button>
				</p>
			</div>

		</div>
		<?php
	}
}
