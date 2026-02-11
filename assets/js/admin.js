/* ═══════════════════════════════════════════════════════════════════
 * SemanticLinker AI – Admin JS
 *
 * Responsibilities:
 *   • "Odrzuć / Usuń" button on the dashboard (AJAX reject + blacklist)
 *   • "Pokaż odrzucone" checkbox toggle
 *   • "Reindeksuj teraz" button on the settings page (AJAX trigger)
 *   • Lightweight notice helper (WP-style green/red bar)
 *
 * Dependencies: jQuery (enqueued by WordPress core in admin)
 * Localized:    window.slAjax  { url, nonce }
 * ═══════════════════════════════════════════════════════════════════ */

( function( $ ) {
	'use strict';

	$( document ).ready( function() {

		/* ── 1. Reject link (dashboard) ───────────────────────────── */
		$( document ).on( 'click', '.sl-btn-reject', function( e ) {
			e.preventDefault();

			var $btn   = $( this );
			var linkId = $btn.data( 'link-id' );

			/* Confirmation dialog */
			if ( ! window.confirm(
				'Odrzucisz ten link i dodasz go permanentnie do blacklisty.\n' +
				'Nie pojawi się ponownie w żadnym artykule.\n\n' +
				'Kontynuować?'
			) ) {
				return;
			}

			$btn.prop( 'disabled', true ).text( '\u2026' );   // …

			$.ajax( {
				url  : slAjax.url,
				type : 'POST',
				data : {
					action  : 'sl_reject_link',
					nonce   : slAjax.nonce,
					link_id : linkId
				},
				success : function( res ) {
					if ( res.success ) {
						var $row = $btn.closest( 'tr' );

						/* Mark row as rejected (hidden unless toggle is on) */
						$row.addClass( 'sl-row-rejected' );
						$row.attr( 'data-status', 'rejected' );

						/* Update the status badge in the row */
						$row.find( '.sl-badge' )
							.removeClass( 'sl-badge-ok' )
							.addClass( 'sl-badge-warn' )
							.text( 'Odrzucony' );

						/* Replace reject button with restore button */
						$btn.closest( 'td' ).html(
							'<button class="button button-link sl-btn-restore" data-link-id="' + linkId + '" style="color: #0073aa;">Przywróć</button>'
						);

						showNotice( 'success', res.data.message );
					} else {
						showNotice( 'error', res.data );
						$btn.prop( 'disabled', false ).text( 'Odrzuć' );
					}
				},
				error : function() {
					showNotice( 'error', 'Błąd serwera – spróbuj ponownie.' );
					$btn.prop( 'disabled', false ).text( 'Odrzuć' );
				}
			} );
		} );

		/* ── 1b. Restore link (dashboard) ─────────────────────────── */
		$( document ).on( 'click', '.sl-btn-restore', function( e ) {
			e.preventDefault();

			var $btn   = $( this );
			var linkId = $btn.data( 'link-id' );

			$btn.prop( 'disabled', true ).text( '\u2026' );

			$.ajax( {
				url  : slAjax.url,
				type : 'POST',
				data : {
					action  : 'sl_restore_link',
					nonce   : slAjax.nonce,
					link_id : linkId
				},
				success : function( res ) {
					if ( res.success ) {
						var $row = $btn.closest( 'tr' );

						/* Update the status badge */
						$row.find( '.sl-badge' )
							.removeClass( 'sl-badge-warn sl-badge-filtered' )
							.addClass( 'sl-badge-ok' )
							.text( 'Aktywny' );

						/* Remove rejected/filtered class */
						$row.removeClass( 'sl-row-rejected sl-row-filtered' );
						$row.attr( 'data-status', 'active' );

						/* Replace restore button with reject button */
						$btn.closest( 'td' ).html(
							'<button class="button button-link sl-btn-reject" data-link-id="' + linkId + '">Odrzuć</button>'
						);

						showNotice( 'success', res.data.message );
					} else {
						showNotice( 'error', res.data );
						$btn.prop( 'disabled', false ).text( 'Przywróć' );
					}
				},
				error : function() {
					showNotice( 'error', 'Błąd serwera – spróbuj ponownie.' );
					$btn.prop( 'disabled', false ).text( 'Przywróć' );
				}
			} );
		} );

		/* ── 2. Show / hide rejected rows (with localStorage persistence) ── */
		( function() {
			var $checkbox = $( '#sl-show-rejected' );
			var $table    = $( '#sl-links-table' );
			var storageKey = 'sl_show_rejected';

			// Restore state from localStorage on page load
			if ( $checkbox.length ) {
				var savedState = localStorage.getItem( storageKey );
				// If no saved state, default to checked (true)
				var isChecked = savedState === null ? true : savedState === 'true';

				$checkbox.prop( 'checked', isChecked );
				$table.toggleClass( 'sl-show-rejected', isChecked );
			}

			// Save state on change
			$checkbox.on( 'change', function() {
				var isChecked = $( this ).is( ':checked' );
				localStorage.setItem( storageKey, isChecked );
				$table.toggleClass( 'sl-show-rejected', isChecked );
			} );
		} )();

		/* ── 2a. Show / hide filtered rows (AI) (with localStorage persistence) ── */
		( function() {
			var $checkbox = $( '#sl-show-filtered' );
			var $table    = $( '#sl-links-table' );
			var storageKey = 'sl_show_filtered';

			// Restore state from localStorage on page load
			if ( $checkbox.length ) {
				var savedState = localStorage.getItem( storageKey );
				// If no saved state, default to checked (true)
				var isChecked = savedState === null ? true : savedState === 'true';

				$checkbox.prop( 'checked', isChecked );
				$table.toggleClass( 'sl-show-filtered', isChecked );
			}

			// Save state on change
			$checkbox.on( 'change', function() {
				var isChecked = $( this ).is( ':checked' );
				localStorage.setItem( storageKey, isChecked );
				$table.toggleClass( 'sl-show-filtered', isChecked );
			} );
		} )();

		/* ── 2b. Delete all links ────────────────────────────────── */
		$( '#sl-btn-delete-all' ).on( 'click', function() {
			if ( ! window.confirm(
				'UWAGA: Usuniesz WSZYSTKIE linki i całą blacklistę!\n\n' +
				'Ta operacja jest nieodwracalna.\n\n' +
				'Kontynuować?'
			) ) {
				return;
			}

			var $btn = $( this );
			$btn.prop( 'disabled', true ).text( 'Usuwanie…' );

			$.ajax( {
				url  : slAjax.url,
				type : 'POST',
				data : {
					action : 'sl_delete_all_links',
					nonce  : slAjax.nonce
				},
				success : function( res ) {
					if ( res.success ) {
						showNotice( 'success', res.data.message );
						location.reload();
					} else {
						showNotice( 'error', res.data );
						$btn.prop( 'disabled', false ).text( 'Usuń wszystkie linki' );
					}
				},
				error : function() {
					showNotice( 'error', 'Błąd serwera – spróbuj ponownie.' );
					$btn.prop( 'disabled', false ).text( 'Usuń wszystkie linki' );
				}
			} );
		} );

		/* ── 3. Manual re-index with progress (settings page) ───── */
		var isIndexingCancelled = false;

		$( '#sl-btn-reindex' ).on( 'click', function() {
			var $btn       = $( this );
			var $cancelBtn = $( '#sl-btn-cancel' );
			var $status    = $( '#sl-reindex-status' );
			var $progress  = $( '#sl-progress-wrap' );

			isIndexingCancelled = false;

			// Separate progress bars for each phase
			var $indexingSection  = $( '#sl-progress-indexing' );
			var $indexingBar      = $( '#sl-progress-indexing-bar' );
			var $indexingText     = $( '#sl-progress-indexing-text' );
			var $indexingPercent  = $( '#sl-progress-indexing-percent' );

			var $matchingSection  = $( '#sl-progress-matching' );
			var $matchingBar      = $( '#sl-progress-matching-bar' );
			var $matchingText     = $( '#sl-progress-matching-text' );
			var $matchingPercent  = $( '#sl-progress-matching-percent' );

			var $filteringSection = $( '#sl-progress-filtering' );
			var $filteringBar     = $( '#sl-progress-filtering-bar' );
			var $filteringText    = $( '#sl-progress-filtering-text' );
			var $filteringPercent = $( '#sl-progress-filtering-percent' );

			// Show progress wrapper and reset all bars
			if ( $progress.length ) {
				$progress.show();

				// Reset indexing
				$indexingBar.css( 'width', '0%' );
				$indexingText.text( 'Inicjalizacja...' );
				$indexingPercent.text( '0%' );
				$indexingSection.css( 'opacity', '1' );

				// Reset matching
				$matchingBar.css( 'width', '0%' );
				$matchingText.text( 'Oczekiwanie...' );
				$matchingPercent.text( '0%' );
				$matchingSection.css( 'opacity', '0.5' );

				// Reset filtering (hidden by default)
				$filteringSection.hide();
				$filteringBar.css( 'width', '0%' );
				$filteringText.text( 'Oczekiwanie...' );
				$filteringPercent.text( '0%' );
			}

			$btn.prop( 'disabled', true ).text( '\u21BB Przetwarzanie\u2026' );
			$cancelBtn.show();
			$status.text( '' );

			// Step 1: Start indexing (initialize)
			$.ajax( {
				url  : slAjax.url,
				type : 'POST',
				data : {
					action : 'sl_start_indexing',
					nonce  : slAjax.nonce
				},
				success : function( res ) {
					if ( res.success ) {
						$indexingText.text( res.data.message );
						// Start processing batches
						processBatch();
					} else {
						handleError( res.data );
					}
				},
				error : function() {
					handleError( 'Błąd połączenia z serwerem.' );
				}
			} );

			// Step 2: Process batches recursively
			function processBatch() {
				// Check if cancelled
				if ( isIndexingCancelled ) {
					return;
				}

				$.ajax( {
					url     : slAjax.url,
					type    : 'POST',
					timeout : 120000,  // 2 min per batch
					data    : {
						action : 'sl_process_batch',
						nonce  : slAjax.nonce
					},
					success : function( res ) {
						if ( res.success ) {
							var data = res.data;
							var percent = data.percent || 0;

							// Show API warning if present (e.g., Gemini API errors)
							if ( data.warning ) {
								showNotice( 'warning', data.warning );
							}

							// Update progress bars based on current phase
							if ( data.phase === 'indexing' ) {
								$indexingBar.css( 'width', percent + '%' );
								$indexingPercent.text( percent + '%' );
								$indexingText.text( data.message || 'Indeksowanie...' );
								$indexingSection.css( 'opacity', '1' );
							} else if ( data.phase === 'matching' ) {
								// Indexing complete - show 100%
								$indexingBar.css( 'width', '100%' );
								$indexingPercent.text( '100%' );
								$indexingText.text( '✓ Zakończono' );
								$indexingSection.css( 'opacity', '0.7' );

								// Update matching progress
								$matchingSection.css( 'opacity', '1' );
								$matchingBar.css( 'width', percent + '%' );
								$matchingPercent.text( percent + '%' );
								$matchingText.text( data.message || 'Dopasowywanie...' );
							} else if ( data.phase === 'filtering' ) {
								// Indexing & matching complete
								$indexingBar.css( 'width', '100%' );
								$indexingPercent.text( '100%' );
								$indexingText.text( '✓ Zakończono' );
								$indexingSection.css( 'opacity', '0.7' );

								$matchingBar.css( 'width', '100%' );
								$matchingPercent.text( '100%' );
								$matchingText.text( '✓ Zakończono' );
								$matchingSection.css( 'opacity', '0.7' );

								// Show and update filtering progress
								$filteringSection.show().css( 'opacity', '1' );
								$filteringBar.css( 'width', percent + '%' );
								$filteringPercent.text( percent + '%' );
								$filteringText.text( data.message || 'Filtrowanie AI...' );
							}

							// Check if complete
							if ( data.complete ) {
								// Mark all phases as complete
								$indexingBar.css( 'width', '100%' );
								$indexingPercent.text( '100%' );
								$indexingText.text( '✓ Zakończono' );
								$indexingSection.css( 'opacity', '0.7' );

								$matchingBar.css( 'width', '100%' );
								$matchingPercent.text( '100%' );
								$matchingText.text( '✓ Zakończono' );
								$matchingSection.css( 'opacity', '0.7' );

								if ( $filteringSection.is( ':visible' ) ) {
									$filteringBar.css( 'width', '100%' );
									$filteringPercent.text( '100%' );
									$filteringText.text( '✓ Zakończono' );
									$filteringSection.css( 'opacity', '0.7' );
								}

								$status.text( '\u2713 ' + data.message ).css( 'color', '#155724' );
								$btn.prop( 'disabled', false ).text( '\u21BB Reindeksuj teraz' );
								$cancelBtn.hide();
								showNotice( 'success', data.message );

								// Hide progress after 3s
								setTimeout( function() {
									if ( $progress.length ) {
										$progress.fadeOut();
									}
								}, 3000 );
							} else if ( data.rate_limited ) {
								// Gemini API rate limit – wait and retry same batch (progress not advanced)
								var retryMs = ( data.retry_after || 2 ) * 1000;
								$indexingText.text( data.message || 'Limit API – ponawianie...' );
								setTimeout( processBatch, retryMs );
							} else {
								// Continue with next batch
								processBatch();
							}
						} else {
							handleError( res.data );
						}
					},
					error : function( xhr, status, error ) {
						// On timeout or error, try to continue
						if ( status === 'timeout' ) {
							$indexingText.text( 'Timeout - ponawiam...' );
							setTimeout( processBatch, 1000 );
						} else {
							// Extract PHP error details from response body
							var detail = '';
							if ( xhr.responseText ) {
								try {
									var parsed = JSON.parse( xhr.responseText );
									if ( parsed.data ) { detail = parsed.data; }
								} catch ( e ) {
									var raw = xhr.responseText.replace( /<[^>]+>/g, '' ).trim();
									if ( raw.length > 0 ) { detail = raw.substring( 0, 300 ); }
								}
							}
							handleError( detail || ( 'HTTP ' + xhr.status + ' ' + error ) );
							refreshDebugLogs(); // Auto-refresh logs so user can see the cause
						}
					}
				} );
			}

			function handleError( msg ) {
				$status.text( '✗ Błąd serwera' ).css( 'color', '#a32d2d' );
				$btn.prop( 'disabled', false ).text( '↻ Reindeksuj teraz' );
				$cancelBtn.hide();
				showNotice( 'error', msg + ' — sprawdź Debug Logs poniżej.' );
				// Auto-expand debug section on error
				$( '#sl-debug-content' ).slideDown( 200 );
				$( '#sl-debug-arrow' ).css( 'transform', 'rotate(90deg)' );
				if ( $progress.length ) {
					$progress.hide();
				}
			}
		} );

		/* ── Cancel indexing button ───────────────────────────────── */
		$( '#sl-btn-cancel' ).on( 'click', function() {
			var $cancelBtn = $( this );
			var $btn       = $( '#sl-btn-reindex' );
			var $status    = $( '#sl-reindex-status' );
			var $progress  = $( '#sl-progress-wrap' );

			isIndexingCancelled = true;
			$cancelBtn.prop( 'disabled', true ).text( 'Anulowanie...' );

			$.ajax( {
				url  : slAjax.url,
				type : 'POST',
				data : {
					action : 'sl_cancel_indexing',
					nonce  : slAjax.nonce
				},
				success : function( res ) {
					$cancelBtn.hide().prop( 'disabled', false ).text( '✕ Anuluj proces' );
					$btn.prop( 'disabled', false ).text( '\u21BB Reindeksuj teraz' );
					$status.text( res.success ? res.data.message : 'Anulowano.' ).css( 'color', '#856404' );
					if ( $progress.length ) {
						$progress.hide();
					}
					showNotice( 'warning', 'Proces został anulowany.' );
				},
				error : function() {
					$cancelBtn.hide().prop( 'disabled', false ).text( '✕ Anuluj proces' );
					$btn.prop( 'disabled', false ).text( '\u21BB Reindeksuj teraz' );
					$status.text( 'Błąd anulowania.' ).css( 'color', '#a32d2d' );
					if ( $progress.length ) {
						$progress.hide();
					}
				}
			} );
		} );

		/* ── Helper: inject a WP-style notice at the top of .wrap ── */
		function showNotice( type, msg ) {
			var $wrap = $( '.wrap' ).first();

			/* Remove any previous dynamic notice */
			$wrap.find( '.sl-dyn-notice' ).remove();

			var $notice = $(
				'<div class="notice notice-' + type + ' is-dismissible sl-dyn-notice">' +
				'<p>' + $( '<span>' ).text( msg ).html() + '</p>' +
				'<button type="button" class="notice-dismiss">' +
				  '<span class="screen-reader-text">Zamknij</span>' +
				'</button>' +
				'</div>'
			);

			/* Insert right after the first child (page title) */
			$wrap.children().first().after( $notice );

			/* Wire the dismiss button */
			$notice.find( '.notice-dismiss' ).on( 'click', function() {
				$notice.remove();
			} );

			/* Auto-dismiss after 5 s */
			setTimeout( function() {
				$notice.fadeOut( 300, function() { $( this ).remove(); } );
			}, 5000 );
		}

		/* ── 4. Debug logs management ─────────────────────────────── */

		// Filter logs by context
		$( '#sl-log-filter' ).on( 'change', function() {
			filterLogs();
		} );

		// Search logs
		$( '#sl-log-search' ).on( 'input', function() {
			filterLogs();
		} );

		function filterLogs() {
			var context = $( '#sl-log-filter' ).val().toLowerCase();
			var search  = $( '#sl-log-search' ).val().toLowerCase();

			$( '.sl-log-row' ).each( function() {
				var $row        = $( this );
				var rowContext  = $row.data( 'context' ).toLowerCase();
				var searchable  = $row.data( 'searchable' );

				var matchContext = ( context === '' || rowContext === context );
				var matchSearch  = ( search === '' || searchable.indexOf( search ) !== -1 );

				$row.toggle( matchContext && matchSearch );
			} );
		}

		// Refresh logs via AJAX
		$( '#sl-btn-refresh-logs' ).on( 'click', function() {
			location.reload();
		} );

		// Copy logs to clipboard
		$( '#sl-btn-copy-logs' ).on( 'click', function() {
			var logs = [];
			$( '.sl-log-row:visible' ).each( function() {
				var $row = $( this );
				var time = $row.find( 'td:eq(0)' ).text().trim();
				var ctx  = $row.find( 'td:eq(1)' ).text().trim();
				var msg  = $row.find( 'td:eq(2)' ).text().trim();
				var data = $row.find( 'td:eq(3) pre' ).text().trim();

				logs.push( '[' + time + '] [' + ctx + '] ' + msg + ( data ? '\n' + data : '' ) );
			} );

			var text = logs.join( '\n\n' );

			if ( navigator.clipboard ) {
				navigator.clipboard.writeText( text ).then( function() {
					showNotice( 'success', 'Skopiowano ' + logs.length + ' wpisów logów do schowka.' );
				} );
			} else {
				// Fallback for older browsers
				var $temp = $( '<textarea>' );
				$( 'body' ).append( $temp );
				$temp.val( text ).select();
				document.execCommand( 'copy' );
				$temp.remove();
				showNotice( 'success', 'Skopiowano ' + logs.length + ' wpisów logów do schowka.' );
			}
		} );

		// Clear logs
		$( '#sl-btn-clear-logs' ).on( 'click', function() {
			if ( ! window.confirm( 'Wyczyścić wszystkie logi debugowania?' ) ) {
				return;
			}

			$.ajax( {
				url  : slAjax.url,
				type : 'POST',
				data : {
					action : 'sl_clear_debug',
					nonce  : slAjax.nonce
				},
				success : function( res ) {
					if ( res.success ) {
						$( '#sl-logs-tbody' ).html( '<tr><td colspan="4" style="color: #666;">Brak logów.</td></tr>' );
						showNotice( 'success', 'Logi zostały wyczyszczone.' );
					} else {
						showNotice( 'error', res.data || 'Błąd podczas czyszczenia logów.' );
					}
				},
				error : function() {
					showNotice( 'error', 'Błąd serwera – spróbuj ponownie.' );
				}
			} );
		} );

		/* ── 5. Custom URLs CRUD ───────────────────────────────────── */

		// Add new custom URL
		$( '#sl-btn-add-custom-url' ).on( 'click', function() {
			var $btn      = $( this );
			var $url      = $( '#sl-custom-url' );
			var $title    = $( '#sl-custom-title' );
			var $keywords = $( '#sl-custom-keywords' );

			var url      = $url.val().trim();
			var title    = $title.val().trim();
			var keywords = $keywords.val().trim();

			if ( ! url || ! title ) {
				showNotice( 'error', 'URL i tytuł są wymagane.' );
				return;
			}

			$btn.prop( 'disabled', true ).text( 'Dodawanie...' );

			$.ajax( {
				url  : slAjax.url,
				type : 'POST',
				data : {
					action   : 'sl_add_custom_url',
					nonce    : slAjax.nonce,
					url      : url,
					title    : title,
					keywords : keywords
				},
				success : function( res ) {
					if ( res.success ) {
						showNotice( 'success', res.data.message );
						// Clear form
						$url.val( '' );
						$title.val( '' );
						$keywords.val( '' );
						// Update counter
						$( '#sl-custom-url-count' ).text( res.data.count );
						// Reload page to show new URL
						location.reload();
					} else {
						showNotice( 'error', res.data );
						$btn.prop( 'disabled', false ).text( 'Dodaj URL' );
					}
				},
				error : function() {
					showNotice( 'error', 'Błąd serwera – spróbuj ponownie.' );
					$btn.prop( 'disabled', false ).text( 'Dodaj URL' );
				}
			} );
		} );

		// Delete custom URL
		$( document ).on( 'click', '.sl-btn-delete-custom-url', function() {
			var $btn = $( this );
			var id   = $btn.data( 'id' );

			if ( ! window.confirm( 'Usunąć ten URL?' ) ) {
				return;
			}

			$btn.prop( 'disabled', true ).text( '...' );

			$.ajax( {
				url  : slAjax.url,
				type : 'POST',
				data : {
					action : 'sl_delete_custom_url',
					nonce  : slAjax.nonce,
					id     : id
				},
				success : function( res ) {
					if ( res.success ) {
						$btn.closest( 'tr' ).fadeOut( 300, function() {
							$( this ).remove();
						} );
						$( '#sl-custom-url-count' ).text( res.data.count );
						showNotice( 'success', res.data.message );
					} else {
						showNotice( 'error', res.data );
						$btn.prop( 'disabled', false ).text( 'Usuń' );
					}
				},
				error : function() {
					showNotice( 'error', 'Błąd serwera – spróbuj ponownie.' );
					$btn.prop( 'disabled', false ).text( 'Usuń' );
				}
			} );
		} );

		// Edit custom URL - toggle edit mode
		$( document ).on( 'click', '.sl-btn-edit-custom-url', function() {
			var $row = $( this ).closest( 'tr' );
			$row.find( '.sl-custom-url-view' ).hide();
			$row.find( '.sl-custom-url-edit' ).show();
		} );

		// Cancel edit
		$( document ).on( 'click', '.sl-btn-cancel-edit', function() {
			var $row = $( this ).closest( 'tr' );
			$row.find( '.sl-custom-url-edit' ).hide();
			$row.find( '.sl-custom-url-view' ).show();
		} );

		// Save edited custom URL
		$( document ).on( 'click', '.sl-btn-save-custom-url', function() {
			var $btn  = $( this );
			var $row  = $btn.closest( 'tr' );
			var id    = $btn.data( 'id' );

			var url      = $row.find( '.sl-edit-url' ).val().trim();
			var title    = $row.find( '.sl-edit-title' ).val().trim();
			var keywords = $row.find( '.sl-edit-keywords' ).val().trim();

			if ( ! url || ! title ) {
				showNotice( 'error', 'URL i tytuł są wymagane.' );
				return;
			}

			$btn.prop( 'disabled', true ).text( '...' );

			$.ajax( {
				url  : slAjax.url,
				type : 'POST',
				data : {
					action   : 'sl_update_custom_url',
					nonce    : slAjax.nonce,
					id       : id,
					url      : url,
					title    : title,
					keywords : keywords
				},
				success : function( res ) {
					if ( res.success ) {
						showNotice( 'success', res.data.message );
						location.reload();
					} else {
						showNotice( 'error', res.data );
						$btn.prop( 'disabled', false ).text( 'Zapisz' );
					}
				},
				error : function() {
					showNotice( 'error', 'Błąd serwera – spróbuj ponownie.' );
					$btn.prop( 'disabled', false ).text( 'Zapisz' );
				}
			} );
		} );

		/* ── 6. Custom URL Threshold ──────────────────────────────── */

		// Update threshold value display when slider moves
		$( '#sl-custom-url-threshold' ).on( 'input', function() {
			var val = parseFloat( $( this ).val() );
			$( '#sl-custom-url-threshold-value' ).text( val.toFixed( 2 ) );
		} );

		// Save threshold via AJAX
		$( '#sl-btn-save-threshold' ).on( 'click', function() {
			var $btn      = $( this );
			var $msg      = $( '#sl-threshold-saved-msg' );
			var threshold = parseFloat( $( '#sl-custom-url-threshold' ).val() );

			$btn.prop( 'disabled', true ).text( 'Zapisywanie...' );

			$.ajax( {
				url  : slAjax.url,
				type : 'POST',
				data : {
					action    : 'sl_save_custom_url_threshold',
					nonce     : slAjax.nonce,
					threshold : threshold
				},
				success : function( res ) {
					$btn.prop( 'disabled', false ).text( 'Zapisz próg' );
					if ( res.success ) {
						$msg.fadeIn( 200 );
						setTimeout( function() {
							$msg.fadeOut( 200 );
						}, 2000 );
					} else {
						showNotice( 'error', res.data || 'Błąd zapisywania.' );
					}
				},
				error : function() {
					$btn.prop( 'disabled', false ).text( 'Zapisz próg' );
					showNotice( 'error', 'Błąd serwera – spróbuj ponownie.' );
				}
			} );
		} );

		/* ── 7. Debug Logs Toggle ────────────────────────────────────── */

		$( '#sl-debug-toggle' ).on( 'click', function() {
			var $content = $( '#sl-debug-content' );
			var $arrow   = $( '#sl-debug-arrow' );

			$content.slideToggle( 200, function() {
				if ( $content.is( ':visible' ) ) {
					$arrow.css( 'transform', 'rotate(90deg)' );
				} else {
					$arrow.css( 'transform', 'rotate(0deg)' );
				}
			} );
		} );

	} );   // ready

} )( jQuery );
