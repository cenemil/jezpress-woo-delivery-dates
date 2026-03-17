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
