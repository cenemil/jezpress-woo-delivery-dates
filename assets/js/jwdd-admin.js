/**
 * JWDD Admin JavaScript
 *
 * Handles the Carriers and Schedules tabs:
 * - Carrier add/edit/delete via AJAX
 * - Schedule add/edit/delete via AJAX
 * - Schedule list filter via AJAX
 * - Recurring slot generation via AJAX
 */

( function () {
	'use strict';

	var cfg = window.jwdd_admin || {};
	var ajaxurl = cfg.ajaxurl || '';
	var nonce   = cfg.nonce   || '';
	var i18n    = cfg.i18n   || {};

	// -------------------------------------------------------------------------
	// Utility helpers
	// -------------------------------------------------------------------------

	function post( action, data, callback ) {
		data.action = action;
		data.nonce  = nonce;

		fetch( ajaxurl, {
			method:  'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body:    new URLSearchParams( data ),
		} )
			.then( function ( res ) { return res.json(); } )
			.then( callback )
			.catch( function () {
				callback( { success: false, data: { message: i18n.error } } );
			} );
	}

	function showFeedback( el, message, type ) {
		if ( ! el ) return;
		el.textContent  = message;
		el.className    = 'jwdd-feedback jwdd-feedback-' + ( type || 'success' );
		el.style.display = 'block';
		setTimeout( function () { el.style.display = 'none'; }, 4000 );
	}

	function slugify( str ) {
		return str.toLowerCase().replace( /[^a-z0-9]+/g, '-' ).replace( /^-|-$/g, '' );
	}

	// -------------------------------------------------------------------------
	// CARRIERS TAB
	// -------------------------------------------------------------------------

	var carrierFeedback = document.getElementById( 'jwdd-carriers-feedback' );
	var carrierTbody    = document.getElementById( 'jwdd-carriers-tbody' );
	var carrierEmptyRow = document.getElementById( 'jwdd-carriers-empty' );
	var saveCarrierBtn  = document.getElementById( 'jwdd-save-carrier' );
	var cancelCarrierBtn = document.getElementById( 'jwdd-cancel-carrier' );
	var carrierFormTitle = document.getElementById( 'jwdd-carrier-form-title' );

	var idField          = document.getElementById( 'jwdd_carrier_id' );
	var nameField        = document.getElementById( 'jwdd_carrier_name' );
	var codeField        = document.getElementById( 'jwdd_carrier_code' );
	var descField        = document.getElementById( 'jwdd_carrier_description' );
	var sortField        = document.getElementById( 'jwdd_carrier_sort_order' );
	var activeField      = document.getElementById( 'jwdd_carrier_is_active' );

	function resetCarrierForm() {
		if ( idField ) idField.value = '0';
		if ( nameField ) nameField.value = '';
		if ( codeField ) codeField.value = '';
		if ( descField ) descField.value = '';
		if ( sortField ) sortField.value = '0';
		if ( activeField ) activeField.checked = true;
		if ( carrierFormTitle ) carrierFormTitle.textContent = i18n.add_carrier || 'Add Carrier';
		if ( saveCarrierBtn ) saveCarrierBtn.textContent = ( i18n.add_carrier || 'Add Carrier' );
		if ( cancelCarrierBtn ) cancelCarrierBtn.style.display = 'none';
	}

	function renderCarrierRow( c ) {
		var badge = c.is_active == 1
			? '<span class="jwdd-badge jwdd-badge-active">Active</span>'
			: '<span class="jwdd-badge jwdd-badge-inactive">Inactive</span>';

		return '<tr id="jwdd-carrier-row-' + c.id + '">'
			+ '<td><strong>' + escHtml( c.name ) + '</strong></td>'
			+ '<td><code>' + escHtml( c.code ) + '</code></td>'
			+ '<td>' + escHtml( c.description ) + '</td>'
			+ '<td>' + escHtml( String( c.sort_order ) ) + '</td>'
			+ '<td>' + badge + '</td>'
			+ '<td>'
			+ '<button class="button button-small jwdd-edit-carrier"'
			+ ' data-id="' + c.id + '" data-name="' + escAttr( c.name ) + '"'
			+ ' data-code="' + escAttr( c.code ) + '" data-description="' + escAttr( c.description ) + '"'
			+ ' data-sort_order="' + c.sort_order + '" data-is_active="' + c.is_active + '">Edit</button> '
			+ '<button class="button button-small jwdd-delete-carrier"'
			+ ' data-id="' + c.id + '" data-name="' + escAttr( c.name ) + '">Delete</button>'
			+ '</td>'
			+ '</tr>';
	}

	if ( saveCarrierBtn ) {
		saveCarrierBtn.addEventListener( 'click', function () {
			var name = nameField ? nameField.value.trim() : '';
			if ( ! name ) {
				showFeedback( carrierFeedback, 'Carrier name is required.', 'error' );
				return;
			}

			var code = codeField && codeField.value.trim() ? codeField.value.trim() : slugify( name );
			saveCarrierBtn.textContent = i18n.saving || 'Saving...';
			saveCarrierBtn.disabled    = true;

			post( 'jwdd_save_carrier', {
				id:          idField ? idField.value : '0',
				name:        name,
				code:        code,
				description: descField ? descField.value : '',
				sort_order:  sortField ? sortField.value : '0',
				is_active:   activeField && activeField.checked ? '1' : '0',
			}, function ( res ) {
				saveCarrierBtn.disabled = false;
				resetCarrierForm();

				if ( res.success ) {
					showFeedback( carrierFeedback, res.data.message, 'success' );
					var carrier = res.data.carrier;
					var existingRow = document.getElementById( 'jwdd-carrier-row-' + carrier.id );

					if ( existingRow ) {
						existingRow.outerHTML = renderCarrierRow( carrier );
					} else {
						if ( carrierEmptyRow ) carrierEmptyRow.remove();
						carrierTbody.insertAdjacentHTML( 'beforeend', renderCarrierRow( carrier ) );
					}

					bindCarrierActions();
				} else {
					showFeedback( carrierFeedback, res.data.message, 'error' );
				}
			} );
		} );
	}

	if ( cancelCarrierBtn ) {
		cancelCarrierBtn.addEventListener( 'click', resetCarrierForm );
	}

	function bindCarrierActions() {
		// Edit buttons
		document.querySelectorAll( '.jwdd-edit-carrier' ).forEach( function ( btn ) {
			btn.onclick = function () {
				if ( idField ) idField.value = btn.dataset.id;
				if ( nameField ) nameField.value = btn.dataset.name;
				if ( codeField ) codeField.value = btn.dataset.code;
				if ( descField ) descField.value = btn.dataset.description;
				if ( sortField ) sortField.value = btn.dataset.sort_order;
				if ( activeField ) activeField.checked = btn.dataset.is_active === '1';
				if ( carrierFormTitle ) carrierFormTitle.textContent = 'Edit Carrier';
				if ( saveCarrierBtn ) saveCarrierBtn.textContent = 'Update Carrier';
				if ( cancelCarrierBtn ) cancelCarrierBtn.style.display = '';
				document.getElementById( 'jwdd-carrier-form-wrap' ).scrollIntoView( { behavior: 'smooth' } );
			};
		} );

		// Delete buttons
		document.querySelectorAll( '.jwdd-delete-carrier' ).forEach( function ( btn ) {
			btn.onclick = function () {
				var name = btn.dataset.name;
				if ( ! confirm( ( i18n.confirm_delete_carrier || 'Delete this carrier and all its schedules?' ).replace( '%s', name ) ) ) {
					return;
				}

				btn.textContent = i18n.deleting || 'Deleting...';
				btn.disabled    = true;

				post( 'jwdd_delete_carrier', { id: btn.dataset.id }, function ( res ) {
					if ( res.success ) {
						var row = document.getElementById( 'jwdd-carrier-row-' + btn.dataset.id );
						if ( row ) row.remove();
						showFeedback( carrierFeedback, res.data.message, 'success' );
						if ( carrierTbody && carrierTbody.querySelectorAll( 'tr' ).length === 0 ) {
							carrierTbody.innerHTML = '<tr id="jwdd-carriers-empty"><td colspan="6">' + ( i18n.no_carriers || 'No carriers yet.' ) + '</td></tr>';
						}
					} else {
						btn.textContent = 'Delete';
						btn.disabled    = false;
						showFeedback( carrierFeedback, res.data.message, 'error' );
					}
				} );
			};
		} );
	}

	bindCarrierActions();

	// -------------------------------------------------------------------------
	// SCHEDULES TAB
	// -------------------------------------------------------------------------

	var scheduleFeedback  = document.getElementById( 'jwdd-schedules-feedback' );
	var schedulesTbody    = document.getElementById( 'jwdd-schedules-tbody' );
	var saveScheduleBtn   = document.getElementById( 'jwdd-save-schedule' );
	var cancelScheduleBtn = document.getElementById( 'jwdd-cancel-schedule' );
	var schFormTitle      = document.getElementById( 'jwdd-schedule-form-title' );
	var schIdField        = document.getElementById( 'jwdd_schedule_id' );

	function resetScheduleForm() {
		if ( schIdField ) schIdField.value = '0';
		if ( document.getElementById( 'jwdd_schedule_carrier' ) ) document.getElementById( 'jwdd_schedule_carrier' ).value = '0';
		if ( document.getElementById( 'jwdd_schedule_label' ) ) document.getElementById( 'jwdd_schedule_label' ).value = '';
		if ( document.getElementById( 'jwdd_schedule_max' ) ) document.getElementById( 'jwdd_schedule_max' ).value = '0';
		if ( document.getElementById( 'jwdd_schedule_is_active' ) ) document.getElementById( 'jwdd_schedule_is_active' ).checked = true;
		if ( schFormTitle ) schFormTitle.textContent = 'Add Schedule Slot';
		if ( saveScheduleBtn ) saveScheduleBtn.textContent = 'Add Slot';
		if ( cancelScheduleBtn ) cancelScheduleBtn.style.display = 'none';
	}

	function renderScheduleRow( s ) {
		var badge = s.is_active == 1
			? '<span class="jwdd-badge jwdd-badge-active">Active</span>'
			: '<span class="jwdd-badge jwdd-badge-inactive">Inactive</span>';
		var maxDisplay = s.max_orders == 0 ? '∞' : s.max_orders;
		var dateDisplay = formatDate( s.schedule_date );

		return '<tr id="jwdd-schedule-row-' + s.id + '">'
			+ '<td>' + escHtml( dateDisplay ) + '</td>'
			+ '<td>' + escHtml( s.carrier_name || '—' ) + '</td>'
			+ '<td>' + escHtml( s.label ) + '</td>'
			+ '<td>' + maxDisplay + '</td>'
			+ '<td>' + escHtml( String( s.booked_count ) ) + '</td>'
			+ '<td>' + badge + '</td>'
			+ '<td>'
			+ '<button class="button button-small jwdd-edit-schedule"'
			+ ' data-id="' + s.id + '"'
			+ ' data-carrier_id="' + s.carrier_id + '"'
			+ ' data-schedule_date="' + escAttr( s.schedule_date ) + '"'
			+ ' data-start_time="' + escAttr( s.start_time ) + '"'
			+ ' data-end_time="' + escAttr( s.end_time ) + '"'
			+ ' data-label="' + escAttr( s.label ) + '"'
			+ ' data-max_orders="' + s.max_orders + '"'
			+ ' data-is_active="' + s.is_active + '">Edit</button> '
			+ '<button class="button button-small jwdd-delete-schedule" data-id="' + s.id + '">Delete</button>'
			+ '</td>'
			+ '</tr>';
	}

	function loadSchedules() {
		if ( ! schedulesTbody ) return;

		var carrier  = document.getElementById( 'jwdd-filter-carrier' );
		var dateFrom = document.getElementById( 'jwdd-filter-date-from' );
		var dateTo   = document.getElementById( 'jwdd-filter-date-to' );

		post( 'jwdd_get_schedules', {
			carrier_id: carrier ? carrier.value : '',
			date_from:  dateFrom ? dateFrom.value : '',
			date_to:    dateTo ? dateTo.value : '',
		}, function ( res ) {
			if ( ! res.success ) {
				schedulesTbody.innerHTML = '<tr><td colspan="7">' + escHtml( res.data.message || i18n.error ) + '</td></tr>';
				return;
			}

			var schedules = res.data.schedules;
			if ( ! schedules || schedules.length === 0 ) {
				schedulesTbody.innerHTML = '<tr><td colspan="7">' + ( i18n.no_schedules || 'No schedule slots found.' ) + '</td></tr>';
				return;
			}

			schedulesTbody.innerHTML = schedules.map( renderScheduleRow ).join( '' );
			bindScheduleActions();
		} );
	}

	function bindScheduleActions() {
		document.querySelectorAll( '.jwdd-edit-schedule' ).forEach( function ( btn ) {
			btn.onclick = function () {
				if ( schIdField ) schIdField.value = btn.dataset.id;
				var el;
				if ( ( el = document.getElementById( 'jwdd_schedule_carrier' ) ) ) el.value = btn.dataset.carrier_id;
				if ( ( el = document.getElementById( 'jwdd_schedule_date' ) ) ) el.value = btn.dataset.schedule_date;
				if ( ( el = document.getElementById( 'jwdd_schedule_start' ) ) ) el.value = btn.dataset.start_time.substring( 0, 5 );
				if ( ( el = document.getElementById( 'jwdd_schedule_end' ) ) ) el.value = btn.dataset.end_time.substring( 0, 5 );
				if ( ( el = document.getElementById( 'jwdd_schedule_label' ) ) ) el.value = btn.dataset.label;
				if ( ( el = document.getElementById( 'jwdd_schedule_max' ) ) ) el.value = btn.dataset.max_orders;
				if ( ( el = document.getElementById( 'jwdd_schedule_is_active' ) ) ) el.checked = btn.dataset.is_active === '1';
				if ( schFormTitle ) schFormTitle.textContent = 'Edit Schedule Slot';
				if ( saveScheduleBtn ) saveScheduleBtn.textContent = 'Update Slot';
				if ( cancelScheduleBtn ) cancelScheduleBtn.style.display = '';
				document.getElementById( 'jwdd-schedule-form-wrap' ).scrollIntoView( { behavior: 'smooth' } );
			};
		} );

		document.querySelectorAll( '.jwdd-delete-schedule' ).forEach( function ( btn ) {
			btn.onclick = function () {
				if ( ! confirm( i18n.confirm_delete_schedule || 'Delete this schedule slot?' ) ) return;

				btn.textContent = i18n.deleting || 'Deleting...';
				btn.disabled    = true;

				post( 'jwdd_delete_schedule', { id: btn.dataset.id }, function ( res ) {
					if ( res.success ) {
						var row = document.getElementById( 'jwdd-schedule-row-' + btn.dataset.id );
						if ( row ) row.remove();
						showFeedback( scheduleFeedback, res.data.message, 'success' );
					} else {
						btn.textContent = 'Delete';
						btn.disabled    = false;
						showFeedback( scheduleFeedback, res.data.message, 'error' );
					}
				} );
			};
		} );
	}

	// Filter button
	var filterBtn = document.getElementById( 'jwdd-filter-schedules' );
	if ( filterBtn ) {
		filterBtn.addEventListener( 'click', loadSchedules );
	}

	// Save schedule
	if ( saveScheduleBtn ) {
		saveScheduleBtn.addEventListener( 'click', function () {
			var date  = document.getElementById( 'jwdd_schedule_date' );
			var start = document.getElementById( 'jwdd_schedule_start' );
			var end   = document.getElementById( 'jwdd_schedule_end' );

			if ( ! date || ! date.value ) { showFeedback( scheduleFeedback, 'Date is required.', 'error' ); return; }
			if ( ! start || ! start.value ) { showFeedback( scheduleFeedback, 'Start time is required.', 'error' ); return; }
			if ( ! end || ! end.value ) { showFeedback( scheduleFeedback, 'End time is required.', 'error' ); return; }

			saveScheduleBtn.textContent = i18n.saving || 'Saving...';
			saveScheduleBtn.disabled    = true;

			post( 'jwdd_save_schedule', {
				id:            schIdField ? schIdField.value : '0',
				carrier_id:    document.getElementById( 'jwdd_schedule_carrier' ) ? document.getElementById( 'jwdd_schedule_carrier' ).value : '0',
				schedule_date: date.value,
				start_time:    start.value,
				end_time:      end.value,
				label:         document.getElementById( 'jwdd_schedule_label' ) ? document.getElementById( 'jwdd_schedule_label' ).value : '',
				max_orders:    document.getElementById( 'jwdd_schedule_max' ) ? document.getElementById( 'jwdd_schedule_max' ).value : '0',
				is_active:     document.getElementById( 'jwdd_schedule_is_active' ) && document.getElementById( 'jwdd_schedule_is_active' ).checked ? '1' : '0',
			}, function ( res ) {
				saveScheduleBtn.disabled = false;
				resetScheduleForm();

				if ( res.success ) {
					showFeedback( scheduleFeedback, res.data.message, 'success' );
					loadSchedules();
				} else {
					showFeedback( scheduleFeedback, res.data.message, 'error' );
				}
			} );
		} );
	}

	if ( cancelScheduleBtn ) {
		cancelScheduleBtn.addEventListener( 'click', resetScheduleForm );
	}

	// -------------------------------------------------------------------------
	// GENERATE RECURRING
	// -------------------------------------------------------------------------

	var generateBtn = document.getElementById( 'jwdd-generate-recurring' );
	var recurringFeedback = document.getElementById( 'jwdd-recurring-feedback' );

	if ( generateBtn ) {
		generateBtn.addEventListener( 'click', function () {
			var startDate  = document.getElementById( 'jwdd_rec_start' );
			var endDate    = document.getElementById( 'jwdd_rec_end' );
			var startTime  = document.getElementById( 'jwdd_rec_start_time' );
			var endTime    = document.getElementById( 'jwdd_rec_end_time' );
			var carrierId  = document.getElementById( 'jwdd_rec_carrier' );
			var label      = document.getElementById( 'jwdd_rec_label' );
			var maxOrders  = document.getElementById( 'jwdd_rec_max' );

			var days = [];
			document.querySelectorAll( '.jwdd-rec-dow:checked' ).forEach( function ( cb ) {
				days.push( cb.value );
			} );

			if ( ! startDate || ! startDate.value || ! endDate || ! endDate.value ) {
				showFeedback( recurringFeedback, 'Start and end dates are required.', 'error' ); return;
			}
			if ( days.length === 0 ) {
				showFeedback( recurringFeedback, 'Select at least one day of the week.', 'error' ); return;
			}
			if ( ! startTime || ! startTime.value || ! endTime || ! endTime.value ) {
				showFeedback( recurringFeedback, 'Start and end times are required.', 'error' ); return;
			}

			if ( ! confirm( i18n.confirm_generate || 'Generate recurring slots for the selected date range and days?' ) ) {
				return;
			}

			generateBtn.textContent = i18n.generating || 'Generating...';
			generateBtn.disabled    = true;

			var params = {
				carrier_id:  carrierId ? carrierId.value : '0',
				start_date:  startDate.value,
				end_date:    endDate.value,
				start_time:  startTime.value,
				end_time:    endTime.value,
				label:       label ? label.value : '',
				max_orders:  maxOrders ? maxOrders.value : '0',
			};

			// Append days array as days_of_week[].
			days.forEach( function ( d ) { params[ 'days_of_week[]' ] = d; } );

			// URLSearchParams doesn't support duplicate keys the way we need, so build body manually.
			var body = new URLSearchParams();
			body.append( 'action', 'jwdd_generate_recurring' );
			body.append( 'nonce', nonce );
			body.append( 'carrier_id', params.carrier_id );
			body.append( 'start_date', params.start_date );
			body.append( 'end_date', params.end_date );
			body.append( 'start_time', params.start_time );
			body.append( 'end_time', params.end_time );
			body.append( 'label', params.label );
			body.append( 'max_orders', params.max_orders );
			days.forEach( function ( d ) { body.append( 'days_of_week[]', d ); } );

			fetch( ajaxurl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body,
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					generateBtn.disabled    = false;
					generateBtn.textContent = 'Generate Slots';

					if ( res.success ) {
						showFeedback( recurringFeedback, res.data.message, 'success' );
						loadSchedules();
					} else {
						showFeedback( recurringFeedback, res.data.message || i18n.error, 'error' );
					}
				} )
				.catch( function () {
					generateBtn.disabled    = false;
					generateBtn.textContent = 'Generate Slots';
					showFeedback( recurringFeedback, i18n.error, 'error' );
				} );
		} );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	function escHtml( str ) {
		var d = document.createElement( 'div' );
		d.textContent = str || '';
		return d.innerHTML;
	}

	function escAttr( str ) {
		return ( str || '' ).replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
	}

	function formatDate( dateStr ) {
		if ( ! dateStr ) return '';
		var parts = dateStr.split( '-' );
		if ( parts.length !== 3 ) return dateStr;
		var d = new Date( parts[0], parts[1] - 1, parts[2] );
		return d.toLocaleDateString( undefined, { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' } );
	}

	// Load schedules on page load if on the schedules tab.
	if ( schedulesTbody ) {
		loadSchedules();
	}

} )();
