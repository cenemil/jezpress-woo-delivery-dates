/**
 * JWDD Checkout JavaScript
 *
 * - Watches the delivery date selector and loads available time slots via AJAX.
 * - Optionally filters by selected carrier.
 * - Handles loading state and empty/error messages.
 */

( function ( $ ) {
	'use strict';

	var cfg         = window.jwdd_checkout || {};
	var ajaxurl     = cfg.ajaxurl || '';
	var nonce       = cfg.nonce   || '';
	var i18n        = cfg.i18n   || {};
	var showCarrier = !! cfg.show_carrier;

	var $dateSelect    = null;
	var $slotSelect    = null;
	var $carrierSelect = null;

	function init() {
		$dateSelect    = $( '#jwdd_delivery_date' );
		$slotSelect    = $( '#jwdd_time_slot_id' );
		$carrierSelect = $( '#jwdd_carrier_id' );

		if ( ! $dateSelect.length || ! $slotSelect.length ) {
			return;
		}

		$dateSelect.on( 'change', onDateChange );

		if ( showCarrier && $carrierSelect.length ) {
			$carrierSelect.on( 'change', onDateChange );
		}
	}

	function onDateChange() {
		var date       = $dateSelect.val();
		var carrierId  = showCarrier && $carrierSelect.length ? $carrierSelect.val() : '';

		$slotSelect.empty().prop( 'disabled', true );

		if ( ! date ) {
			$slotSelect.append( $( '<option>', { value: '', text: i18n.select_date || 'Select a date first...' } ) );
			return;
		}

		$slotSelect.append( $( '<option>', { value: '', text: i18n.loading || 'Loading time slots...' } ) );

		$.post( ajaxurl, {
			action:     'jwdd_get_time_slots',
			nonce:      nonce,
			date:       date,
			carrier_id: carrierId || '',
		} )
		.done( function ( response ) {
			$slotSelect.empty();

			if ( ! response.success || ! response.data || ! response.data.slots ) {
				$slotSelect.append( $( '<option>', { value: '', text: i18n.error || 'Could not load time slots.' } ) );
				return;
			}

			var slots = response.data.slots;

			if ( slots.length === 0 ) {
				$slotSelect.append( $( '<option>', { value: '', text: i18n.no_slots || 'No time slots available for this date.' } ) );
				return;
			}

			$slotSelect.append( $( '<option>', { value: '', text: i18n.select_slot || 'Select a time slot...' } ) );

			$.each( slots, function ( index, slot ) {
				var label = slot.label;
				if ( showCarrier && slot.carrier_name ) {
					label += ' (' + slot.carrier_name + ')';
				}
				$slotSelect.append( $( '<option>', { value: slot.id, text: label } ) );
			} );

			$slotSelect.prop( 'disabled', false );
		} )
		.fail( function () {
			$slotSelect.empty().append( $( '<option>', { value: '', text: i18n.error || 'Could not load time slots.' } ) );
		} );
	}

	// Initialise on document ready and re-init after WooCommerce updates the
	// checkout (e.g. on shipping method change which reloads checkout fragments).
	$( document ).ready( init );
	$( document.body ).on( 'updated_checkout', init );

} )( jQuery );
