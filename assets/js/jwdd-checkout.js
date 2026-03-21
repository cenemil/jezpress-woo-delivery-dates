/**
 * JWDD Checkout JavaScript
 *
 * - Initialises a jQuery UI Datepicker restricted to available delivery dates.
 * - Refreshes available dates via AJAX whenever WooCommerce updates the checkout
 *   (e.g. on address or shipping method change).
 * - Hides the delivery fields when the shipping address is incomplete.
 * - On date selection loads available time slots via AJAX.
 */

( function ( $ ) {
	'use strict';

	var cfg            = window.jwdd_checkout || {};
	var ajaxurl        = cfg.ajaxurl        || '';
	var nonce          = cfg.nonce          || '';
	var i18n           = cfg.i18n           || {};
	var availableDates = cfg.available_dates || [];
	var maxFutureDays  = cfg.max_future_days || 30;
	var hasAddress     = !! cfg.has_address;
	var weekStart      = cfg.week_start !== undefined ? parseInt( cfg.week_start, 10 ) : 0;
	var dateFormat     = cfg.date_format || 'MM d, yy';

	var $wrap       = null;
	var $statusMsg  = null;
	var $dateRow    = null;
	var $slotRow    = null;
	var $datePicker = null;
	var $dateInput  = null;
	var $slotSelect = null;

	// -------------------------------------------------------------------------
	// Initialise
	// -------------------------------------------------------------------------

	function init() {
		$wrap       = $( '#jwdd-delivery-dates-wrap' );
		$statusMsg  = $( '#jwdd-date-status' );
		$dateRow    = $( '.jwdd-date-row' );
		$slotRow    = $( '.jwdd-slot-row' );
		$datePicker = $( '#jwdd_delivery_date_picker' );
		$dateInput  = $( '#jwdd_delivery_date' );
		$slotSelect = $( '#jwdd_time_slot_id' );

		if ( ! $wrap.length ) {
			return;
		}

		applyState( hasAddress, availableDates );
	}

	// -------------------------------------------------------------------------
	// State management
	// -------------------------------------------------------------------------

	function applyState( addressOk, dates ) {
		if ( ! addressOk ) {
			showStatus( i18n.address_required || 'Enter your shipping address to see available delivery dates.' );
			return;
		}

		if ( ! dates || dates.length === 0 ) {
			showStatus( i18n.no_dates || 'No delivery dates are currently available for your area.' );
			return;
		}

		availableDates = dates;
		showPickers();
		initDatepicker();
	}

	function showStatus( message ) {
		$dateRow.hide();
		$slotRow.hide();
		$statusMsg.text( message ).show();
	}

	function showPickers() {
		$statusMsg.hide();
		$dateRow.show();
	}

	// -------------------------------------------------------------------------
	// Datepicker
	// -------------------------------------------------------------------------

	function initDatepicker() {
		if ( ! $datePicker.length ) {
			return;
		}

		if ( $datePicker.hasClass( 'hasDatepicker' ) ) {
			$datePicker.datepicker( 'destroy' );
		}

		$datePicker.datepicker( {
			dateFormat:    dateFormat,
			altField:      '#jwdd_delivery_date',
			altFormat:     'yy-mm-dd',
			firstDay:      weekStart,
			minDate:       0,
			maxDate:       maxFutureDays,
			beforeShowDay: function ( date ) {
				var dateStr = $.datepicker.formatDate( 'yy-mm-dd', date );
				return [ availableDates.indexOf( dateStr ) !== -1, '' ];
			},
			onSelect: function () {
				onDateChange();
			},
		} );
	}

	function resetSelection() {
		if ( $datePicker && $datePicker.hasClass( 'hasDatepicker' ) ) {
			$datePicker.datepicker( 'setDate', null );
		}
		if ( $datePicker ) { $datePicker.val( '' ); }
		if ( $dateInput )  { $dateInput.val( '' ); }
		if ( $slotSelect ) {
			$slotSelect
				.empty()
				.append( $( '<option>', { value: '', text: i18n.select_date || 'Select a date first...' } ) )
				.prop( 'disabled', true );
		}
		if ( $slotRow ) { $slotRow.hide(); }
	}

	// -------------------------------------------------------------------------
	// Dynamic date refresh (fires after WooCommerce checkout AJAX update)
	// -------------------------------------------------------------------------

	function refreshDates() {
		if ( ! $wrap || ! $wrap.length ) {
			return;
		}

		resetSelection();
		showStatus( i18n.loading_dates || 'Checking available delivery dates\u2026' );

		$.post( ajaxurl, {
			action: 'jwdd_get_available_dates',
			nonce:  nonce,
		} )
		.done( function ( response ) {
			if ( ! response.success || ! response.data ) {
				showStatus( i18n.error || 'Could not load delivery dates. Please refresh the page.' );
				return;
			}

			hasAddress     = !! response.data.has_address;
			availableDates = response.data.dates || [];
			applyState( hasAddress, availableDates );
		} )
		.fail( function () {
			showStatus( i18n.error || 'Could not load delivery dates. Please refresh the page.' );
		} );
	}

	// -------------------------------------------------------------------------
	// Time slot loading
	// -------------------------------------------------------------------------

	/**
	 * Populate the time slot select from a slots array and show/hide the row.
	 *
	 * @param {Array} slots Array of slot objects with id and label properties.
	 */
	function renderSlots( slots ) {
		$slotSelect.empty();

		if ( ! slots || slots.length === 0 ) {
			$slotRow.hide();
			return;
		}

		$slotSelect.append( $( '<option>', { value: '', text: i18n.select_slot || 'Select a time slot...' } ) );

		$.each( slots, function ( index, slot ) {
			$slotSelect.append( $( '<option>', { value: slot.id, text: slot.label } ) );
		} );

		$slotSelect.prop( 'disabled', false );
		$slotRow.show();
	}

	function onDateChange() {
		var date = $dateInput.val();

		$slotSelect.empty().prop( 'disabled', true );

		if ( ! date ) {
			$slotSelect.append( $( '<option>', { value: '', text: i18n.select_date || 'Select a date first...' } ) );
			return;
		}

		// Use inline slot data when available (localised at page load for ≤30 schedule defs).
		// Falls back to AJAX for dates not present in the map (e.g. after an address change
		// refreshes available dates with a different carrier context).
		if ( cfg.slots_by_date && Object.prototype.hasOwnProperty.call( cfg.slots_by_date, date ) ) {
			renderSlots( cfg.slots_by_date[ date ] );
			return;
		}

		$slotSelect.append( $( '<option>', { value: '', text: i18n.loading || 'Loading time slots...' } ) );

		$.post( ajaxurl, {
			action: 'jwdd_get_time_slots',
			nonce:  nonce,
			date:   date,
		} )
		.done( function ( response ) {
			$slotSelect.empty();

			if ( ! response.success || ! response.data || ! response.data.slots ) {
				$slotRow.hide();
				return;
			}

			renderSlots( response.data.slots );
		} )
		.fail( function () {
			$slotRow.hide();
		} );
	}

	// -------------------------------------------------------------------------
	// Event bindings
	// -------------------------------------------------------------------------

	$( document ).ready( init );

	// Show loading state immediately when WooCommerce starts its checkout update.
	$( document.body ).on( 'update_checkout', function () {
		if ( $wrap && $wrap.length ) {
			resetSelection();
			showStatus( i18n.loading_dates || 'Checking available delivery dates\u2026' );
		}
	} );

	// Refresh dates once WooCommerce finishes updating the checkout.
	$( document.body ).on( 'updated_checkout', function () {
		if ( ! $wrap ) {
			init();
		} else {
			refreshDates();
		}
	} );

} )( jQuery );
