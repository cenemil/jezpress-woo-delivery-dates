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
			'max_future_days' => isset( $input['max_future_days'] ) ? absint( $input['max_future_days'] ) : 30,
			'checkout_label'  => isset( $input['checkout_label'] ) ? sanitize_text_field( $input['checkout_label'] ) : 'Select Delivery Date & Time',
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
			filemtime( JWDD_DIR . 'assets/css/jwdd-admin.css' )
		);

		wp_enqueue_script(
			'jwdd-admin',
			JWDD_URL . 'assets/js/jwdd-admin.js',
			array(),
			filemtime( JWDD_DIR . 'assets/js/jwdd-admin.js' ),
			true
		);

		$carriers = JWDD_Carriers::get_all();

		$tab    = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
		$def_id     = isset( $_GET['def_id'] )     ? absint( $_GET['def_id'] )     : 0;
		$carrier_id = isset( $_GET['carrier_id'] ) ? absint( $_GET['carrier_id'] ) : 0;

		$context = 'other';
		if ( 'schedules' === $tab ) {
			if ( 'add' === $action ) {
				$context = 'schedules_add';
			} elseif ( 'edit' === $action && $def_id ) {
				$context = 'schedules_edit';
			} else {
				$context = 'schedules_list';
			}
		} elseif ( 'carriers' === $tab ) {
			if ( 'add' === $action ) {
				$context = 'carriers_add';
			} elseif ( 'edit' === $action && $carrier_id ) {
				$context = 'carriers_edit';
			} else {
				$context = 'carriers_list';
			}
		}

		// Build WooCommerce shipping zones list (for carrier form).
		$wc_zones_data = array();
		if ( 'carriers_add' === $context || 'carriers_edit' === $context ) {
			if ( class_exists( 'WC_Shipping_Zones' ) ) {
				$wc_zones_data[] = array(
					'id'   => 0,
					'name' => __( 'Rest of World', 'jezpress-woo-delivery-dates' ),
				);
				foreach ( WC_Shipping_Zones::get_zones() as $zone ) {
					$wc_zones_data[] = array(
						'id'   => (int) $zone['id'],
						'name' => $zone['zone_name'],
					);
				}
			}
		}

		// Build saved shipping zones for the carrier being edited.
		$carrier_zones_data = array();
		if ( 'carriers_edit' === $context && $carrier_id ) {
			$carrier_for_js = JWDD_Carriers::get_by_id( $carrier_id );
			if ( $carrier_for_js && ! empty( $carrier_for_js->shipping_zones ) ) {
				$decoded = json_decode( $carrier_for_js->shipping_zones, true );
				if ( is_array( $decoded ) ) {
					$carrier_zones_data = $decoded;
				}
			}
		}

		// Build per-day slot data for the schedule def form.
		$day_slots_data = array();
		if ( ( 'schedules_add' === $context || 'schedules_edit' === $context ) && $def_id ) {
			$def_for_js = JWDD_Schedule_Defs::get_by_id( $def_id );
			if ( $def_for_js ) {
				$saved_days = json_decode( $def_for_js->days_of_week, true ) ?: array();
				foreach ( $saved_days as $d ) {
					if ( is_array( $d ) && isset( $d['day'] ) ) {
						$day_slots_data[ (int) $d['day'] ] = isset( $d['slots'] ) && is_array( $d['slots'] ) ? $d['slots'] : array();
					}
				}
			}
		}

		wp_localize_script( 'jwdd-admin', 'jwdd_admin', array(
			'ajaxurl'           => admin_url( 'admin-ajax.php' ),
			'nonce'             => wp_create_nonce( 'jwdd_admin_nonce' ),
			'carriers'          => $carriers,
			'context'           => $context,
			'def_id'            => $def_id,
			'def_edit_base_url'    => admin_url( 'admin.php?page=jwdd-delivery-dates&tab=schedules&action=edit' ),
			'schedule_list_url'    => admin_url( 'admin.php?page=jwdd-delivery-dates&tab=schedules' ),
			'carrier_id'           => $carrier_id,
			'carrier_list_url'     => admin_url( 'admin.php?page=jwdd-delivery-dates&tab=carriers' ),
			'day_slots'            => $day_slots_data,
			'wc_zones'             => $wc_zones_data,
			'carrier_zones'        => $carrier_zones_data,
			'i18n'              => array(
				'confirm_delete_carrier'  => __( 'Delete this carrier and all its schedules? This cannot be undone.', 'jezpress-woo-delivery-dates' ),
				'confirm_delete_schedule' => __( 'Delete this schedule and all its slots? This cannot be undone.', 'jezpress-woo-delivery-dates' ),
				'saving'                  => __( 'Saving...', 'jezpress-woo-delivery-dates' ),
				'deleting'                => __( 'Deleting...', 'jezpress-woo-delivery-dates' ),
				'error'                   => __( 'An error occurred. Please try again.', 'jezpress-woo-delivery-dates' ),
				'no_carriers'             => __( 'No carriers yet. Add one below.', 'jezpress-woo-delivery-dates' ),
				'no_schedules'            => __( 'No schedules yet.', 'jezpress-woo-delivery-dates' ),
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
			'settings'  => __( 'Settings', 'jezpress-woo-delivery-dates' ),
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
				<h2><?php esc_html_e( 'Settings', 'jezpress-woo-delivery-dates' ); ?></h2>

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
				</table>
			</div>

			<?php submit_button( __( 'Save Settings', 'jezpress-woo-delivery-dates' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Render the Carriers tab — routes to list, add, or edit page.
	 *
	 * @return void
	 */
	private function render_tab_carriers() {
		$action     = isset( $_GET['action'] )     ? sanitize_key( $_GET['action'] ) : '';
		$carrier_id = isset( $_GET['carrier_id'] ) ? absint( $_GET['carrier_id'] )   : 0;

		if ( 'add' === $action ) {
			$this->render_carrier_form_page( 0 );
		} elseif ( 'edit' === $action && $carrier_id > 0 ) {
			$this->render_carrier_form_page( $carrier_id );
		} else {
			$this->render_carrier_list_page();
		}
	}

	/**
	 * Render the carrier list page.
	 *
	 * @return void
	 */
	private function render_carrier_list_page() {
		$carriers = JWDD_Carriers::get_all();
		$add_url  = admin_url( 'admin.php?page=jwdd-delivery-dates&tab=carriers&action=add' );
		?>
		<div style="max-width:960px; margin-top:20px;">

			<div class="jwdd-card">
				<h2>
					<?php esc_html_e( 'Delivery Carriers', 'jezpress-woo-delivery-dates' ); ?>
					<a href="<?php echo esc_url( $add_url ); ?>" class="button button-primary button-small" style="margin-left:auto;">
						<?php esc_html_e( '+ Add Carrier', 'jezpress-woo-delivery-dates' ); ?>
					</a>
				</h2>

				<div id="jwdd-carriers-feedback" class="jwdd-feedback" style="display:none;"></div>

				<table class="widefat striped" id="jwdd-carriers-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Name', 'jezpress-woo-delivery-dates' ); ?></th>
							<th><?php esc_html_e( 'Code', 'jezpress-woo-delivery-dates' ); ?></th>
							<th><?php esc_html_e( 'Status', 'jezpress-woo-delivery-dates' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'jezpress-woo-delivery-dates' ); ?></th>
						</tr>
					</thead>
					<tbody id="jwdd-carriers-tbody">
						<?php if ( empty( $carriers ) ) : ?>
							<tr id="jwdd-carriers-empty">
								<td colspan="4"><?php esc_html_e( 'No carriers yet. Click &quot;+ Add Carrier&quot; to create one.', 'jezpress-woo-delivery-dates' ); ?></td>
							</tr>
						<?php else : ?>
						<?php foreach ( $carriers as $c ) : ?>
							<?php $edit_url = admin_url( 'admin.php?page=jwdd-delivery-dates&tab=carriers&action=edit&carrier_id=' . $c->id ); ?>
							<tr id="jwdd-carrier-row-<?php echo esc_attr( $c->id ); ?>">
								<td><strong><?php echo esc_html( $c->name ); ?></strong></td>
								<td><code><?php echo esc_html( $c->code ); ?></code></td>
								<td>
									<?php if ( $c->is_active ) : ?>
										<span class="jwdd-badge jwdd-badge-active"><?php esc_html_e( 'Active', 'jezpress-woo-delivery-dates' ); ?></span>
									<?php else : ?>
										<span class="jwdd-badge jwdd-badge-inactive"><?php esc_html_e( 'Inactive', 'jezpress-woo-delivery-dates' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">
										<?php esc_html_e( 'Edit', 'jezpress-woo-delivery-dates' ); ?>
									</a>
									<button class="button button-small jwdd-delete-carrier"
										   data-id="<?php echo esc_attr( $c->id ); ?>"
										   data-name="<?php echo esc_attr( $c->name ); ?>">
										<?php esc_html_e( 'Delete', 'jezpress-woo-delivery-dates' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the carrier add/edit form page.
	 *
	 * @param int $carrier_id 0 for add, positive int for edit.
	 * @return void
	 */
	private function render_carrier_form_page( $carrier_id ) {
		$is_edit  = $carrier_id > 0;
		$carrier  = $is_edit ? JWDD_Carriers::get_by_id( $carrier_id ) : null;
		$list_url = admin_url( 'admin.php?page=jwdd-delivery-dates&tab=carriers' );

		if ( $is_edit && ! $carrier ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Carrier not found.', 'jezpress-woo-delivery-dates' ) . '</p></div>';
			return;
		}
		?>
		<div style="max-width:780px; margin-top:20px;">

			<p style="margin-bottom:16px;">
				<a href="<?php echo esc_url( $list_url ); ?>" class="jwdd-back-link">
					&#8592; <?php esc_html_e( 'Back to Carriers', 'jezpress-woo-delivery-dates' ); ?>
				</a>
			</p>

			<div class="jwdd-card">
				<h2>
					<?php if ( $is_edit ) : ?>
						<?php printf(
							/* translators: %s: carrier name */
							esc_html__( 'Edit Carrier: %s', 'jezpress-woo-delivery-dates' ),
							'<em>' . esc_html( $carrier->name ) . '</em>'
						); ?>
					<?php else : ?>
						<?php esc_html_e( 'Add Carrier', 'jezpress-woo-delivery-dates' ); ?>
					<?php endif; ?>
				</h2>

				<table class="form-table" role="presentation">
					<tr>
						<th><label for="jwdd_carrier_name"><?php esc_html_e( 'Name', 'jezpress-woo-delivery-dates' ); ?> <span class="required">*</span></label></th>
						<td>
							<input type="text" id="jwdd_carrier_name" class="regular-text"
							   value="<?php echo $is_edit ? esc_attr( $carrier->name ) : ''; ?>"
							   placeholder="<?php esc_attr_e( 'e.g. Australia Post', 'jezpress-woo-delivery-dates' ); ?>">
						</td>
					</tr>
					<tr>
						<th><label for="jwdd_carrier_code"><?php esc_html_e( 'Code', 'jezpress-woo-delivery-dates' ); ?></label></th>
						<td>
							<input type="text" id="jwdd_carrier_code" class="regular-text"
							   value="<?php echo $is_edit ? esc_attr( $carrier->code ) : ''; ?>"
							   placeholder="<?php esc_attr_e( 'e.g. auspost', 'jezpress-woo-delivery-dates' ); ?>">
							<p class="description"><?php esc_html_e( 'Short identifier. Auto-generated from name if left blank.', 'jezpress-woo-delivery-dates' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Active', 'jezpress-woo-delivery-dates' ); ?></th>
						<td>
							<label>
								<input type="checkbox" id="jwdd_carrier_is_active" value="1"
									<?php checked( $is_edit ? (bool) $carrier->is_active : true ); ?>>
								<?php esc_html_e( 'Enabled', 'jezpress-woo-delivery-dates' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<input type="hidden" id="jwdd_carrier_id" value="<?php echo esc_attr( $carrier_id ); ?>">
			</div>

			<div class="jwdd-card">
				<h2><?php esc_html_e( 'Shipping Zones &amp; Estimated Delivery', 'jezpress-woo-delivery-dates' ); ?></h2>
				<p class="description" style="margin-top:0; margin-bottom:14px;">
					<?php esc_html_e( 'Map shipping zones to estimated delivery days for this carrier. Add multiple entries for different zones.', 'jezpress-woo-delivery-dates' ); ?>
				</p>

				<div id="jwdd-carrier-zones-list"></div>

				<p style="margin-top:10px;">
					<button type="button" class="button" id="jwdd-add-zone-row">
						<?php esc_html_e( '+ Add Zone', 'jezpress-woo-delivery-dates' ); ?>
					</button>
				</p>
			</div>

			<div id="jwdd-carrier-feedback" class="jwdd-feedback" style="display:none;"></div>

			<p>
				<button type="button" class="button button-primary" id="jwdd-save-carrier">
					<?php echo $is_edit
						? esc_html__( 'Update Carrier', 'jezpress-woo-delivery-dates' )
						: esc_html__( 'Add Carrier', 'jezpress-woo-delivery-dates' ); ?>
				</button>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the Schedules management tab — routes to list, add, or edit page.
	 *
	 * @return void
	 */
	private function render_tab_schedules() {
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
		$def_id = isset( $_GET['def_id'] ) ? absint( $_GET['def_id'] ) : 0;

		if ( 'add' === $action ) {
			$this->render_schedule_def_page( 0 );
		} elseif ( 'edit' === $action && $def_id > 0 ) {
			$this->render_schedule_def_page( $def_id );
		} else {
			$this->render_schedule_list_page();
		}
	}

	/**
	 * Render the schedule definitions list page.
	 *
	 * @return void
	 */
	private function render_schedule_list_page() {
		$defs    = JWDD_Schedule_Defs::get_all();
		$add_url = admin_url( 'admin.php?page=jwdd-delivery-dates&tab=schedules&action=add' );

		$dow_abbr = array( 0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat' );
		?>
		<div style="max-width:960px; margin-top:20px;">
			<div class="jwdd-card">
				<h2>
					<?php esc_html_e( 'Schedules', 'jezpress-woo-delivery-dates' ); ?>
					<a href="<?php echo esc_url( $add_url ); ?>" class="button button-primary button-small" style="margin-left:auto;">
						<?php esc_html_e( '+ Add Schedule', 'jezpress-woo-delivery-dates' ); ?>
					</a>
				</h2>

				<div id="jwdd-def-feedback" class="jwdd-feedback" style="display:none;"></div>

				<table class="widefat striped" id="jwdd-schedule-defs-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Name', 'jezpress-woo-delivery-dates' ); ?></th>
							<th><?php esc_html_e( 'Carrier', 'jezpress-woo-delivery-dates' ); ?></th>
							<th><?php esc_html_e( 'Days', 'jezpress-woo-delivery-dates' ); ?></th>
							<th><?php esc_html_e( 'Status', 'jezpress-woo-delivery-dates' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'jezpress-woo-delivery-dates' ); ?></th>
						</tr>
					</thead>
					<tbody id="jwdd-schedule-defs-tbody">
						<?php if ( empty( $defs ) ) : ?>
							<tr id="jwdd-defs-empty">
								<td colspan="5"><?php esc_html_e( 'No schedules yet. Click "Add Schedule" to create one.', 'jezpress-woo-delivery-dates' ); ?></td>
							</tr>
						<?php else : ?>
							<?php foreach ( $defs as $def ) : ?>
								<?php
								$days   = json_decode( $def->days_of_week, true ) ?: array();
								$labels = array();
								foreach ( $days as $d ) {
									$day_num = is_array( $d ) ? ( isset( $d['day'] ) ? (int) $d['day'] : null ) : (int) $d;
									if ( null !== $day_num && isset( $dow_abbr[ $day_num ] ) ) {
										$labels[] = $dow_abbr[ $day_num ];
									}
								}
								$days_display = empty( $labels ) ? '—' : implode( ', ', $labels );
								$edit_url     = admin_url( 'admin.php?page=jwdd-delivery-dates&tab=schedules&action=edit&def_id=' . $def->id );
								?>
								<tr id="jwdd-def-row-<?php echo esc_attr( $def->id ); ?>">
									<td><strong><?php echo esc_html( $def->name ); ?></strong></td>
									<td><?php echo esc_html( $def->carrier_name ?: '—' ); ?></td>
									<td><?php echo esc_html( $days_display ); ?></td>
									<td>
										<?php if ( $def->is_active ) : ?>
											<span class="jwdd-badge jwdd-badge-active"><?php esc_html_e( 'Active', 'jezpress-woo-delivery-dates' ); ?></span>
										<?php else : ?>
											<span class="jwdd-badge jwdd-badge-inactive"><?php esc_html_e( 'Inactive', 'jezpress-woo-delivery-dates' ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">
											<?php esc_html_e( 'Edit', 'jezpress-woo-delivery-dates' ); ?>
										</a>
										<button class="button button-small jwdd-delete-schedule-def"
												data-id="<?php echo esc_attr( $def->id ); ?>"
												data-name="<?php echo esc_attr( $def->name ); ?>">
											<?php esc_html_e( 'Delete', 'jezpress-woo-delivery-dates' ); ?>
										</button>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the add/edit schedule definition page.
	 *
	 * @param int $def_id 0 for add, positive int for edit.
	 * @return void
	 */
	private function render_schedule_def_page( $def_id ) {
		$is_edit  = $def_id > 0;
		$def      = null;
		$list_url = admin_url( 'admin.php?page=jwdd-delivery-dates&tab=schedules' );
		$carriers = JWDD_Carriers::get_all();
		$today    = gmdate( 'Y-m-d' );
		$next30   = gmdate( 'Y-m-d', strtotime( '+30 days' ) );

		$dow_labels = array(
			0 => __( 'Sunday', 'jezpress-woo-delivery-dates' ),
			1 => __( 'Monday', 'jezpress-woo-delivery-dates' ),
			2 => __( 'Tuesday', 'jezpress-woo-delivery-dates' ),
			3 => __( 'Wednesday', 'jezpress-woo-delivery-dates' ),
			4 => __( 'Thursday', 'jezpress-woo-delivery-dates' ),
			5 => __( 'Friday', 'jezpress-woo-delivery-dates' ),
			6 => __( 'Saturday', 'jezpress-woo-delivery-dates' ),
		);

		if ( $is_edit ) {
			$def = JWDD_Schedule_Defs::get_by_id( $def_id );
			if ( ! $def ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Schedule not found.', 'jezpress-woo-delivery-dates' ) . '</p></div>';
				echo '<p><a href="' . esc_url( $list_url ) . '" class="button">' . esc_html__( '← Back to Schedules', 'jezpress-woo-delivery-dates' ) . '</a></p>';
				return;
			}
		}

		// Build per-day config keyed by day number, each with start/end times.
		$days_config = array();
		if ( $is_edit && $def ) {
			$saved = json_decode( $def->days_of_week, true ) ?: array();
			foreach ( $saved as $d ) {
				if ( is_array( $d ) && isset( $d['day'] ) ) {
					$days_config[ (int) $d['day'] ] = array(
						'cutoff' => isset( $d['cutoff'] ) ? $d['cutoff'] : '',
						'slots'  => isset( $d['slots'] ) && is_array( $d['slots'] ) ? $d['slots'] : array(),
					);
				}
			}
		}
		if ( ! $is_edit ) {
			foreach ( array( 1, 2, 3, 4, 5 ) as $d ) {
				$days_config[ $d ] = array( 'cutoff' => '', 'slots' => array() );
			}
		}
		?>
		<div style="max-width:780px; margin-top:20px;">

			<p style="margin-bottom:16px;">
				<a href="<?php echo esc_url( $list_url ); ?>" class="jwdd-back-link">
					← <?php esc_html_e( 'Back to Schedules', 'jezpress-woo-delivery-dates' ); ?>
				</a>
			</p>

			<!-- Schedule definition form -->
			<div class="jwdd-card">
				<h2>
					<?php if ( $is_edit ) : ?>
						<?php
						printf(
							/* translators: %s: schedule name */
							esc_html__( 'Edit Schedule: %s', 'jezpress-woo-delivery-dates' ),
							'<em>' . esc_html( $def->name ) . '</em>'
						);
						?>
					<?php else : ?>
						<?php esc_html_e( 'Add Schedule', 'jezpress-woo-delivery-dates' ); ?>
					<?php endif; ?>
				</h2>

				<div id="jwdd-def-feedback" class="jwdd-feedback" style="display:none;"></div>

				<table class="form-table" role="presentation">
					<tr>
						<th><label for="jwdd_def_name"><?php esc_html_e( 'Name', 'jezpress-woo-delivery-dates' ); ?> <span class="required">*</span></label></th>
						<td>
							<input type="text" id="jwdd_def_name" class="regular-text"
								   value="<?php echo $is_edit ? esc_attr( $def->name ) : ''; ?>"
								   placeholder="<?php esc_attr_e( 'e.g. Morning Delivery', 'jezpress-woo-delivery-dates' ); ?>">
						</td>
					</tr>
					<tr>
						<th><label for="jwdd_def_carrier"><?php esc_html_e( 'Carrier', 'jezpress-woo-delivery-dates' ); ?></label></th>
						<td>
							<select id="jwdd_def_carrier">
								<option value="0"><?php esc_html_e( '— No specific carrier —', 'jezpress-woo-delivery-dates' ); ?></option>
								<?php foreach ( $carriers as $c ) : ?>
									<option value="<?php echo esc_attr( $c->id ); ?>"
										<?php selected( $is_edit ? (int) $def->carrier_id : 0, (int) $c->id ); ?>>
										<?php echo esc_html( $c->name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th style="vertical-align:top; padding-top:14px;"><?php esc_html_e( 'Days &amp; Times', 'jezpress-woo-delivery-dates' ); ?></th>
						<td>
							<?php foreach ( $dow_labels as $num => $lbl ) : ?>
								<?php
								$is_checked = isset( $days_config[ $num ] );
								$cutoff_val = $is_checked ? $days_config[ $num ]['cutoff'] : '';
								$slot_count = $is_checked ? count( $days_config[ $num ]['slots'] ) : 0;
								?>
								<div class="jwdd-dow-row">
									<label class="jwdd-dow-label">
										<input type="checkbox" class="jwdd-def-dow" value="<?php echo esc_attr( $num ); ?>"
											<?php checked( $is_checked ); ?>>
										<?php echo esc_html( $lbl ); ?>
									</label>
									<div class="jwdd-dow-times">
										<label class="jwdd-dow-cutoff-lbl"><?php esc_html_e( 'Cutoff:', 'jezpress-woo-delivery-dates' ); ?></label>
										<input type="time" id="jwdd_dow_cutoff_<?php echo esc_attr( $num ); ?>"
											   class="jwdd-dow-cutoff"
											   value="<?php echo esc_attr( $cutoff_val ); ?>"
											   <?php echo ! $is_checked ? 'disabled' : ''; ?>>
										<button type="button" class="button button-small jwdd-dow-cutoff-clear"
											   title="<?php esc_attr_e( 'Clear cutoff time', 'jezpress-woo-delivery-dates' ); ?>"
											   <?php echo ! $is_checked ? 'disabled' : ''; ?>>&#x2715;</button>
										<button type="button" class="button button-small jwdd-dow-slots-btn"
											   data-day="<?php echo esc_attr( $num ); ?>"
											   data-name="<?php echo esc_attr( $lbl ); ?>"
											   <?php echo ! $is_checked ? 'disabled' : ''; ?>>
											<?php esc_html_e( 'Time Slots', 'jezpress-woo-delivery-dates' ); ?>
											<span class="jwdd-dow-slot-count">(<?php echo absint( $slot_count ); ?>)</span>
										</button>
									</div>
								</div>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Active', 'jezpress-woo-delivery-dates' ); ?></th>
						<td>
							<label>
								<input type="checkbox" id="jwdd_def_is_active" value="1"
									<?php checked( $is_edit ? (bool) $def->is_active : true ); ?>>
								<?php esc_html_e( 'Enabled', 'jezpress-woo-delivery-dates' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<input type="hidden" id="jwdd_def_id" value="<?php echo esc_attr( $def_id ); ?>">

				<p>
					<button type="button" class="button button-primary" id="jwdd-save-schedule-def">
						<?php echo $is_edit
							? esc_html__( 'Update Schedule', 'jezpress-woo-delivery-dates' )
							: esc_html__( 'Add Schedule', 'jezpress-woo-delivery-dates' ); ?>
					</button>
				</p>
			</div>

		<!-- Time slots modal -->
		<div id="jwdd-slot-modal" class="jwdd-modal" style="display:none;" role="dialog" aria-modal="true">
			<div class="jwdd-modal-backdrop"></div>
			<div class="jwdd-modal-box">
				<div class="jwdd-modal-header">
					<h3 id="jwdd-modal-title"><?php esc_html_e( 'Time Slots', 'jezpress-woo-delivery-dates' ); ?></h3>
					<button type="button" class="jwdd-modal-close" aria-label="<?php esc_attr_e( 'Close', 'jezpress-woo-delivery-dates' ); ?>">&#x2715;</button>
				</div>
				<div class="jwdd-modal-body">
					<div id="jwdd-modal-slots-list"></div>
					<hr class="jwdd-modal-divider">
					<h4><?php esc_html_e( 'Add Time Slot', 'jezpress-woo-delivery-dates' ); ?></h4>
					<div class="jwdd-modal-form">
						<label>
							<?php esc_html_e( 'Start:', 'jezpress-woo-delivery-dates' ); ?>
							<input type="time" id="jwdd_slot_start" value="09:00">
						</label>
						<label>
							<?php esc_html_e( 'End:', 'jezpress-woo-delivery-dates' ); ?>
							<input type="time" id="jwdd_slot_end" value="12:00">
						</label>
						<input type="text" id="jwdd_slot_label" class="regular-text"
							   placeholder="<?php esc_attr_e( 'Label (e.g. Morning Delivery)', 'jezpress-woo-delivery-dates' ); ?>">
						<button type="button" class="button button-primary" id="jwdd-modal-add-slot">
							<?php esc_html_e( 'Add Slot', 'jezpress-woo-delivery-dates' ); ?>
						</button>
					</div>
				</div>
			</div>
		</div>

		</div>
		<?php
	}

	/**
	 * Format a days_of_week JSON string into a human-readable abbreviated list.
	 *
	 * @param string $days_json JSON-encoded array of day numbers (0–6).
	 * @return string
	 */
	private function format_days( $days_json ) {
		$map  = array( 0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat' );
		$days = json_decode( $days_json, true );
		if ( empty( $days ) ) {
			return '—';
		}
		$labels = array();
		foreach ( $days as $d ) {
			$day_num = is_array( $d ) ? ( isset( $d['day'] ) ? (int) $d['day'] : null ) : (int) $d;
			if ( null !== $day_num && isset( $map[ $day_num ] ) ) {
				$labels[] = $map[ $day_num ];
			}
		}
		return implode( ', ', $labels );
	}
}
