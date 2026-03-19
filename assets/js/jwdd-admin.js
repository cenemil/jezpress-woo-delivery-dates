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
	var context = cfg.context || '';
	var defId   = parseInt( cfg.def_id || '0', 10 );

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

	if ( context === 'carriers_list' ) {
		bindCarrierListActions();
	}

	function bindCarrierListActions() {
		document.querySelectorAll( '.jwdd-delete-carrier' ).forEach( function ( btn ) {
			btn.onclick = function () {
				var name = btn.dataset.name || '';
				if ( ! confirm( ( i18n.confirm_delete_carrier || 'Delete this carrier and all its schedules?' ).replace( '%s', name ) ) ) {
					return;
				}

				btn.textContent = i18n.deleting || 'Deleting...';
				btn.disabled    = true;

				post( 'jwdd_delete_carrier', { id: btn.dataset.id }, function ( res ) {
					if ( res.success ) {
						var row = document.getElementById( 'jwdd-carrier-row-' + btn.dataset.id );
						if ( row ) row.remove();
						var feedback = document.getElementById( 'jwdd-carriers-feedback' );
						showFeedback( feedback, res.data.message, 'success' );
						var tbody = document.getElementById( 'jwdd-carriers-tbody' );
						if ( tbody && tbody.querySelectorAll( 'tr' ).length === 0 ) {
							tbody.innerHTML = '<tr id="jwdd-carriers-empty"><td colspan="6">' + ( i18n.no_carriers || 'No carriers yet.' ) + '</td></tr>';
						}
					} else {
						btn.textContent = 'Delete';
						btn.disabled    = false;
						showFeedback( document.getElementById( 'jwdd-carriers-feedback' ), res.data.message || i18n.error, 'error' );
					}
				} );
			};
		} );
	}

	if ( context === 'carriers_add' || context === 'carriers_edit' ) {
		initCarrierForm();
	}

	function initCarrierForm() {
		var saveBtn  = document.getElementById( 'jwdd-save-carrier' );
		var feedback = document.getElementById( 'jwdd-carrier-feedback' );

		if ( ! saveBtn ) return;

		initCarrierZoneRows();

		saveBtn.addEventListener( 'click', function () {
			var nameEl   = document.getElementById( 'jwdd_carrier_name' );
			var codeEl   = document.getElementById( 'jwdd_carrier_code' );
			var activeEl = document.getElementById( 'jwdd_carrier_is_active' );
			var idEl     = document.getElementById( 'jwdd_carrier_id' );

			var name = nameEl ? nameEl.value.trim() : '';
			if ( ! name ) {
				showFeedback( feedback, 'Carrier name is required.', 'error' );
				return;
			}

			var zones = [];
			document.querySelectorAll( '#jwdd-carrier-zones-list .jwdd-zone-row' ).forEach( function ( row ) {
				var sel     = row.querySelector( '.jwdd-zone-select' );
				var inp     = row.querySelector( '.jwdd-zone-est-days' );
				var estDays = inp ? inp.value.trim() : '';
				if ( estDays ) {
					zones.push( { zone_id: sel ? parseInt( sel.value, 10 ) : 0, est_days: estDays } );
				}
			} );

			var code = codeEl && codeEl.value.trim() ? codeEl.value.trim() : slugify( name );
			saveBtn.textContent = i18n.saving || 'Saving...';
			saveBtn.disabled    = true;

			post( 'jwdd_save_carrier', {
				id:                  idEl ? idEl.value : '0',
				name:                name,
				code:                code,
				is_active:           activeEl && activeEl.checked ? '1' : '0',
				shipping_zones_json: JSON.stringify( zones ),
			}, function ( res ) {
				saveBtn.disabled = false;

				if ( res.success ) {
					if ( context === 'carriers_add' ) {
						window.location.href = cfg.carrier_list_url || '';
					} else {
						saveBtn.textContent = 'Update Carrier';
						showFeedback( feedback, res.data.message, 'success' );
					}
				} else {
					saveBtn.textContent = context === 'carriers_add' ? 'Add Carrier' : 'Update Carrier';
					showFeedback( feedback, res.data.message || i18n.error, 'error' );
				}
			} );
		} );
	}

	function buildZoneRow( zoneId, estDays ) {
		var row = document.createElement( 'div' );
		row.className = 'jwdd-zone-row';

		var zoneField = document.createElement( 'label' );
		zoneField.className = 'jwdd-zone-field';

		var zoneSpan = document.createElement( 'span' );
		zoneSpan.textContent = 'Shipping Zone';
		zoneField.appendChild( zoneSpan );

		var select = document.createElement( 'select' );
		select.className = 'jwdd-zone-select';
		( cfg.wc_zones || [] ).forEach( function ( z ) {
			var opt = document.createElement( 'option' );
			opt.value = z.id;
			opt.textContent = z.name;
			if ( parseInt( zoneId, 10 ) === z.id ) { opt.selected = true; }
			select.appendChild( opt );
		} );
		zoneField.appendChild( select );

		var daysField = document.createElement( 'label' );
		daysField.className = 'jwdd-zone-field';

		var daysSpan = document.createElement( 'span' );
		daysSpan.textContent = 'Est. Delivery Days';
		daysField.appendChild( daysSpan );

		var input = document.createElement( 'input' );
		input.type = 'text';
		input.className = 'jwdd-zone-est-days';
		input.value = estDays || '';
		input.placeholder = 'e.g. 1\u20132 days';
		daysField.appendChild( input );

		var removeBtn = document.createElement( 'button' );
		removeBtn.type = 'button';
		removeBtn.className = 'button button-small jwdd-zone-remove';
		removeBtn.textContent = 'Remove';
		removeBtn.addEventListener( 'click', function () { row.remove(); } );

		row.appendChild( zoneField );
		row.appendChild( daysField );
		row.appendChild( removeBtn );

		return row;
	}

	function initCarrierZoneRows() {
		var list   = document.getElementById( 'jwdd-carrier-zones-list' );
		var addBtn = document.getElementById( 'jwdd-add-zone-row' );
		if ( ! list || ! addBtn ) return;

		( cfg.carrier_zones || [] ).forEach( function ( z ) {
			list.appendChild( buildZoneRow( z.zone_id, z.est_days ) );
		} );

		addBtn.addEventListener( 'click', function () {
			list.appendChild( buildZoneRow( 0, '' ) );
		} );
	}

	// -------------------------------------------------------------------------
	// SCHEDULES TAB
	// -------------------------------------------------------------------------

	// -- Schedule Defs List (schedules_list context) --

	if ( context === 'schedules_list' ) {
		bindScheduleDefActions();
	}

	function bindScheduleDefActions() {
		document.querySelectorAll( '.jwdd-delete-schedule-def' ).forEach( function ( btn ) {
			btn.onclick = function () {
				var name = btn.dataset.name || '';
				if ( ! confirm( ( i18n.confirm_delete_schedule || 'Delete this schedule and all its slots?' ).replace( '%s', name ) ) ) {
					return;
				}

				btn.textContent = i18n.deleting || 'Deleting...';
				btn.disabled    = true;

				post( 'jwdd_delete_schedule_def', { id: btn.dataset.id }, function ( res ) {
					if ( res.success ) {
						var row = document.getElementById( 'jwdd-def-row-' + btn.dataset.id );
						if ( row ) row.remove();
						var feedback = document.getElementById( 'jwdd-def-feedback' );
						showFeedback( feedback, res.data.message, 'success' );
						var tbody = document.getElementById( 'jwdd-schedule-defs-tbody' );
						if ( tbody && tbody.querySelectorAll( 'tr' ).length === 0 ) {
							tbody.innerHTML = '<tr id="jwdd-defs-empty"><td colspan="5">' + ( i18n.no_schedules || 'No schedules yet.' ) + '</td></tr>';
						}
					} else {
						btn.textContent = 'Delete';
						btn.disabled    = false;
						showFeedback( document.getElementById( 'jwdd-def-feedback' ), res.data.message || i18n.error, 'error' );
					}
				} );
			};
		} );
	}

	// -- Schedule Def Form (schedules_add and schedules_edit contexts) --

	// Per-day slot data: { dayNum: [{start, end, label}, ...], ... }
	var daySlots = {};
	if ( context === 'schedules_add' || context === 'schedules_edit' ) {
		Object.keys( cfg.day_slots || {} ).forEach( function ( k ) {
			daySlots[ parseInt( k, 10 ) ] = ( cfg.day_slots[ k ] || [] ).slice();
		} );
	}

	if ( context === 'schedules_add' || context === 'schedules_edit' ) {
		initDowToggles();
		initScheduleDefForm();
		initSlotModal();
	}

	function initDowToggles() {
		document.querySelectorAll( '.jwdd-def-dow' ).forEach( function ( cb ) {
			var row = cb.closest( '.jwdd-dow-row' );
			if ( ! row ) return;

			function syncRow() {
				row.querySelectorAll( '.jwdd-dow-cutoff, .jwdd-dow-cutoff-clear, .jwdd-dow-slots-btn' ).forEach( function ( el ) {
					el.disabled = ! cb.checked;
				} );
			}
			syncRow();
			cb.addEventListener( 'change', syncRow );

			var clearBtn = row.querySelector( '.jwdd-dow-cutoff-clear' );
			if ( clearBtn ) {
				clearBtn.addEventListener( 'click', function () {
					var cutoffInput = row.querySelector( '.jwdd-dow-cutoff' );
					if ( cutoffInput ) cutoffInput.value = '';
				} );
			}
		} );
	}

	function initScheduleDefForm() {
		var saveBtn  = document.getElementById( 'jwdd-save-schedule-def' );
		var feedback = document.getElementById( 'jwdd-def-feedback' );

		if ( ! saveBtn ) return;

		saveBtn.addEventListener( 'click', function () {
			var nameEl    = document.getElementById( 'jwdd_def_name' );
			var carrierEl = document.getElementById( 'jwdd_def_carrier' );
			var activeEl  = document.getElementById( 'jwdd_def_is_active' );
			var idEl      = document.getElementById( 'jwdd_def_id' );

			var name = nameEl ? nameEl.value.trim() : '';
			if ( ! name ) {
				showFeedback( feedback, 'Schedule name is required.', 'error' );
				return;
			}

			var days = [];
			document.querySelectorAll( '.jwdd-def-dow:checked' ).forEach( function ( cb ) {
				var row    = cb.closest( '.jwdd-dow-row' );
				var dayNum = parseInt( cb.value, 10 );
				var cutoff = row ? ( ( row.querySelector( '.jwdd-dow-cutoff' ) || {} ).value || '12:00' ) : '12:00';
				days.push( { day: dayNum, cutoff: cutoff, slots: daySlots[ dayNum ] || [] } );
			} );

			saveBtn.textContent = i18n.saving || 'Saving...';
			saveBtn.disabled    = true;

			var body = new URLSearchParams();
			body.append( 'action', 'jwdd_save_schedule_def' );
			body.append( 'nonce', nonce );
			body.append( 'id', idEl ? idEl.value : '0' );
			body.append( 'name', name );
			body.append( 'carrier_id', carrierEl ? carrierEl.value : '0' );
			body.append( 'is_active', activeEl && activeEl.checked ? '1' : '0' );
			body.append( 'days_of_week_json', JSON.stringify( days ) );

			fetch( ajaxurl, {
				method:  'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body:    body,
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					saveBtn.disabled = false;

					if ( res.success ) {
						if ( context === 'schedules_add' && res.data.def_id ) {
							window.location.href = ( cfg.def_edit_base_url || '' ) + '&def_id=' + res.data.def_id;
						} else {
							saveBtn.textContent = 'Update Schedule';
							showFeedback( feedback, res.data.message, 'success' );
						}
					} else {
						saveBtn.textContent = context === 'schedules_add' ? 'Add Schedule' : 'Update Schedule';
						showFeedback( feedback, res.data.message || i18n.error, 'error' );
					}
				} )
				.catch( function () {
					saveBtn.disabled    = false;
					saveBtn.textContent = context === 'schedules_add' ? 'Add Schedule' : 'Update Schedule';
					showFeedback( feedback, i18n.error, 'error' );
				} );
		} );
	}

	// -- Time Slots Modal --

	var modalActiveDayNum  = null;

	function initSlotModal() {
		var modal = document.getElementById( 'jwdd-slot-modal' );
		if ( ! modal ) return;

		// Open modal when a day's "Time Slots" button is clicked.
		document.querySelectorAll( '.jwdd-dow-slots-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				openSlotModal( parseInt( btn.dataset.day, 10 ), btn.dataset.name || '' );
			} );
		} );

		// Close on backdrop or × button.
		modal.querySelector( '.jwdd-modal-backdrop' ).addEventListener( 'click', closeSlotModal );
		modal.querySelector( '.jwdd-modal-close' ).addEventListener( 'click', closeSlotModal );

		// Add slot button inside modal.
		var addBtn = document.getElementById( 'jwdd-modal-add-slot' );
		if ( addBtn ) {
			addBtn.addEventListener( 'click', function () {
				if ( null === modalActiveDayNum ) return;
				var startEl = document.getElementById( 'jwdd_slot_start' );
				var endEl   = document.getElementById( 'jwdd_slot_end' );
				var labelEl = document.getElementById( 'jwdd_slot_label' );
				var start   = startEl ? startEl.value : '09:00';
				var end     = endEl   ? endEl.value   : '12:00';
				var label   = labelEl ? labelEl.value.trim() : '';
				if ( ! start || ! end ) return;
				if ( ! daySlots[ modalActiveDayNum ] ) daySlots[ modalActiveDayNum ] = [];
				daySlots[ modalActiveDayNum ].push( { start: start, end: end, label: label } );
				updateSlotCount( modalActiveDayNum );
				if ( startEl ) startEl.value = '09:00';
				if ( endEl )   endEl.value   = '12:00';
				if ( labelEl ) labelEl.value = '';
				renderModalSlotList();
			} );
		}
	}

	function openSlotModal( dayNum, dayName ) {
		var modal = document.getElementById( 'jwdd-slot-modal' );
		if ( ! modal ) return;
		modalActiveDayNum = dayNum;
		document.getElementById( 'jwdd-modal-title' ).textContent = dayName + ' \u2014 Time Slots';
		renderModalSlotList();
		var startEl = document.getElementById( 'jwdd_slot_start' );
		var endEl   = document.getElementById( 'jwdd_slot_end' );
		var labelEl = document.getElementById( 'jwdd_slot_label' );
		if ( startEl ) startEl.value = '09:00';
		if ( endEl )   endEl.value   = '12:00';
		if ( labelEl ) labelEl.value = '';
		modal.style.display = '';
		document.body.classList.add( 'jwdd-modal-open' );
	}

	function closeSlotModal() {
		var modal = document.getElementById( 'jwdd-slot-modal' );
		if ( modal ) modal.style.display = 'none';
		document.body.classList.remove( 'jwdd-modal-open' );
		modalActiveDayNum = null;
	}

	function renderModalSlotList() {
		var list  = document.getElementById( 'jwdd-modal-slots-list' );
		if ( ! list ) return;
		var slots = ( modalActiveDayNum !== null && daySlots[ modalActiveDayNum ] ) ? daySlots[ modalActiveDayNum ] : [];
		if ( slots.length === 0 ) {
			list.innerHTML = '<p class="jwdd-modal-no-slots">No time slots added yet.</p>';
			return;
		}
		var html = '<table class="widefat jwdd-modal-slots-table"><thead><tr>'
			+ '<th>Start</th><th>End</th><th>Label</th><th></th>'
			+ '</tr></thead><tbody>';
		slots.forEach( function ( slot, idx ) {
			html += '<tr>'
				+ '<td><input type="time" class="jwdd-modal-slot-start" data-idx="' + idx + '" value="' + escAttr( slot.start ) + '"></td>'
				+ '<td><input type="time" class="jwdd-modal-slot-end" data-idx="' + idx + '" value="' + escAttr( slot.end ) + '"></td>'
				+ '<td><input type="text" class="jwdd-modal-slot-label" data-idx="' + idx + '" value="' + escAttr( slot.label || '' ) + '" placeholder="Label"></td>'
				+ '<td><button type="button" class="button button-small jwdd-modal-delete-slot" data-idx="' + idx + '">Remove</button></td>'
				+ '</tr>';
		} );
		html += '</tbody></table>';
		list.innerHTML = html;
		bindModalSlotActions();
	}

	function bindModalSlotActions() {
		var list = document.getElementById( 'jwdd-modal-slots-list' );

		list.querySelectorAll( '.jwdd-modal-slot-start, .jwdd-modal-slot-end, .jwdd-modal-slot-label' ).forEach( function ( input ) {
			input.addEventListener( 'change', function () {
				var idx   = parseInt( input.dataset.idx, 10 );
				var slots = daySlots[ modalActiveDayNum ] || [];
				if ( ! slots[ idx ] ) return;
				if ( input.classList.contains( 'jwdd-modal-slot-start' ) ) slots[ idx ].start = input.value;
				if ( input.classList.contains( 'jwdd-modal-slot-end' ) )   slots[ idx ].end   = input.value;
				if ( input.classList.contains( 'jwdd-modal-slot-label' ) ) slots[ idx ].label = input.value;
				daySlots[ modalActiveDayNum ] = slots;
			} );
		} );

		list.querySelectorAll( '.jwdd-modal-delete-slot' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var idx = parseInt( btn.dataset.idx, 10 );
				if ( modalActiveDayNum !== null && daySlots[ modalActiveDayNum ] ) {
					daySlots[ modalActiveDayNum ].splice( idx, 1 );
					updateSlotCount( modalActiveDayNum );
					renderModalSlotList();
				}
			} );
		} );
	}

	function updateSlotCount( dayNum ) {
		var btn = document.querySelector( '.jwdd-dow-slots-btn[data-day="' + dayNum + '"]' );
		if ( ! btn ) return;
		var countEl = btn.querySelector( '.jwdd-dow-slot-count' );
		if ( countEl ) countEl.textContent = '(' + ( ( daySlots[ dayNum ] || [] ).length ) + ')';
	}

	// -------------------------------------------------------------------------
	// HOLIDAYS TAB
	// -------------------------------------------------------------------------

	if ( context === 'holidays_list' ) {
		bindHolidayListActions();
	}

	function bindHolidayListActions() {
		document.querySelectorAll( '.jwdd-delete-holiday' ).forEach( function ( btn ) {
			btn.onclick = function () {
				if ( ! confirm( i18n.confirm_delete_holiday || 'Delete this holiday? This cannot be undone.' ) ) {
					return;
				}

				btn.textContent = i18n.deleting || 'Deleting...';
				btn.disabled    = true;

				post( 'jwdd_delete_holiday', { id: btn.dataset.id }, function ( res ) {
					if ( res.success ) {
						var row = document.getElementById( 'jwdd-holiday-row-' + btn.dataset.id );
						if ( row ) row.remove();
						var feedback = document.getElementById( 'jwdd-holidays-feedback' );
						showFeedback( feedback, res.data.message, 'success' );
						var tbody = document.getElementById( 'jwdd-holidays-tbody' );
						if ( tbody && tbody.querySelectorAll( 'tr' ).length === 0 ) {
							tbody.innerHTML = '<tr id="jwdd-holidays-empty"><td colspan="6">' + ( i18n.no_holidays || 'No holidays yet.' ) + '</td></tr>';
						}
					} else {
						btn.textContent = 'Delete';
						btn.disabled    = false;
						showFeedback( document.getElementById( 'jwdd-holidays-feedback' ), res.data.message || i18n.error, 'error' );
					}
				} );
			};
		} );
	}

	if ( context === 'holidays_add' || context === 'holidays_edit' ) {
		initHolidayForm();
	}

	function initHolidayForm() {
		var saveBtn  = document.getElementById( 'jwdd-save-holiday' );
		var feedback = document.getElementById( 'jwdd-holiday-feedback' );

		if ( ! saveBtn ) return;

		saveBtn.addEventListener( 'click', function () {
			var nameEl     = document.getElementById( 'jwdd_holiday_name' );
			var dateFromEl = document.getElementById( 'jwdd_holiday_date_from' );
			var dateToEl   = document.getElementById( 'jwdd_holiday_date_to' );
			var activeEl   = document.getElementById( 'jwdd_holiday_is_active' );
			var idEl       = document.getElementById( 'jwdd_holiday_id' );

			var name     = nameEl     ? nameEl.value.trim()     : '';
			var dateFrom = dateFromEl ? dateFromEl.value.trim() : '';
			var dateTo   = dateToEl   ? dateToEl.value.trim()   : '';

			if ( ! name ) {
				showFeedback( feedback, 'Holiday name is required.', 'error' );
				return;
			}
			if ( ! dateFrom || ! dateTo ) {
				showFeedback( feedback, 'From and To dates are required.', 'error' );
				return;
			}
			if ( dateTo < dateFrom ) {
				showFeedback( feedback, 'To date must be on or after From date.', 'error' );
				return;
			}

			// Collect selected carrier IDs (empty = all carriers).
			var carrierIds  = [];
			var carriersEl  = document.getElementById( 'jwdd-holiday-carriers' );
			if ( carriersEl ) {
				Array.from( carriersEl.selectedOptions ).forEach( function ( opt ) {
					carrierIds.push( parseInt( opt.value, 10 ) );
				} );
			}

			saveBtn.textContent = i18n.saving || 'Saving...';
			saveBtn.disabled    = true;

			post( 'jwdd_save_holiday', {
				id:               idEl ? idEl.value : '0',
				name:             name,
				carrier_ids_json: JSON.stringify( carrierIds ),
				date_from:        dateFrom,
				date_to:          dateTo,
				is_active:        activeEl && activeEl.checked ? '1' : '0',
			}, function ( res ) {
				saveBtn.disabled = false;

				if ( res.success ) {
					if ( context === 'holidays_add' && res.data.id ) {
						window.location.href = ( cfg.holiday_edit_base_url || '' ) + '&holiday_id=' + res.data.id;
					} else {
						saveBtn.textContent = 'Update Holiday';
						showFeedback( feedback, res.data.message, 'success' );
					}
				} else {
					saveBtn.textContent = context === 'holidays_add' ? 'Add Holiday' : 'Update Holiday';
					showFeedback( feedback, res.data.message || i18n.error, 'error' );
				}
			} );
		} );
	}

	// -------------------------------------------------------------------------
	// CALENDAR TAB
	// -------------------------------------------------------------------------

	if ( context === 'calendar' ) {
		initCalendar();
	}

	function initCalendar() {
		var gridEl      = document.getElementById( 'jwdd-cal-grid' );
		var periodEl    = document.getElementById( 'jwdd-cal-period' );
		var prevBtn     = document.getElementById( 'jwdd-cal-prev' );
		var nextBtn     = document.getElementById( 'jwdd-cal-next' );
		var toggleBtns  = document.querySelectorAll( '.jwdd-cal-view-toggle' );
		var weekStart   = parseInt( cfg.week_start || '0', 10 );

		var state = {
			view: 'month',
			date: cfg.today || calTodayStr(),
			data: null,
		};

		// Day name arrays (Sun=0 index).
		var DAY_ABBR = [ 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ];

		// Initial fetch.
		fetchCalendar();

		// View toggle buttons.
		toggleBtns.forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				if ( btn.dataset.view === state.view ) return;
				state.view = btn.dataset.view;
				// For day view, navigate to today if no specific day is set.
				if ( 'day' === state.view && state.data ) {
					state.date = state.data.today || state.date;
				}
				fetchCalendar();
			} );
		} );

		// Prev / next navigation.
		prevBtn && prevBtn.addEventListener( 'click', function () {
			if ( state.data ) state.date = state.data.prev_date;
			fetchCalendar();
		} );

		nextBtn && nextBtn.addEventListener( 'click', function () {
			if ( state.data ) state.date = state.data.next_date;
			fetchCalendar();
		} );

		// Calendar overflow modals (holidays & orders).
		var calModals = initCalendarModals();
		if ( gridEl ) {
			gridEl.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest( '.jwdd-cal-more-btn' );
				if ( ! btn ) return;
				e.preventDefault();
				var dateStr = btn.dataset.date;
				var type    = btn.dataset.type;
				if ( ! state.data || ! dateStr ) return;
				if ( type === 'holidays' ) {
					calModals.openHolidays( dateStr, ( state.data.holidays || {} )[ dateStr ] || [] );
				} else {
					calModals.openOrders( dateStr, ( state.data.orders || {} )[ dateStr ] || [] );
				}
			} );
		}

		function fetchCalendar() {
			if ( gridEl ) {
				gridEl.innerHTML =
					'<div class="jwdd-cal-loading">'
					+ '<span class="spinner is-active" style="float:none; margin:0 4px 0 0;"></span>'
					+ 'Loading\u2026'
					+ '</div>';
			}
			post( 'jwdd_get_calendar_orders', {
				view:       state.view,
				date:       state.date,
				week_start: weekStart,
			}, function ( res ) {
				if ( res.success ) {
					state.data = res.data;
					state.view = res.data.view;
					state.date = res.data.range_start;
					renderCalendar( res.data );
				} else {
					if ( gridEl ) {
						gridEl.innerHTML = '<p class="jwdd-cal-error">Failed to load calendar data.</p>';
					}
				}
			} );
		}

		function renderCalendar( data ) {
			if ( periodEl ) periodEl.textContent = data.period_label;

			toggleBtns.forEach( function ( btn ) {
				btn.classList.toggle( 'active', btn.dataset.view === data.view );
			} );

			if ( ! gridEl ) return;

			if ( 'month' === data.view ) {
				gridEl.innerHTML = renderMonthGrid( data );
			} else if ( 'week' === data.view ) {
				gridEl.innerHTML = renderWeekGrid( data );
			} else {
				gridEl.innerHTML = renderDayGrid( data );
			}
		}

		// Returns ordered array of day-of-week indices starting at weekStart.
		function getWeekDayOrder() {
			var days = [];
			for ( var i = 0; i < 7; i++ ) {
				days.push( ( weekStart + i ) % 7 );
			}
			return days;
		}

		function renderHeaderRow() {
			var days = getWeekDayOrder();
			var html = '<div class="jwdd-cal-header-row">';
			days.forEach( function ( d ) {
				html += '<div class="jwdd-cal-header-cell">' + escHtml( DAY_ABBR[ d ] ) + '</div>';
			} );
			html += '</div>';
			return html;
		}

		function renderMonthGrid( data ) {
			var today      = data.today;
			var orders     = data.orders    || {};
			var holidays   = data.holidays  || {};
			var parts      = data.range_start.split( '-' );
			var year       = parseInt( parts[0], 10 );
			var month      = parseInt( parts[1], 10 ) - 1; // 0-indexed
			var daysInMonth = new Date( year, month + 1, 0 ).getDate();
			var firstDOW   = new Date( year, month, 1 ).getDay(); // 0=Sun
			var leading    = ( firstDOW - weekStart + 7 ) % 7;
			var prevLast   = new Date( year, month, 0 ).getDate();

			var html = '<div class="jwdd-cal-month">' + renderHeaderRow();
			html += '<div class="jwdd-cal-body">';

			// Leading cells from previous month.
			for ( var p = leading - 1; p >= 0; p-- ) {
				html += '<div class="jwdd-cal-cell jwdd-cal-cell-other">'
					+ '<span class="jwdd-cal-day-num">' + ( prevLast - p ) + '</span>'
					+ '</div>';
			}

			// Current month days.
			for ( var day = 1; day <= daysInMonth; day++ ) {
				var dateStr      = year + '-' + calPad( month + 1 ) + '-' + calPad( day );
				var isToday      = ( dateStr === today );
				var isPast       = ( dateStr < today );
				var dayOrders    = orders[ dateStr ]   || [];
				var dayHolidays  = holidays[ dateStr ] || [];

				var cls = 'jwdd-cal-cell';
				if ( isToday ) cls += ' jwdd-cal-cell-today';
				else if ( isPast ) cls += ' jwdd-cal-cell-past';
				if ( dayHolidays.length ) cls += ' jwdd-cal-cell-has-holiday';

				html += '<div class="' + cls + '">'
					+ '<span class="jwdd-cal-day-num">' + day + '</span>'
					+ renderHolidayPills( dayHolidays, dateStr )
					+ renderOrderPills( dayOrders, dateStr )
					+ '</div>';
			}

			// Trailing cells to fill last row.
			var total    = leading + daysInMonth;
			var trailing = ( 7 - ( total % 7 ) ) % 7;
			for ( var t = 1; t <= trailing; t++ ) {
				html += '<div class="jwdd-cal-cell jwdd-cal-cell-other">'
					+ '<span class="jwdd-cal-day-num">' + t + '</span>'
					+ '</div>';
			}

			html += '</div></div>'; // .jwdd-cal-body + .jwdd-cal-month
			return html;
		}

		function renderWeekGrid( data ) {
			var today      = data.today;
			var orders     = data.orders   || {};
			var holidays   = data.holidays || {};
			var sp         = data.range_start.split( '-' );
			var startDate  = new Date( parseInt( sp[0], 10 ), parseInt( sp[1], 10 ) - 1, parseInt( sp[2], 10 ) );
			var weekDayOrder = getWeekDayOrder();

			var html = '<div class="jwdd-cal-week">' + renderHeaderRow();
			html += '<div class="jwdd-cal-body jwdd-cal-week-body">';

			weekDayOrder.forEach( function ( dow, idx ) {
				var d            = new Date( startDate );
				d.setDate( d.getDate() + idx );
				var dateStr      = calFormatYMD( d );
				var isToday      = ( dateStr === today );
				var isPast       = ( dateStr < today );
				var dayOrders    = orders[ dateStr ]   || [];
				var dayHolidays  = holidays[ dateStr ] || [];

				var cls = 'jwdd-cal-cell jwdd-cal-cell-week';
				if ( isToday ) cls += ' jwdd-cal-cell-today';
				else if ( isPast ) cls += ' jwdd-cal-cell-past';
				if ( dayHolidays.length ) cls += ' jwdd-cal-cell-has-holiday';

				html += '<div class="' + cls + '">'
					+ '<span class="jwdd-cal-day-num">' + DAY_ABBR[ d.getDay() ] + ' ' + d.getDate() + '</span>'
					+ renderHolidayPills( dayHolidays, dateStr )
					+ renderOrderPills( dayOrders, dateStr )
					+ '</div>';
			} );

			html += '</div></div>'; // .jwdd-cal-week-body + .jwdd-cal-week
			return html;
		}

		function renderDayGrid( data ) {
			var dateStr     = data.range_start;
			var orders      = data.orders   || {};
			var holidays    = data.holidays || {};
			var dayOrders   = orders[ dateStr ]   || [];
			var dayHolidays = holidays[ dateStr ] || [];

			var html = '<div class="jwdd-cal-day">';

			// Holiday block — rendered first, above the order slots.
			if ( dayHolidays.length ) {
				html += '<div class="jwdd-cal-day-holidays">';
				html += '<h4 class="jwdd-cal-day-holidays-heading">Holidays</h4>';
				html += '<ul class="jwdd-cal-day-holiday-list">';
				dayHolidays.forEach( function ( h ) {
					html += '<li class="jwdd-cal-day-holiday-item">'
						+ '<a href="' + escAttr( h.edit_url ) + '" class="jwdd-cal-holiday-link">'
						+ escHtml( h.name )
						+ '</a>'
						+ ' <span class="jwdd-cal-holiday-carriers">' + escHtml( h.carriers ) + '</span>'
						+ '</li>';
				} );
				html += '</ul></div>'; // .jwdd-cal-day-holiday-list + .jwdd-cal-day-holidays
			}

			// Delivery order slots.
			if ( ! dayOrders.length ) {
				html += '<p class="jwdd-cal-no-orders">'
					+ ( dayHolidays.length ? 'No deliveries scheduled (holiday).' : 'No deliveries scheduled for this day.' )
					+ '</p>';
			} else {
				// Group by slot_label.
				var bySlot    = {};
				var slotOrder = [];
				dayOrders.forEach( function ( o ) {
					var key = o.slot_label || '';
					if ( ! bySlot[ key ] ) {
						bySlot[ key ] = [];
						slotOrder.push( key );
					}
					bySlot[ key ].push( o );
				} );

				slotOrder.forEach( function ( slotLabel ) {
					html += '<div class="jwdd-cal-day-slot">';
					html += '<h4 class="jwdd-cal-slot-label">'
						+ escHtml( slotLabel || 'Unassigned time slot' )
						+ ' <span class="jwdd-cal-slot-count">'
						+ bySlot[ slotLabel ].length + ' order' + ( bySlot[ slotLabel ].length !== 1 ? 's' : '' )
						+ '</span></h4>';
					html += '<ul class="jwdd-cal-day-orders">';
					bySlot[ slotLabel ].forEach( function ( o ) {
						html += '<li>'
							+ '<a href="' + escAttr( o.edit_url ) + '" class="jwdd-cal-order-link">'
							+ 'Delivery day for Order #' + escHtml( String( o.number ) )
							+ '</a>'
							+ ' <span class="jwdd-cal-status jwdd-cal-status-' + escAttr( o.status ) + '">'
							+ escHtml( o.status_label )
							+ '</span>'
							+ '</li>';
					} );
					html += '</ul></div>'; // .jwdd-cal-day-orders + .jwdd-cal-day-slot
				} );
			}

			html += '</div>'; // .jwdd-cal-day
			return html;
		}

		var CAL_MAX_PILLS = 5;

		function renderOrderPills( orders, dateStr ) {
			if ( ! orders.length ) return '';
			var visible  = orders.slice( 0, CAL_MAX_PILLS );
			var overflow = orders.length - CAL_MAX_PILLS;
			var html = '<div class="jwdd-cal-orders">';
			visible.forEach( function ( o ) {
				var title = 'Order #' + o.number
					+ ( o.slot_label ? ' \u2014 ' + o.slot_label : '' )
					+ ' (' + o.status_label + ')';
				html += '<a href="' + escAttr( o.edit_url ) + '"'
					+ ' class="jwdd-cal-order-pill jwdd-cal-order-' + escAttr( o.status ) + '"'
					+ ' title="' + escAttr( title ) + '">'
					+ 'Delivery day for Order #' + escHtml( String( o.number ) )
					+ '</a>';
			} );
			if ( overflow > 0 ) {
				html += '<button type="button"'
					+ ' class="jwdd-cal-more-btn jwdd-cal-more-orders"'
					+ ' data-date="' + escAttr( dateStr ) + '"'
					+ ' data-type="orders">+'
					+ overflow + ' more order' + ( overflow !== 1 ? 's' : '' )
					+ '</button>';
			}
			html += '</div>';
			return html;
		}

		function renderHolidayPills( holidays, dateStr ) {
			if ( ! holidays.length ) return '';
			var visible  = holidays.slice( 0, CAL_MAX_PILLS );
			var overflow = holidays.length - CAL_MAX_PILLS;
			var html = '<div class="jwdd-cal-holiday-pills">';
			visible.forEach( function ( h ) {
				html += '<a href="' + escAttr( h.edit_url ) + '"'
					+ ' class="jwdd-cal-holiday-pill"'
					+ ' title="' + escAttr( h.name + ' \u2014 ' + h.carriers ) + '">'
					+ escHtml( h.name )
					+ '</a>';
			} );
			if ( overflow > 0 ) {
				html += '<button type="button"'
					+ ' class="jwdd-cal-more-btn jwdd-cal-more-holidays"'
					+ ' data-date="' + escAttr( dateStr ) + '"'
					+ ' data-type="holidays">+'
					+ overflow + ' more holiday' + ( overflow !== 1 ? 's' : '' )
					+ '</button>';
			}
			html += '</div>';
			return html;
		}

		function calTodayStr() {
			return calFormatYMD( new Date() );
		}

		function calFormatYMD( d ) {
			return d.getFullYear() + '-' + calPad( d.getMonth() + 1 ) + '-' + calPad( d.getDate() );
		}

		function calPad( n ) {
			return String( n ).padStart( 2, '0' );
		}

		function initCalendarModals() {
			function createModal( id, titleId, bodyId ) {
				var el = document.createElement( 'div' );
				el.id = id;
				el.className = 'jwdd-modal';
				el.style.display = 'none';
				el.innerHTML =
					'<div class="jwdd-modal-backdrop"></div>'
					+ '<div class="jwdd-modal-box">'
					+ '<div class="jwdd-modal-header">'
					+ '<h3 id="' + titleId + '"></h3>'
					+ '<button type="button" class="jwdd-modal-close">×</button>'
					+ '</div>'
					+ '<div class="jwdd-modal-body" id="' + bodyId + '"></div>'
					+ '</div>';
				document.body.appendChild( el );
				function close() {
					el.style.display = 'none';
					document.body.classList.remove( 'jwdd-modal-open' );
				}
				el.querySelector( '.jwdd-modal-backdrop' ).addEventListener( 'click', close );
				el.querySelector( '.jwdd-modal-close' ).addEventListener( 'click', close );
				return el;
			}

			var holidayModal = createModal( 'jwdd-cal-holidays-modal', 'jwdd-cal-holidays-modal-title', 'jwdd-cal-holidays-modal-body' );
			var ordersModal  = createModal( 'jwdd-cal-orders-modal',  'jwdd-cal-orders-modal-title',  'jwdd-cal-orders-modal-body' );

			function openModal( modal ) {
				modal.style.display = '';
				document.body.classList.add( 'jwdd-modal-open' );
			}

			return {
				openHolidays: function ( dateStr, holidays ) {
					document.getElementById( 'jwdd-cal-holidays-modal-title' ).textContent = 'Holidays — ' + formatCalDate( dateStr );
					var body = document.getElementById( 'jwdd-cal-holidays-modal-body' );
					var html = '<ul class="jwdd-cal-modal-list">';
					holidays.forEach( function ( h ) {
						html += '<li class="jwdd-cal-modal-list-item">'
							+ '<a href="' + escAttr( h.edit_url ) + '" class="jwdd-cal-holiday-link">'
							+ escHtml( h.name ) + '</a>'
							+ ' <span class="jwdd-cal-holiday-carriers">' + escHtml( h.carriers ) + '</span>'
							+ '</li>';
					} );
					html += '</ul>';
					body.innerHTML = html;
					openModal( holidayModal );
				},
				openOrders: function ( dateStr, orders ) {
					document.getElementById( 'jwdd-cal-orders-modal-title' ).textContent = 'Orders — ' + formatCalDate( dateStr );
					var body = document.getElementById( 'jwdd-cal-orders-modal-body' );
					var html = '<ul class="jwdd-cal-modal-list">';
					orders.forEach( function ( o ) {
						html += '<li class="jwdd-cal-modal-list-item">'
							+ '<a href="' + escAttr( o.edit_url ) + '" class="jwdd-cal-order-link">'
							+ 'Order #' + escHtml( String( o.number ) ) + '</a>'
							+ ( o.slot_label ? ' <span class="jwdd-cal-modal-slot">' + escHtml( o.slot_label ) + '</span>' : '' )
							+ ' <span class="jwdd-cal-status jwdd-cal-status-' + escAttr( o.status ) + '">' + escHtml( o.status_label ) + '</span>'
							+ '</li>';
					} );
					html += '</ul>';
					body.innerHTML = html;
					openModal( ordersModal );
				},
			};
		}

		function formatCalDate( dateStr ) {
			if ( ! dateStr ) return dateStr;
			var parts = dateStr.split( '-' );
			if ( parts.length !== 3 ) return dateStr;
			var d = new Date( parseInt( parts[0], 10 ), parseInt( parts[1], 10 ) - 1, parseInt( parts[2], 10 ) );
			return d.toLocaleDateString( undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' } );
		}
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

} )();
