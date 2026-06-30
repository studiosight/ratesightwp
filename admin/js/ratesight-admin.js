/* global RatesightAdmin, navigator */
( function ( $ ) {
	'use strict';

	// ── Shared utilities ──────────────────────────────────────────────────────
	function esc( s ) {
		return String( s )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	$( function () {

		var ajax       = RatesightAdmin.ajax_url;
		var nonce      = RatesightAdmin.nonce;
		var rsLastSync = RatesightAdmin.last_sync || '';

		// ── Generic clipboard helper ──────────────────────────────────────────
		function copyText( text, onSuccess ) {
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then( onSuccess ).catch( function () {
					fallbackCopy( text, onSuccess );
				} );
			} else {
				fallbackCopy( text, onSuccess );
			}
		}

		function fallbackCopy( text, onSuccess ) {
			var $tmp = $( '<textarea>' )
				.css( { position: 'fixed', top: 0, left: 0, opacity: 0 } )
				.val( text ).appendTo( 'body' );
			$tmp[0].focus();
			$tmp[0].select();
			try { document.execCommand( 'copy' ); onSuccess(); } catch ( e ) {}
			$tmp.remove();
		}

		// ── Shortcode / URL copy buttons ──────────────────────────────────────
		$( document ).on( 'click', '.rs-btn-copy', function () {
			var $btn = $( this );
			var text = $btn.data( 'copy' );
			if ( ! text ) return;
			copyText( text, function () {
				var orig = $btn.text();
				$btn.text( 'Copied!' ).addClass( 'rs-copied' );
				setTimeout( function () { $btn.text( orig ).removeClass( 'rs-copied' ); }, 2000 );
			} );
		} );

		// ── AI worker health test ─────────────────────────────────────────────
		$( '#rs-test-ai-worker' ).on( 'click', function () {
			var $btn = $( this ).prop( 'disabled', true ).text( 'Testing…' );
			var $res = $( '#rs-ai-worker-result' ).show().css( 'color', '' ).text( 'Pinging worker…' );
			$.post( ajaxurl, { action: 'ratesight_test_ai_worker', nonce: rsAdmin.nonce } )
				.done( function ( r ) {
					$btn.prop( 'disabled', false ).text( 'Test Connection' );
					if ( r.success ) {
						$res.css( 'color', '#00a32a' ).text( '✓ ' + r.data.message );
					} else {
						$res.css( 'color', '#d63638' ).text( '✗ ' + ( r.data && r.data.message ? r.data.message : 'Failed.' ) );
					}
				} )
				.fail( function () {
					$btn.prop( 'disabled', false ).text( 'Test Connection' );
					$res.css( 'color', '#d63638' ).text( '✗ Request failed.' );
				} );
		} );

		// ── Send test request ─────────────────────────────────────────────────
		$( '#rs-regen-secret' ).on( 'click', function () {
			var isFirst = $( '#rs-webhook-secret' ).val() === '';
			var msg = isFirst
				? 'Generate a webhook secret? You can share it with integrations that support HMAC signing.'
				: 'Regenerate the webhook secret? Any integrations sending the old secret will stop matching until updated.';
			if ( ! confirm( msg ) ) return;
			var $btn = $( this ).prop( 'disabled', true ).text( isFirst ? 'Generating…' : 'Regenerating…' );
			$.post( ajax, { action: 'ratesight_regen_webhook_secret', nonce: nonce } )
				.done( function ( r ) {
					$btn.prop( 'disabled', false ).text( isFirst ? 'Generate Secret' : 'Regenerate' );
					if ( r.success ) {
						$( '#rs-webhook-secret' ).val( r.data.secret );
						$( '#rs-webhook-secret-wrap' ).show();
						$( '.rs-btn-copy[data-copy]' ).each( function() {
							if ( $( this ).prev( 'input' ).is( '#rs-webhook-secret' ) ) {
								$( this ).data( 'copy', r.data.secret ).attr( 'data-copy', r.data.secret );
							}
						} );
					}
				} )
				.fail( function () { $btn.prop( 'disabled', false ); } );
		} );

		$( '#rs-send-test' ).on( 'click', function () {
			var $btn      = $( this ).prop( 'disabled', true ).text( 'Sending…' );
			var $feedback = $( '#rs-test-feedback' ).show().text( '' ).removeClass( 'rs-feedback-ok rs-feedback-err' );

			$.post( ajax, { action: 'ratesight_send_test', nonce: nonce } )
				.done( function ( r ) {
					if ( r.success ) {
						$feedback.addClass( 'rs-feedback-ok' ).html(
							'✓ ' + r.data.message +
							( r.data.post_url ? ' <a href="' + r.data.post_url + '" target="_blank">View post ↗</a>' : '' )
						);
					} else {
						$feedback.addClass( 'rs-feedback-err' ).text( '✗ ' + ( r.data && r.data.message ? r.data.message : 'Unknown error.' ) );
					}
				} )
				.fail( function () {
					$feedback.addClass( 'rs-feedback-err' ).text( '✗ Request failed.' );
				} )
				.always( function () { $btn.prop( 'disabled', false ).text( 'Send Test Request' ); } );
		} );

		// ── Clear logs ────────────────────────────────────────────────────────
		$( '#rs-clear-logs' ).on( 'click', function () {
			if ( ! window.confirm( 'Delete all activity log entries? This cannot be undone.' ) ) {
				return;
			}
			var $btn = $( this ).prop( 'disabled', true ).text( 'Clearing…' );

			$.post( ajax, { action: 'ratesight_clear_logs', nonce: nonce } )
				.done( function ( r ) {
					if ( r.success ) {
						$( '#rs-log-table-wrap' ).fadeOut( 200, function () { $( this ).remove(); } );
						$( '.rs-log-bar' ).after( '<div class="rs-empty"><p>Activity log cleared.</p></div>' );
						$btn.remove();
					} else {
						$btn.prop( 'disabled', false ).text( 'Clear All' );
					}
				} )
				.fail( function () { $btn.prop( 'disabled', false ).text( 'Clear All' ); } );
		} );

		// ── Debug pending logs ───────────────────────────────────────────────────
		$( '#rs-debug-pending' ).on( 'click', function () {
			var $btn = $( this ).prop( 'disabled', true ).text( 'Checking…' );
			$.post( ajax, { action: 'ratesight_debug_pending_logs', nonce: nonce } )
				.done( function ( r ) {
					$btn.prop( 'disabled', false ).text( 'Debug Pending' );
					if ( r.success ) {
						var d = r.data;
						var out = 'null post_id: ' + d.null_post_id
							+ ' | zero post_id: ' + d.zero_post_id
							+ ' | has post_id: ' + d.has_post_id
							+ ' | title matches: ' + d.title_matches;
						if ( d.post_statuses && d.post_statuses.length ) {
							out += ' | post statuses: ' + d.post_statuses.map( function(s){ return s.post_status + '(' + s.cnt + ')'; } ).join(', ');
						}
						out += '\nSample rows: ' + JSON.stringify( d.sample_rows, null, 2 );
						alert( out );
					}
				} )
				.fail( function () { $btn.prop( 'disabled', false ).text( 'Debug Pending' ); } );
		} );


		$( '#rs-fix-log-status' ).on( 'click', function () {
			var $btn = $( this ).prop( 'disabled', true ).text( 'Fixing…' );
			$.post( ajax, { action: 'ratesight_fix_log_status', nonce: nonce } )
				.done( function ( r ) {
					$btn.prop( 'disabled', false ).text( 'Fix Log Status' );
					if ( r.success ) {
						var d = r.data;
						var msg = '✓ Resolved ' + d.resolved + ' entries';
						if ( d.orphaned ) { msg += ', ' + d.orphaned + ' marked failed (post deleted)'; }
						if ( d.still_pending ) { msg += '. ' + d.still_pending + ' still pending (post in draft).'; }
						$( '#rs-publish-drafts-feedback' ).show().css( 'color', '#00a32a' ).text( msg );
						$.post( ajax, { action: 'ratesight_get_logs', nonce: nonce } )
							.done( function ( lr ) {
								if ( lr.success ) { $( '#rs-log-table-wrap tbody' ).html( lr.data.html ); }
							} );
					}
				} )
				.fail( function () { $btn.prop( 'disabled', false ).text( 'Fix Log Status' ); } );
		} );


		$( '#rs-publish-drafts' ).on( 'click', function () {
			if ( ! window.confirm( 'This will publish all Ratesight pages that are currently in draft. You can navigate away — cron will finish the rest. Continue?' ) ) {
				return;
			}

			var $btn = $( this ).prop( 'disabled', true ).text( 'Publishing…' );
			var $fb  = $( '#rs-publish-drafts-feedback' ).show().text( 'Starting…' );

			function refreshLogs() {
				$.post( ajax, { action: 'ratesight_get_logs', nonce: nonce } )
					.done( function ( r ) {
						if ( r.success && r.data.html !== undefined ) {
							$( '#rs-log-table-wrap tbody' ).html( r.data.html );
						}
					} );
			}

			function runNext() {
				$.post( ajax, { action: 'ratesight_bulk_publish_drafts', nonce: nonce } )
					.done( function ( r ) {
						if ( ! r.success ) {
							$fb.css( 'color', '#d63638' ).text( '✗ ' + ( r.data && r.data.message ? r.data.message : 'Failed.' ) );
							$btn.prop( 'disabled', false ).text( 'Publish All Drafts' );
							refreshLogs();
							return;
						}
						var d = r.data;
						$fb.css( 'color', '' ).text( 'Published ' + d.published + ' / ' + d.total + '…' );
						refreshLogs();
						if ( d.done ) {
							var failNote = d.failed > 0 ? ' (' + d.failed + ' failed)' : '';
							$fb.css( 'color', '#00a32a' ).text( '✓ Done — ' + d.published + ' pages published' + failNote + '.' );
							$btn.prop( 'disabled', false ).text( 'Publish All Drafts' );
						} else {
							runNext();
						}
					} )
					.fail( function () {
						$fb.css( 'color', '#d63638' ).text( '✗ Request failed — cron will continue in background.' );
						$btn.prop( 'disabled', false ).text( 'Publish All Drafts' );
						refreshLogs();
					} );
			}

			runNext();
		} );

		// ── Retry failed log entry ───────────────────────────────────────────────
		$( '#rs-log-table-wrap' ).on( 'click', '.rs-retry-log', function () {
			var $btn   = $( this ).prop( 'disabled', true ).text( 'Retrying…' );
			var logId  = $btn.data( 'log-id' );
			var $row   = $btn.closest( 'tr' );

			$.post( ajax, { action: 'ratesight_retry_log', nonce: nonce, log_id: logId } )
				.done( function ( r ) {
					if ( r.success ) {
						$btn.text( 'Queued ✓' );
						$row.find( '.rs-pill' ).removeClass( 'failed' ).addClass( 'pending' ).text( 'Pending' );
						if ( r.data && r.data.post_url ) {
							$btn.after( ' <a href="' + r.data.post_url + '" target="_blank" style="font-size:11px;">View ↗</a>' );
						}
					} else {
						var msg = ( r.data && r.data.message ) ? r.data.message : 'Retry failed.';
						$btn.prop( 'disabled', false ).text( 'Retry' );
						$row.find( 'td:last-child' ).append( '<span style="color:#d63638;font-size:11px;display:block;">' + msg + '</span>' );
					}
				} )
				.fail( function () { $btn.prop( 'disabled', false ).text( 'Retry' ); } );
		} );

		// ── Retry GBP post step only ─────────────────────────────────────────────
		$( '#rs-log-table-wrap' ).on( 'click', '.rs-retry-gbp', function () {
			var $btn  = $( this ).prop( 'disabled', true ).text( 'Posting…' );
			var logId = $btn.data( 'log-id' );
			var $row  = $btn.closest( 'tr' );

			$.post( ajax, { action: 'ratesight_retry_gbp', nonce: nonce, log_id: logId } )
				.done( function ( r ) {
					if ( r.success ) {
						$btn.text( 'Posted ✓' );
						$row.find( '.rs-note' ).text( 'GBP post succeeded on retry.' );
					} else {
						var msg = ( r.data && r.data.message ) ? r.data.message : 'GBP retry failed.';
						$btn.prop( 'disabled', false ).text( 'Retry GBP' );
						$row.find( 'td:last-child' ).append( '<span style="color:#d63638;font-size:11px;display:block;">' + msg + '</span>' );
					}
				} )
				.fail( function () { $btn.prop( 'disabled', false ).text( 'Retry GBP' ); } );
		} );

		// ── Recheck pending log entry ────────────────────────────────────────────
		$( '#rs-log-table-wrap' ).on( 'click', '.rs-recheck-pending', function () {
			var $btn  = $( this ).prop( 'disabled', true ).text( 'Checking…' );
			var logId = $btn.data( 'log-id' );
			var $row  = $btn.closest( 'tr' );

			$.post( ajax, { action: 'ratesight_recheck_pending', nonce: nonce, log_id: logId } )
				.done( function ( r ) {
					if ( r.success ) {
						$btn.text( 'Resolved ✓' );
						$row.find( '.rs-pill' ).removeClass( 'pending' ).addClass( 'success' ).text( 'Success' );
						if ( r.data && r.data.post_id ) {
							$row.find( 'td:nth-child(5)' ).html( '<a class="rs-post-link" href="' + ( r.data.post_url || '#' ) + '" target="_blank">#' + r.data.post_id + '</a>' );
						}
					} else {
						var msg = ( r.data && r.data.message ) ? r.data.message : 'Recheck failed.';
						$btn.prop( 'disabled', false ).text( 'Recheck' );
						$row.find( 'td:last-child' ).append( '<span style="color:#d63638;font-size:11px;display:block;">' + msg + '</span>' );
					}
				} )
				.fail( function () { $btn.prop( 'disabled', false ).text( 'Recheck' ); } );
		} );

		// ── IndexNow status check ──────────────────────────────────────────────
		$( '#rs-check-indexnow' ).on( 'click', function () {
			var $btn = $( this ).prop( 'disabled', true ).text( 'Checking…' );
			$.post( ajax, { action: 'ratesight_indexnow_status', nonce: nonce } )
				.done( function ( r ) {
					if ( r.success ) {
						var d        = r.data;
						var yes      = '<span style="color:#00a32a;font-weight:600;">✅ Verified</span>';
						var no       = '<span style="color:#d63638;font-weight:600;">❌ Not reachable</span>';
						$( '#rs-indexnow-url' ).html( '<code>' + esc( d.key_url ) + '</code>' );
						$( '#rs-indexnow-verified' ).html( d.verified ? yes : no + ' — visit the URL above to debug' );
						$( '#rs-indexnow-result' ).show();
					}
				} )
				.always( function () { $btn.prop( 'disabled', false ).text( 'Check IndexNow Key' ); } );
		} );

		// ── Schema status ──────────────────────────────────────────────────────
		$( document ).on( 'click', '#rs-load-schema-status', function () {
			var $btn    = $( this ).prop( 'disabled', true ).text( 'Checking…' );
			var $result = $( '#rs-schema-result' );

			// Load all ranking rows and check schema status per post.
			$.post( ajax, { action: 'ratesight_get_rankings', nonce: nonce, days: 90 } )
				.done( function ( r ) {
					if ( ! r.success || ! r.data.rows.length ) {
						$result.html( '<p style="color:#646970;font-size:13px;">No posts found. Run a GSC sync first.</p>' );
						$btn.prop( 'disabled', false ).text( 'Check Schema Status' );
						return;
					}

					// For each post, check schema via a single preview call.
					var rows   = r.data.rows.slice( 0, 50 ); // Cap at 50 for performance
					var checks = rows.map( function ( row ) {
						return $.post( ajax, { action: 'ratesight_preview_schema', nonce: nonce, post_id: row.post_id } )
							.then( function ( sr ) {
								return { row: row, schema: sr.success ? sr.data : null };
							} );
					} );

					$.when.apply( $, checks ).done( function () {
						var results = Array.from( arguments );
						// $.when with single item returns the data directly, multiple returns arrays
						if ( rows.length === 1 ) results = [ results ];

						var hasSchema  = results.filter( function(x) { return x && x[0] && x[0].schema && x[0].schema.has_schema; } );
						var noSchema   = results.filter( function(x) { return x && x[0] && x[0].schema && ! x[0].schema.has_schema; } );

						var html = '<div style="display:flex;gap:16px;margin-bottom:14px;">' +
							'<div style="background:#edfaef;border:1px solid #c3eed0;border-radius:4px;padding:10px 16px;text-align:center;">' +
								'<div style="font-size:22px;font-weight:700;color:#00a32a;">' + hasSchema.length + '</div>' +
								'<div style="font-size:11px;color:#646970;margin-top:2px;">Has Schema</div>' +
							'</div>' +
							'<div style="background:#fef8e7;border:1px solid #f0d98a;border-radius:4px;padding:10px 16px;text-align:center;">' +
								'<div style="font-size:22px;font-weight:700;color:#7a5800;">' + noSchema.length + '</div>' +
								'<div style="font-size:11px;color:#646970;margin-top:2px;">Missing Schema</div>' +
							'</div>' +
						'</div>';

						if ( noSchema.length ) {
							html += '<p style="font-size:13px;margin-bottom:10px;"><strong>Missing schema — click to preview and add:</strong></p>' +
							'<table class="wp-list-table widefat fixed striped">' +
							'<thead><tr><th>Post</th><th style="width:120px">Detected Type</th><th style="width:160px">Action</th></tr></thead><tbody>' +
							noSchema.map( function ( x ) {
								var row    = x[0].row;
								var schema = x[0].schema;
								var type   = schema ? esc( schema.detected_type ) : '—';
								return '<tr id="rs-schema-row-' + row.post_id + '">' +
									'<td>' + esc( row.post_title ) + '</td>' +
									'<td><code>' + type + '</code></td>' +
									'<td>' +
										'<button type="button" class="button rs-preview-schema" data-post-id="' + row.post_id + '" data-type="">Preview &amp; Add</button>' +
									'</td>' +
								'</tr>' +
								'<tr id="rs-schema-preview-' + row.post_id + '" style="display:none;">' +
								'<td colspan="3" style="padding:10px 14px;">' +
									'<div style="background:#f6f7f7;border-radius:4px;padding:12px;">' +
										'<label style="font-size:12px;font-weight:600;display:block;margin-bottom:6px;">Schema type: ' +
											'<select class="rs-schema-type-sel" data-post-id="' + row.post_id + '">' +
												'<option value="Article">Article</option>' +
												'<option value="FAQPage">FAQPage</option>' +
												'<option value="Service">Service</option>' +
												'<option value="LocalBusiness">LocalBusiness</option>' +
												'<option value="WebPage">WebPage</option>' +
											'</select>' +
										'</label>' +
										'<textarea class="large-text rs-schema-json" rows="10" style="font-family:monospace;font-size:11px;margin-bottom:8px;"></textarea>' +
										'<div style="display:flex;gap:8px;">' +
											'<button type="button" class="button button-primary rs-save-schema-btn" data-post-id="' + row.post_id + '">Save Schema</button>' +
											'<button type="button" class="button rs-cancel-schema" data-post-id="' + row.post_id + '">Cancel</button>' +
										'</div>' +
									'</div>' +
								'</td>' +
								'</tr>';
							} ).join('') +
							'</tbody></table>';
						}

						$result.html( html );
						$btn.prop( 'disabled', false ).text( 'Refresh' );
					} );
				} )
				.fail( function () {
					$result.html( '<p style="color:#d63638;">Request failed.</p>' );
					$btn.prop( 'disabled', false ).text( 'Check Schema Status' );
				} );
		} );

		// Preview schema for a specific post
		$( document ).on( 'click', '.rs-preview-schema', function () {
			var $btn    = $( this ).prop( 'disabled', true ).text( 'Loading…' );
			var postId  = $( this ).data( 'post-id' );
			var type    = $( this ).data( 'type' ) || '';
			var $row    = $( '#rs-schema-preview-' + postId );

			$.post( ajax, { action: 'ratesight_preview_schema', nonce: nonce, post_id: postId, type: type } )
				.done( function ( r ) {
					if ( r.success ) {
						$row.find( '.rs-schema-json' ).val( r.data.json );
						$row.find( '.rs-schema-type-sel' ).val( r.data.detected_type );
						$row.show();
					} else {
						alert( r.data && r.data.message ? r.data.message : 'Failed.' );
					}
					$btn.prop( 'disabled', false ).text( 'Preview & Add' );
				} )
				.fail( function () { $btn.prop( 'disabled', false ).text( 'Preview & Add' ); } );
		} );

		// Regenerate preview when type changes
		$( document ).on( 'change', '.rs-schema-type-sel', function () {
			var postId = $( this ).data( 'post-id' );
			var type   = $( this ).val();
			var $row   = $( '#rs-schema-preview-' + postId );

			$.post( ajax, { action: 'ratesight_preview_schema', nonce: nonce, post_id: postId, type: type } )
				.done( function ( r ) {
					if ( r.success ) $row.find( '.rs-schema-json' ).val( r.data.json );
				} );
		} );

		// Save schema
		$( document ).on( 'click', '.rs-save-schema-btn', function () {
			var $btn    = $( this ).prop( 'disabled', true ).text( 'Saving…' );
			var postId  = $( this ).data( 'post-id' );
			var $row    = $( '#rs-schema-preview-' + postId );
			var json    = $row.find( '.rs-schema-json' ).val();

			$.post( ajax, { action: 'ratesight_save_schema', nonce: nonce, post_id: postId, schema_json: json } )
				.done( function ( r ) {
					if ( r.success ) {
						$( '#rs-schema-row-' + postId ).find( 'td:last-child' ).html(
							'<span style="color:#00a32a;font-size:12px;">✅ Schema saved</span>'
						);
						$row.hide();
					} else {
						alert( r.data && r.data.message ? r.data.message : 'Save failed.' );
						$btn.prop( 'disabled', false ).text( 'Save Schema' );
					}
				} )
				.fail( function () { $btn.prop( 'disabled', false ).text( 'Save Schema' ); } );
		} );

		// Cancel schema preview
		$( document ).on( 'click', '.rs-cancel-schema', function () {
			var postId = $( this ).data( 'post-id' );
			$( '#rs-schema-preview-' + postId ).hide();
			$( '#rs-schema-row-' + postId ).find( '.rs-preview-schema' ).prop( 'disabled', false ).text( 'Preview & Add' );
		} );
		$( '#rs-check-sitemap' ).on( 'click', function () {
			var $btn = $( this ).prop( 'disabled', true ).text( 'Checking…' );

			$.post( ajax, { action: 'ratesight_sitemap_status', nonce: nonce } )
				.done( function ( r ) {
					if ( r.success ) {
						var d   = r.data;
						var yes = '<span style="color:#00a32a;font-weight:600;">✅ Yes</span>';
						var no  = '<span style="color:#d63638;font-weight:600;">❌ No</span>';

						$( '#rs-sitemap-url' ).text( d.sitemap_url || '—' );
						$( '#rs-sitemap-live' ).html( d.sitemap_live ? yes : no + ' — sitemap.xml not reachable' );
						$( '#rs-sitemap-gsc' ).html( d.gsc_submitted ? yes : no + ' — <a href="https://search.google.com/search-console" target="_blank">Submit in GSC →</a>' );
						$( '#rs-sitemap-bing' ).html( d.bing_submitted ? yes : ( d.bing_verified ? no + ' — sitemap not submitted to Bing' : no + ' — domain not verified in Bing Webmaster Tools' ) );
						$( '#rs-sitemap-result' ).show();
					} else {
						alert( r.data && r.data.message ? r.data.message : 'Check failed.' );
					}
				} )
				.fail( function () { alert( 'Request failed.' ); } )
				.always( function () { $btn.prop( 'disabled', false ).text( 'Check Sitemap Status' ); } );
		} );

		// Load GBP locations
		$( '#rs-load-gbp-locations' ).on( 'click', function () {
			var $btn = $( this ).prop( 'disabled', true );
			var $fb  = $( '#rs-gbp-load-feedback' ).show();
			$.post( ajax, { action: 'ratesight_list_gbp', nonce: nonce } )
				.done( function ( r ) {
					if ( r.success && r.data.locations && r.data.locations.length ) {
						// Sort alphabetically by label
						var allLocs = r.data.locations.slice().sort( function ( a, b ) {
							return a.label.localeCompare( b.label );
						} );

						function rebuildGbp( query ) {
							var $sel = $( '#rs-gbp-location-select' ).empty().append( '<option value="">— Select a location —</option>' );
							var q = ( query || '' ).toLowerCase();
							$.each( allLocs, function ( i, loc ) {
								if ( ! q || loc.label.toLowerCase().indexOf( q ) !== -1 || ( loc.sublabel && loc.sublabel.toLowerCase().indexOf( q ) !== -1 ) ) {
									var display = loc.label + ( loc.sublabel ? ' (' + loc.sublabel + ')' : '' );
									$sel.append( $( '<option>', { value: loc.id, text: display } ).data( 'sublabel', loc.sublabel || '' ) );
								}
							} );
						}

						rebuildGbp( '' );

						$( '#rs-gbp-filter' )
							.val( '' )
							.off( 'input.gbp' )
							.on( 'input.gbp', function () { rebuildGbp( $( this ).val() ); } );

						$( '#rs-gbp-location-picker' ).show();
						$fb.hide();
					} else {
						$fb.text( r.data && r.data.message ? r.data.message : 'Failed to load locations.' );
					}
				} )
				.fail( function () { $fb.text( 'Request failed.' ); } )
				.always( function () { $btn.prop( 'disabled', false ); } );
		} );

		// Lock GBP location
		$( document ).on( 'click', '#rs-lock-gbp-btn', function () {
			var $sel   = $( '#rs-gbp-location-select' );
			var loc_id = $sel.val();
			var label  = $sel.find( 'option:selected' ).text();
			if ( ! loc_id ) { alert( 'Please select a location from the list first.' ); return; }
			if ( ! confirm( 'Lock "' + label + '"?\n\nYou will need to disconnect to change it.' ) ) { return; }
			var $btn = $( this ).prop( 'disabled', true ).text( 'Locking…' );
			$.post( ajax, { action: 'ratesight_lock_gbp', nonce: nonce, location_id: loc_id, label: label } )
				.done( function ( r ) {
					if ( r.success ) {
						$.post( ajax, { action: 'ratesight_sync_gbp_now', nonce: nonce } )
							.always( function () { location.reload(); } );
					} else { $btn.prop( 'disabled', false ).text( 'Lock This Location' ); alert( r.data && r.data.message ? r.data.message : 'Failed.' ); }
				} )
				.fail( function () { $btn.prop( 'disabled', false ).text( 'Lock This Location' ); alert( 'Request failed.' ); } );
		} );

		// Disconnect GBP
		$( '#rs-gbp-disconnect-btn' ).on( 'click', function () {
			var confirm_text = $( '#rs-gbp-disconnect-input' ).val();
			if ( confirm_text !== 'DISCONNECT' ) {
				alert( 'Type DISCONNECT in the box to confirm.' );
				$( '#rs-gbp-disconnect-input' ).trigger( 'focus' );
				return;
			}
			$.post( ajax, { action: 'ratesight_disconnect_gbp', nonce: nonce, confirm: confirm_text } )
				.done( function ( r ) {
					if ( r.success ) { location.reload(); }
					else { alert( r.data && r.data.message ? r.data.message : 'Failed.' ); }
				} );
		} );

		// Load GSC properties
		$( '#rs-load-gsc-properties' ).on( 'click', function () {
			var $btn = $( this ).prop( 'disabled', true );
			var $fb  = $( '#rs-gsc-load-feedback' ).show();
			$.post( ajax, { action: 'ratesight_list_gsc', nonce: nonce } )
				.done( function ( r ) {
					if ( r.success && r.data.properties && r.data.properties.length ) {
						function gscSortKey( url ) {
							return url
								.replace( /^sc-domain:/, '' )
								.replace( /^https?:\/\//, '' )
								.replace( /^www\./, '' )
								.toLowerCase();
						}

						// Sort alphabetically by actual domain name
						var allProps = r.data.properties.slice().sort( function ( a, b ) {
							return gscSortKey( a.url ).localeCompare( gscSortKey( b.url ) );
						} );

						function rebuildGsc( query ) {
							var $sel = $( '#rs-gsc-property-select' ).empty().append( '<option value="">— Select a property —</option>' );
							var q = ( query || '' ).toLowerCase();
							$.each( allProps, function ( i, prop ) {
								if ( ! q || prop.url.toLowerCase().indexOf( q ) !== -1 || gscSortKey( prop.url ).indexOf( q ) !== -1 ) {
									$sel.append( $( '<option>', { value: prop.url, text: prop.url } ) );
								}
							} );
						}

						rebuildGsc( '' );

						$( '#rs-gsc-filter' )
							.val( '' )
							.off( 'input.gsc' )
							.on( 'input.gsc', function () { rebuildGsc( $( this ).val() ); } );

						$( '#rs-gsc-property-picker' ).show();
						$fb.hide();
					} else {
						$fb.text( r.data && r.data.message ? r.data.message : 'Failed to load properties.' );
					}
				} )
				.fail( function () { $fb.text( 'Request failed.' ); } )
				.always( function () { $btn.prop( 'disabled', false ); } );
		} );

		// Lock GSC property
		$( document ).on( 'click', '#rs-lock-gsc-btn', function () {
			var prop_url = $( '#rs-gsc-property-select' ).val();
			if ( ! prop_url ) { alert( 'Please select a property from the list first.' ); return; }
			if ( ! confirm( 'Lock "' + prop_url + '"?\n\nYou will need to disconnect to change it.' ) ) { return; }
			var $btn = $( this ).prop( 'disabled', true ).text( 'Locking…' );
			$.post( ajax, { action: 'ratesight_lock_gsc', nonce: nonce, property_url: prop_url } )
				.done( function ( r ) {
					if ( r.success ) {
						if ( r.data && r.data.notes && r.data.notes.length ) {
							alert( r.data.notes.join( '\n' ) );
						}
						// Auto-sync immediately on first lock (page data + finalise).
						$.post( ajax, { action: 'ratesight_sync_gsc_now', nonce: nonce } )
							.always( function () {
								$.post( ajax, { action: 'ratesight_sync_gsc_finalise', nonce: nonce } )
									.always( function () { location.reload(); } );
							} );
					} else { $btn.prop( 'disabled', false ).text( 'Lock This Property' ); alert( r.data && r.data.message ? r.data.message : 'Failed.' ); }
				} )
				.fail( function () { $btn.prop( 'disabled', false ).text( 'Lock This Property' ); alert( 'Request failed.' ); } );
		} );

		$( '#rs-toggle-deepseek-key' ).on( 'click', function () {
			var $input = $( '#ratesight_deepseek_api_key' );
			$input.attr( 'type', $input.attr( 'type' ) === 'password' ? 'text' : 'password' );
		} );

		// Quick disconnect button next to email address (no typing required)
		$( document ).on( 'click', '.rs-quick-disconnect', function () {
			var service = $( this ).data( 'service' );
			var label   = service === 'gsc' ? 'Google Search Console' : 'Google Business Profile';
			if ( ! confirm( 'Disconnect ' + label + '? You will need to reconnect to restore access.' ) ) return;
			var $btn = $( this ).prop( 'disabled', true ).text( 'Disconnecting…' );
			var action = service === 'gsc' ? 'ratesight_disconnect_gsc' : 'ratesight_disconnect_gbp';
			$.post( ajax, { action: action, nonce: nonce, confirm: 'DISCONNECT' } )
				.done( function ( r ) {
					if ( r.success ) { location.reload(); }
					else { $btn.prop( 'disabled', false ).text( 'Disconnect' ); alert( r.data && r.data.message ? r.data.message : 'Failed.' ); }
				} )
				.fail( function () { $btn.prop( 'disabled', false ).text( 'Disconnect' ); } );
		} );

		// Disconnect GSC
		$( '#rs-gsc-disconnect-btn' ).on( 'click', function () {
			var confirm_text = $( '#rs-gsc-disconnect-input' ).val();
			if ( confirm_text !== 'DISCONNECT' ) {
				alert( 'Type DISCONNECT in the box to confirm.' );
				$( '#rs-gsc-disconnect-input' ).trigger( 'focus' );
				return;
			}
			$.post( ajax, { action: 'ratesight_disconnect_gsc', nonce: nonce, confirm: confirm_text } )
				.done( function ( r ) {
					if ( r.success ) { location.reload(); }
					else { alert( r.data && r.data.message ? r.data.message : 'Failed.' ); }
				} );
		} );

		// ── Bing Webmaster Tools ─────────────────────────────────────────────────

		$( '#rs-save-bing-key' ).on( 'click', function () {
			var key = $( '#rs-bing-api-key' ).val().trim();
			if ( ! key ) { alert( 'Please enter your Bing API key.' ); return; }
			var $btn = $( this ).prop( 'disabled', true ).text( 'Saving…' );
			var $fb  = $( '#rs-bing-key-feedback' ).show().text( '' );
			$.post( ajax, { action: 'ratesight_save_bing_key', nonce: nonce, api_key: key } )
				.done( function ( r ) {
					if ( r.success ) { location.reload(); }
					else { $fb.text( r.data && r.data.message ? r.data.message : 'Failed.' ); $btn.prop( 'disabled', false ).text( 'Save Key' ); }
				} )
				.fail( function () { $fb.text( 'Request failed.' ); $btn.prop( 'disabled', false ).text( 'Save Key' ); } );
		} );

		$( '#rs-load-bing-sites' ).on( 'click', function () {
			var $btn = $( this ).prop( 'disabled', true );
			var $fb  = $( '#rs-bing-sites-feedback' ).show().text( 'Loading…' );
			$.post( ajax, { action: 'ratesight_load_bing_sites', nonce: nonce } )
				.done( function ( r ) {
					if ( r.success && r.data.sites && r.data.sites.length ) {
						var $sel = $( '#rs-bing-site-select' ).empty().append( '<option value="">— Select a site —</option>' );
						$.each( r.data.sites, function ( i, site ) {
							var url = typeof site === 'string' ? site : ( site.Url || site.url || JSON.stringify( site ) );
							$sel.append( $( '<option>', { value: url, text: url } ) );
						} );
						$( '#rs-bing-site-picker' ).show();
						$fb.hide();
					} else {
						$fb.text( r.data && r.data.message ? r.data.message : 'No sites found. Make sure your site is verified in Bing Webmaster Tools.' );
					}
				} )
				.fail( function () { $fb.text( 'Request failed.' ); } )
				.always( function () { $btn.prop( 'disabled', false ); } );
		} );

		$( '#rs-lock-bing-btn' ).on( 'click', function () {
			var site = $( '#rs-bing-site-select' ).val();
			if ( ! site ) { alert( 'Please select a site first.' ); return; }
			if ( ! confirm( 'Lock "' + site + '" for Bing data?' ) ) { return; }
			var $btn = $( this ).prop( 'disabled', true ).text( 'Locking…' );
			$.post( ajax, { action: 'ratesight_lock_bing_site', nonce: nonce, site_url: site } )
				.done( function ( r ) {
					if ( r.success ) {
						// Trigger first sync automatically then reload.
						$.post( ajax, { action: 'ratesight_sync_bing_now', nonce: nonce } )
							.always( function () { location.reload(); } );
					} else {
						alert( r.data && r.data.message ? r.data.message : 'Failed.' );
						$btn.prop( 'disabled', false ).text( 'Lock Site' );
					}
				} )
				.fail( function () { $btn.prop( 'disabled', false ).text( 'Lock Site' ); } );
		} );

		$( document ).on( 'click', '#rs-sync-bing-now', function () {
			var $btn = $( this ).prop( 'disabled', true ).text( 'Syncing…' );
			var $fb  = $( '#rs-sync-bing-feedback' ).show().css( 'color', '' ).text( 'Syncing…' );
			$.post( ajax, { action: 'ratesight_sync_bing_now', nonce: nonce } )
				.done( function ( r ) {
					$btn.prop( 'disabled', false ).text( 'Sync Now' );
					if ( r.success ) {
						$fb.css( 'color', '#00a32a' ).text( '✓ ' + ( r.data.message || 'Sync complete.' ) );
						setTimeout( function () { location.reload(); }, 1500 );
					} else {
						$fb.css( 'color', '#d63638' ).text( '✗ ' + ( r.data && r.data.message ? r.data.message : 'Sync failed.' ) );
					}
				} )
				.fail( function () {
					$btn.prop( 'disabled', false ).text( 'Sync Now' );
					$fb.css( 'color', '#d63638' ).text( '✗ Request failed.' );
				} );
		} );

		$( document ).on( 'click', '#rs-sync-gbp-conn-now', function () {
			var $btn = $( this ).prop( 'disabled', true ).text( 'Syncing…' );
			var $fb  = $( '#rs-sync-gbp-conn-feedback' ).show().css( 'color', '' ).text( 'Syncing…' );
			$.post( ajax, { action: 'ratesight_sync_gbp_now', nonce: nonce } )
				.done( function ( r ) {
					$btn.prop( 'disabled', false ).text( 'Sync Now' );
					if ( r.success ) {
						$fb.css( 'color', '#00a32a' ).text( '✓ ' + ( r.data.message || 'Sync complete.' ) );
						setTimeout( function () { location.reload(); }, 1500 );
					} else {
						$fb.css( 'color', '#d63638' ).text( '✗ ' + ( r.data && r.data.message ? r.data.message : 'Sync failed.' ) );
					}
				} )
				.fail( function () {
					$btn.prop( 'disabled', false ).text( 'Sync Now' );
					$fb.css( 'color', '#d63638' ).text( '✗ Request failed.' );
				} );
		} );

		$( document ).on( 'click', '#rs-sync-bing-perf', function () {
			var $btn = $( this ).prop( 'disabled', true ).text( 'Syncing…' );
			var $fb  = $( '#rs-sync-bing-perf-feedback' ).show().css( 'color', '' ).text( '' );
			$.post( ajax, { action: 'ratesight_sync_bing_now', nonce: nonce } )
				.done( function ( r ) {
					$btn.prop( 'disabled', false ).text( 'Sync Now' );
					if ( r.success ) {
						$fb.css( 'color', '#00a32a' ).text( '✓ Done' );
						setTimeout( function () { location.reload(); }, 1000 );
					} else {
						$fb.css( 'color', '#d63638' ).text( '✗ ' + ( r.data && r.data.message ? r.data.message : 'Failed.' ) );
					}
				} )
				.fail( function () { $btn.prop( 'disabled', false ).text( 'Sync Now' ); } );
		} );

		$( document ).on( 'click', '.rs-bing-period', function () {
			var days = $( this ).data( 'days' );
			var url  = new URL( window.location.href );
			url.searchParams.set( 'bing_days', days );
			window.location.href = url.toString();
		} );

		$( document ).on( 'click', '#rs-clear-indexnow-log', function () {
			if ( ! confirm( 'Clear IndexNow submission log?' ) ) return;
			$.post( ajax, { action: 'ratesight_clear_indexnow_log', nonce: nonce } )
				.done( function ( r ) { if ( r.success ) location.reload(); } );
		} );

		// ── Inline add category ───────────────────────────────────────────────────
		$( document ).on( 'click', '.rs-add-cat-btn', function () {
			var target = $( this ).data( 'target' );
			$( '.rs-add-cat-form[data-for="' + target + '"]' ).show().find( '.rs-new-cat-name' ).val( '' ).focus();
			$( this ).hide();
		} );

		$( document ).on( 'click', '.rs-cancel-cat-btn', function () {
			var target = $( this ).data( 'target' );
			$( '.rs-add-cat-form[data-for="' + target + '"]' ).hide();
			$( '.rs-add-cat-btn[data-target="' + target + '"]' ).show();
		} );

		$( document ).on( 'click', '.rs-save-cat-btn', function () {
			var $btn      = $( this ).prop( 'disabled', true );
			var target    = $btn.data( 'target' );
			var taxonomy  = $btn.data( 'taxonomy' );
			var $form     = $( '.rs-add-cat-form[data-for="' + target + '"]' );
			var name      = $form.find( '.rs-new-cat-name' ).val().trim();
			var $fb       = $form.find( '.rs-add-cat-feedback' );

			if ( ! name ) { $fb.css('color','#d63638').text('Enter a name.'); $btn.prop('disabled',false); return; }

			$.post( ajax, { action: 'ratesight_add_category', nonce: nonce, name: name, taxonomy: taxonomy } )
				.done( function ( r ) {
					$btn.prop( 'disabled', false );
					if ( r.success ) {
						// Add the new option to the select and select it.
						var $select = $( '#' + target );
						if ( $select.find( 'option[value="' + r.data.term_id + '"]' ).length === 0 ) {
							$select.append( $( '<option>', { value: r.data.term_id, text: r.data.name } ) );
						}
						$select.val( r.data.term_id );
						$fb.css('color','#00a32a').text( r.data.existed ? '✓ Already exists — selected.' : '✓ Added.' );
						setTimeout( function () { $form.hide(); $( '.rs-add-cat-btn[data-target="' + target + '"]' ).show(); $fb.text(''); }, 1500 );
					} else {
						$fb.css('color','#d63638').text( r.data && r.data.message ? r.data.message : 'Failed.' );
					}
				} )
				.fail( function () { $btn.prop('disabled',false); $fb.css('color','#d63638').text('Request failed.'); } );
		} );

		$( document ).on( 'keydown', '.rs-new-cat-name', function ( e ) {
			if ( e.key === 'Enter' ) { e.preventDefault(); $( this ).closest( '.rs-add-cat-form' ).find( '.rs-save-cat-btn' ).trigger( 'click' ); }
		} );

		// ── Period toggle (7d / 30d / 90d) ────────────────────────────────────
		var activePeriod = ( RatesightAdmin.active_days ? parseInt( RatesightAdmin.active_days ) : 30 );

		var rsSort        = { col: 'impressions', dir: -1 }; // col, dir: 1=asc, -1=desc

		// Expected CTR by position (simplified scale used for bar colouring).
		function expectedCtr( pos ) {
			var table = [0, 28, 15, 11, 8, 6, 4, 3, 2.5, 2, 1.5];
			if ( pos < 1 ) return 0;
			if ( pos <= 10 ) return table[ Math.round( pos ) ] || 1.5;
			return 0.5;
		}

		function buildCtrCell( ctr, position ) {
			var pct     = parseFloat( ctr ) * 100 || 0;
			var exp     = expectedCtr( parseFloat( position ) );
			var ratio   = exp > 0 ? pct / exp : 1;
			var color   = ratio >= 0.8 ? '#16a34a' : ratio >= 0.4 ? '#d97706' : '#dc2626';
			var barPct  = Math.min( 100, ( pct / Math.max( exp, pct, 1 ) ) * 100 ).toFixed(0);
			if ( pct === 0 ) return '<td><span style="color:#787c82;">—</span></td>';
			return '<td title="Expected ~' + exp.toFixed(1) + '% at this position">' +
				'<div style="display:flex;flex-direction:column;gap:2px;">' +
					'<span style="font-size:12px;font-weight:600;color:' + color + ';">' + pct.toFixed(1) + '%</span>' +
					'<div style="height:3px;background:#f0f0f1;border-radius:2px;width:50px;">' +
						'<div style="height:3px;background:' + color + ';border-radius:2px;width:' + barPct + '%;"></div>' +
					'</div>' +
				'</div>' +
			'</td>';
		}

		function buildSparkline( sparklineStr, currentPos ) {
			if ( ! sparklineStr ) return '<td style="min-width:56px;"></td>';
			var pts = sparklineStr.split( ',' ).map( parseFloat ).filter( function(v) { return ! isNaN(v) && v > 0; } );
			if ( ! pts.length ) return '<td style="min-width:56px;"></td>';
			var w = 52, h = 20, pad = 3;
			var min = Math.min.apply( null, pts );
			var max = Math.max.apply( null, pts );
			var range = max - min || 1;
			// In SVG y=0 is top. High position = bad = draw LOW on chart (high y).
			function scaleY( v ) { return pad + ( ( v - min ) / range ) * ( h - pad * 2 ); }
			var first = pts[0], last = pts[ pts.length - 1 ];
			var color = last < first - 0.5 ? '#16a34a' : last > first + 0.5 ? '#dc2626' : '#9ca3af';
			var svg = '<svg width="' + w + '" height="' + h + '" viewBox="0 0 ' + w + ' ' + h + '" style="display:block;overflow:visible;">';
			if ( pts.length === 1 ) {
				// Single point — draw a dot
				svg += '<circle cx="' + (w/2) + '" cy="' + scaleY(pts[0]).toFixed(1) + '" r="2.5" fill="' + color + '"/>';
			} else {
				var coords = pts.map( function( v, i ) {
					return ( ( i / ( pts.length - 1 ) ) * w ).toFixed(1) + ',' + scaleY(v).toFixed(1);
				} ).join( ' ' );
				svg += '<polyline points="' + coords + '" fill="none" stroke="' + color + '" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>';
			}
			svg += '</svg>';
			return '<td style="min-width:56px;">' + svg + '</td>';
		}

		function updateOrganicCards( rows ) {
			if ( ! rows || ! rows.length || ! $( '#rs-organic-cards' ).length ) return;
			var imp = 0, clicks = 0, prevImp = 0, prevClicks = 0, top3 = 0, top10 = 0, newCount = 0;
			rows.forEach( function ( r ) {
				imp       += parseInt( r.impressions )      || 0;
				clicks    += parseInt( r.clicks )           || 0;
				prevImp   += parseInt( r.prev_impressions ) || 0;
				prevClicks+= parseInt( r.prev_clicks )      || 0;
				var p      = parseFloat( r.position )       || 0;
				if ( p > 0 ) {
					if ( p <= 10 ) top10++;
					if ( p <= 3  ) top3++;
				}
				if ( parseInt( r.is_new ) ) newCount++;
			} );

			function pctDelta( now, prev ) {
				if ( prev <= 0 ) return null;
				return Math.round( ( ( now - prev ) / prev ) * 100 );
			}

			$( '#rs-organic-cards' ).find( '[data-oc]' ).each( function () {
				var key = $( this ).data( 'oc' );
				var val = key === 'impressions' ? imp.toLocaleString()
						: key === 'clicks'      ? clicks.toLocaleString()
						: key === 'top3'        ? top3
						: key === 'top10'       ? top10
						: key === 'new'         ? newCount : '';
				$( this ).text( val );
			} );

			// Hide new rankings card when 0
			var $newCard = $( '#rs-organic-cards [data-oc="new"]' ).closest( 'div' );
			if ( newCount === 0 ) {
				$newCard.hide();
			} else {
				$newCard.show();
			}

			// Update delta badges
			var deltas = {
				impressions: pctDelta( imp, prevImp ),
				clicks:      pctDelta( clicks, prevClicks ),
			};
			$( '#rs-organic-cards' ).find( '[data-oc-delta]' ).each( function () {
				var key = $( this ).data( 'oc-delta' );
				var d   = deltas[ key ];
				if ( d === null || d === undefined ) { $( this ).hide(); return; }
				var up  = d >= 0;
				$( this ).text( ( up ? '+' : '' ) + d + '%' )
					.css( { color: up ? '#15803d' : '#dc2626', background: up ? '#f0fdf4' : '#fef2f2' } )
					.show();
			} );
		}
		var rsCurrentRows = [];

		// Sort on column header click — works for both initial load and filter changes.
		$( document ).on( 'click', '.rs-sort-th', function () {
			var col = $( this ).data( 'col' );
			if ( ! col || ! rsCurrentRows.length ) return;
			if ( rsSort.col === col ) {
				rsSort.dir *= -1; // flip direction
			} else {
				rsSort.col = col;
				// Sensible defaults: position asc (lower = better), everything else desc
				rsSort.dir = col === 'position' ? 1 : -1;
			}
			var $tableWrap = $( '#rs-rankings-table' ).closest( 'div[style]' ).parent();
			$tableWrap.html( buildRankingsTable( rsCurrentRows ) );
		} );

		function rsSortRows( rows ) {
			var col = rsSort.col, dir = rsSort.dir;
			return rows.slice().sort( function ( a, b ) {
				var va, vb;
				if ( col === 'title' ) {
					va = ( a.post_title || '' ).toLowerCase();
					vb = ( b.post_title || '' ).toLowerCase();
					return dir * va.localeCompare( vb );
				}
				va = parseFloat( a[ col ] ) || 0;
				vb = parseFloat( b[ col ] ) || 0;
				// Position: lower number = better rank, so treat 0 as worst
				if ( col === 'position' ) {
					if ( va === 0 ) va = 9999;
					if ( vb === 0 ) vb = 9999;
				}
				return dir * ( va - vb );
			} );
		}

		function buildRankingsTable( rows ) {
			if ( ! rows || ! rows.length ) {
				return '<div class="rs-empty"><p>No data in this period.</p></div>';
			}

			var trendLabel = activePeriod + 'd Trend';

			function sortTh( col, label, width ) {
				var active = rsSort.col === col;
				var arrow  = active ? ( rsSort.dir === -1 ? ' ↓' : ' ↑' ) : '';
				var style  = 'cursor:pointer;user-select:none;white-space:nowrap;' + ( width ? 'width:' + width + ';' : 'min-width:200px;' );
				if ( active ) style += 'color:#1877F2;';
				return '<th class="rs-sort-th" data-col="' + col + '" style="' + style + '">' + label + arrow + '</th>';
			}

			var thead = '<thead><tr>' +
				sortTh( 'title',          'Post Title',      '' ) +
				sortTh( 'impressions',    'Impressions',     '100px' ) +
				sortTh( 'clicks',         'Clicks',          '70px' ) +
				sortTh( 'position',       'Position',        '105px' ) +
				sortTh( 'position_start', trendLabel,        '90px' ) +
				'<th style="width:60px;white-space:nowrap;" title="30-day position history">30d</th>' +
				sortTh( 'ctr',            'CTR',             '80px' ) +
				'<th style="width:50px;">↗</th>' +
				'</tr></thead>';

			var pinSvg = '<svg width="12" height="15" viewBox="0 0 24 30" xmlns="http://www.w3.org/2000/svg" style="vertical-align:-2px;margin-right:5px;flex-shrink:0;"><path fill="#1877F2" d="M12 0C5.373 0 0 5.373 0 12c0 8.5 12 18 12 18s12-9.5 12-18c0-6.627-5.373-12-12-12zm0 16.5a4.5 4.5 0 1 1 0-9 4.5 4.5 0 0 1 0 9z"/></svg>';

			var tbody = rsSortRows( rows ).map( function ( row ) {
				var pos      = parseFloat( row.position );
				var posStart = row.position_start !== null && row.position_start !== '' ? parseFloat( row.position_start ) : null;
				// Positive trend = improved (position number went down = higher ranking).
				var trend    = posStart !== null ? ( posStart - pos ).toFixed(1) : null;
				var posColor = pos <= 3 ? '#00a32a' : ( pos <= 10 ? '#1877F2' : '#646970' );
				var isRsPage = row.post_type === 'ratesight_page';

				function trendCell( d ) {
					if ( d === null ) return '<td><span class="rs-flat">—</span></td>';
					var f = parseFloat( d );
					if ( Math.abs( f ) < 0.1 ) return '<td><span class="rs-flat">→</span></td>';
					// Cap — large delta means sparse history at period start, not a real signal.
					if ( Math.abs( f ) > 50 ) return '<td><span class="rs-flat" title="Insufficient history for reliable trend">—</span></td>';
					var cls = f > 0 ? 'rs-up' : 'rs-down';
					var arr = f > 0 ? '↑' : '↓';
					return '<td><span class="' + cls + '">' + arr + Math.abs( f ).toFixed(1) + '</span></td>';
				}

				var titleHtml = ( row.edit_url ? '<a href="' + esc( row.edit_url || '' ) + '" target="_blank">' + esc( row.post_title || '(no title)' ) + '</a>' : esc( row.post_title || '(no title)' ) );

				return '<tr class="rs-rank-row" data-post-id="' + row.post_id + '">' +
					'<td>' +
						( isRsPage ? pinSvg : '' ) +
						titleHtml +
						' <button type="button" class="rs-kw-toggle button button-small" data-post-id="' + row.post_id + '" style="font-size:10px;margin-left:6px;">Keywords</button>' +
					'</td>' +
					'<td style="white-space:nowrap;"><strong>' + ( parseInt( row.impressions ) > 0 ? parseInt( row.impressions ).toLocaleString() : '—' ) + '</strong></td>' +
					'<td style="white-space:nowrap;">' + ( parseInt( row.clicks ) > 0 ? parseInt( row.clicks ).toLocaleString() : '—' ) + '</td>' +
					'<td style="white-space:nowrap;"><span style="color:' + posColor + ';font-weight:600;">' + ( pos > 0 ? '#' + pos.toFixed(1) : '—' ) + '</span></td>' +
					trendCell( trend ) +
					buildSparkline( row.sparkline || '', pos ) +
					buildCtrCell( row.ctr, pos ) +
					'<td>' + ( row.url ? '<a href="' + esc( row.url ) + '" target="_blank" class="rs-post-link">↗</a>' : '—' ) + '</td>' +
					'</tr>' +
					'<tr class="rs-kw-row" id="rs-kw-' + row.post_id + '" style="display:none;">' +
					'<td colspan="8" style="padding:0 14px 14px;"><div class="rs-kw-content" style="color:#646970;font-size:12px;padding:8px 0;">Loading keywords…</div></td>' +
					'</tr>';
			} ).join('');

			return '<div style="overflow-x:auto;"><table class="wp-list-table widefat striped" id="rs-rankings-table" style="table-layout:auto;min-width:700px;">' + thead + '<tbody>' + tbody + '</tbody></table></div>';
		}

		$( document ).on( 'click', '.rs-period-btn', function () {
			var $btn = $( this );
			var days = parseInt( $btn.data( 'days' ) );
			if ( days === activePeriod ) return;

			activePeriod = days;

			// Update button styles
			$( '.rs-period-btn' ).css( { background: '#fff', color: '#646970', fontWeight: 'normal' } ).removeClass( 'rs-period-active' );
			$btn.css( { background: '#1877F2', color: '#fff', fontWeight: '600' } ).addClass( 'rs-period-active' );

			var $tableWrap = $( '#rs-rankings-table' ).closest( 'div, table' ).parent();
			$tableWrap.html( '<p style="color:#646970;font-size:13px;padding:12px 0;">Loading…</p>' );

			$.post( ajax, { action: 'ratesight_get_rankings', nonce: nonce, days: days } )
				.done( function ( r ) {
					if ( r.success ) {
						rsCurrentRows = r.data.rows || [];
						$tableWrap.html( buildRankingsTable( rsCurrentRows ) );
						updateOrganicCards( rsCurrentRows );
					} else {
						$tableWrap.html( '<p style="color:#d63638;">Failed to load data.</p>' );
					}
				} )
				.fail( function () {
					$tableWrap.html( '<p style="color:#d63638;">Request failed.</p>' );
				} );
		} );

		// Keyword toggle per ranking row
		$( document ).on( 'click', '.rs-kw-toggle', function () {
			var postId  = $( this ).data( 'post-id' );
			var $kwRow  = $( '#rs-kw-' + postId );
			var $content = $kwRow.find( '.rs-kw-content' );

			if ( $kwRow.is( ':visible' ) ) {
				$kwRow.hide();
				return;
			}

			$kwRow.show();

			// Only load once
			if ( $content.data( 'loaded' ) ) return;

			$content.text( 'Loading keywords…' );

			$.post( ajax, { action: 'ratesight_get_keywords', nonce: nonce, post_id: postId } )
				.done( function ( r ) {
					if ( r.success && r.data.keywords && r.data.keywords.length ) {
						var rows = r.data.keywords.map( function ( kw ) {
							var pos     = parseFloat( kw.position ).toFixed(1);
							var pos7    = kw.position_7d  ? parseFloat( kw.position_7d ).toFixed(1)  : null;
							var pos30   = kw.position_30d ? parseFloat( kw.position_30d ).toFixed(1) : null;
							var delta7  = pos7  ? ( parseFloat( pos7  ) - parseFloat( pos ) ).toFixed(1) : null;
							var delta30 = pos30 ? ( parseFloat( pos30 ) - parseFloat( pos ) ).toFixed(1) : null;

							function renderDelta( d ) {
								if ( d === null ) return '<td style="font-size:12px;color:#787c82;">—</td>';
								var f = parseFloat( d );
								var cls = f > 0 ? 'rs-up' : ( f < 0 ? 'rs-down' : 'rs-flat' );
								var arrow = f > 0 ? '↑' : ( f < 0 ? '↓' : '→' );
								return '<td><span class="' + cls + '">' + arrow + Math.abs( f ) + '</span></td>';
							}

							var posColor = parseFloat( pos ) <= 3 ? '#00a32a' : ( parseFloat( pos ) <= 10 ? '#1877F2' : '#646970' );

							return '<tr>' +
								'<td style="font-size:12px;">' + esc( kw.query ) + '</td>' +
								'<td style="font-size:12px;">' + kw.impressions + '</td>' +
								'<td style="font-size:12px;">' + kw.clicks + '</td>' +
								'<td><span style="color:' + posColor + ';font-weight:600;font-size:12px;">#' + pos + '</span></td>' +
								renderDelta( delta7 ) +
								renderDelta( delta30 ) +
								'<td style="font-size:12px;">' + parseFloat( kw.ctr ).toFixed(1) + '%</td>' +
							'</tr>';
						} ).join('');

						$content.html(
							'<table class="rs-kw-table">' +
							'<thead><tr>' +
							'<th style="font-size:11px;color:#787c82;padding:4px 10px;">Query</th>' +
							'<th style="font-size:11px;color:#787c82;padding:4px 10px;">Impr</th>' +
							'<th style="font-size:11px;color:#787c82;padding:4px 10px;">Clicks</th>' +
							'<th style="font-size:11px;color:#787c82;padding:4px 10px;">Pos</th>' +
							'<th style="font-size:11px;color:#787c82;padding:4px 10px;">7d Δ</th>' +
							'<th style="font-size:11px;color:#787c82;padding:4px 10px;">30d Δ</th>' +
							'<th style="font-size:11px;color:#787c82;padding:4px 10px;">CTR</th>' +
							'</tr></thead><tbody>' + rows + '</tbody></table>'
						);
						$content.data( 'loaded', true );
					} else {
						$content.text( 'No keyword data yet — will appear after the next sync.' );
						$content.data( 'loaded', true );
					}
				} )
				.fail( function () { $content.text( 'Failed to load keywords.' ); } );
		} );

		$( document ).on( 'click', '#rs-sync-gbp-now', function () {
			var $btn = $( this ).prop( 'disabled', true ).text( 'Syncing…' );
			$.post( ajax, { action: 'ratesight_sync_gbp_now', nonce: nonce } )
				.done( function ( r ) {
					if ( r.success ) {
						var metrics = r.data && r.data.metrics ? r.data.metrics : [];
						var dbRows  = r.data.db_rows || 0;
						var sample  = r.data.db_sample;
						$btn.text( '✓ ' + ( r.data.message || 'Synced' ) );
						var logLines = metrics.map( function(m) { return '• ' + m; } );
						logLines.push( '• DB rows stored: ' + dbRows );
						if ( sample ) {
							logLines.push( '• Sample: ' + sample.date + ' search:' + sample.search_impressions + ' maps:' + sample.maps_impressions );
						}
						var $log = $( '<div style="font-size:11px;color:#787c82;margin-top:6px;line-height:1.6;">' + logLines.join('<br>') + '</div>' );
						$btn.after( $log );
						// If rows were stored, reload the overview tab to show updated data.
						if ( dbRows > 0 ) {
							$log.append( '<br>• Reloading to update overview…' );
							setTimeout( function () {
								window.location.href = window.location.href.split('#')[0] + '&ptab=local&_r=' + Date.now();
							}, 2000 );
						} else {
							setTimeout( function () { $btn.prop( 'disabled', false ).text( 'Sync GBP Performance' ); $log.remove(); }, 15000 );
						}
					} else {
						var msg = r.data && r.data.message ? r.data.message : 'Sync failed.';
						$btn.prop( 'disabled', false ).text( 'Sync GBP Performance' );
						alert( '⚠ GBP Sync: ' + msg );
					}
				} )
				.fail( function () { $btn.prop( 'disabled', false ).text( 'Sync GBP Performance' ); alert( 'Request failed.' ); } );
		} );

		// ── Review velocity ────────────────────────────────────────────────────
		$( document ).on( 'click', '#rs-check-review-velocity', function () {
			var $btn = $( this ).prop( 'disabled', true ).text( 'Checking…' );
			$.post( ajax, { action: 'ratesight_review_velocity', nonce: nonce } )
				.done( function ( r ) {
					if ( r.success ) {
						var d      = r.data;
						var icon, color, bg, border;

						if ( d.status === 'no_data' ) {
							icon = '⏳'; color = '#646970'; bg = '#f6f7f7'; border = '#dcdcde';
						} else if ( d.status === 'good' ) {
							icon = '✅'; color = '#00a32a'; bg = '#edfaef'; border = '#c3eed0';
						} else {
							icon = '⚠️'; color = '#7a5800'; bg = '#fff8e1'; border = '#ffe57f';
						}

						var html = '<div style="background:' + bg + ';border:1px solid ' + border + ';border-radius:4px;padding:10px 14px;font-size:13px;color:' + color + ';max-width:500px;">' +
							icon + ' ' + esc( d.message ) +
							( d.total ? ' <span style="color:#787c82;font-size:12px;">(' + d.total + ' total, avg ' + parseFloat( d.avg_rating || 0 ).toFixed(1) + '★)</span>' : '' ) +
							( d.status === 'no_data' ? '<br><span style="font-size:12px;color:#787c82;">GBP performance syncs weekly. Come back after the first sync completes.</span>' : '' ) +
							'</div>';
						$( '#rs-review-velocity-result' ).html( html );
					}
				} )
				.always( function () { $btn.prop( 'disabled', false ).text( 'Check Review Velocity' ); } );
		} );

		// ── Q&A ────────────────────────────────────────────────────────────────
		$( document ).on( 'click', '#rs-load-qa', function () {
			var $btn     = $( this ).prop( 'disabled', true ).text( 'Loading…' );
			var $loading = $( '#rs-qa-loading' ).show();
			var $content = $( '#rs-qa-content' );

			$.post( ajax, { action: 'ratesight_get_qa', nonce: nonce } )
				.done( function ( r ) {
					if ( r.success ) {
						var d         = r.data;

						if ( d.unavailable ) {
							$content.html( '<p style="font-size:13px;color:#787c82;">Google Q&amp;A API access is not enabled for this account. <a href="https://developers.google.com/my-business/content/review-data" target="_blank">Learn about requesting access →</a></p>' );
							$btn.prop( 'disabled', false ).text( 'Retry' );
							return;
						}

						var questions = d.questions || [];
						var unanswered = d.unanswered_count || 0;

						if ( ! questions.length ) {
							$content.html( '<p style="color:#646970;font-size:13px;">No Q&amp;A found for this location.</p>' );
							return;
						}

						var statsHtml = unanswered > 0
							? '<div style="background:#fff8e1;border:1px solid #ffe57f;border-radius:4px;padding:8px 12px;font-size:13px;color:#7a5800;margin-bottom:14px;">⚠️ <strong>' + unanswered + '</strong> unanswered question' + ( unanswered !== 1 ? 's' : '' ) + '</div>'
							: '<div style="background:#edfaef;border:1px solid #c3eed0;border-radius:4px;padding:8px 12px;font-size:13px;color:#00a32a;margin-bottom:14px;">✅ All questions answered</div>';

						var qHtml = questions.map( function ( q ) {
							var text         = q.text || q.question || '';
							var questionName = q.name || '';
							var hasAnswer    = q.topAnswers && q.topAnswers.length > 0;
							var answerText   = hasAnswer ? q.topAnswers[0].text : '';
							var safeId       = questionName.replace( /\//g, '-' );

							return '<div class="rs-review-card">' +
								'<div class="rs-review-meta" style="margin-bottom:6px;">' +
									( q.upvoteCount ? '<span style="font-size:11px;color:#787c82;">👍 ' + q.upvoteCount + ' upvotes</span>' : '' ) +
								'</div>' +
								'<div style="font-weight:600;font-size:13px;margin-bottom:8px;">Q: ' + esc( text ) + '</div>' +
								( hasAnswer
									? '<div class="rs-reply-box"><strong>A:</strong> ' + esc( answerText ) + '</div>'
									: '<div>' +
										'<button type="button" class="button rs-draft-answer" data-question-name="' + esc( questionName ) + '" data-question="' + esc( text ) + '">Draft AI Answer</button>' +
										'<div class="rs-reply-area" id="rs-qa-' + esc( safeId ) + '" style="display:none;">' +
											'<textarea class="rs-reply-draft" placeholder="AI answer will appear here…"></textarea>' +
											'<div style="display:flex;gap:8px;margin-top:6px;">' +
												'<button type="button" class="button button-primary rs-post-answer" data-question-name="' + esc( questionName ) + '">Post Answer</button>' +
												'<button type="button" class="button rs-cancel-answer">Cancel</button>' +
											'</div>' +
										'</div>' +
									'</div>'
								) +
							'</div>';
						} ).join('');

						$content.html( statsHtml + qHtml );
						$btn.prop( 'disabled', false ).text( 'Refresh' );
					} else {
						$content.html( '<p style="color:#d63638;">' + esc( r.data && r.data.message ? r.data.message : 'Failed.' ) + '</p>' );
						$btn.prop( 'disabled', false ).text( 'Retry' );
					}
				} )
				.fail( function () {
					$content.html( '<p style="color:#d63638;">Request failed.</p>' );
					$btn.prop( 'disabled', false ).text( 'Retry' );
				} )
				.always( function () { $loading.hide(); } );
		} );

		// Draft AI answer for Q&A
		$( document ).on( 'click', '.rs-draft-answer', function () {
			var $btn      = $( this ).prop( 'disabled', true ).text( 'Drafting…' );
			var name      = $( this ).data( 'question-name' );
			var question  = $( this ).data( 'question' );
			var safeId    = name.replace( /\//g, '-' );
			var $area     = $( '#rs-qa-' + safeId );

			var prompt = 'Write a helpful, professional answer to this Google Business Q&A question: "' + question + '". Keep it under 100 words. Sound like the business owner.';

			$.post( ajax, { action: 'ratesight_ai_chat', nonce: nonce, prompt: prompt, context: 'local' } )
				.done( function ( r ) {
					if ( r.success ) {
						$area.find( '.rs-reply-draft' ).val( r.data.reply );
						$area.show();
					} else {
						alert( r.data && r.data.message ? r.data.message : 'Could not generate answer.' );
					}
					$btn.prop( 'disabled', false ).text( 'Draft AI Answer' );
				} )
				.fail( function () {
					alert( 'Request failed.' );
					$btn.prop( 'disabled', false ).text( 'Draft AI Answer' );
				} );
		} );

		$( document ).on( 'click', '.rs-post-answer', function () {
			var $btn         = $( this ).prop( 'disabled', true ).text( 'Posting…' );
			var questionName = $( this ).data( 'question-name' );
			var $area        = $( this ).closest( '.rs-reply-area' );
			var text         = $area.find( '.rs-reply-draft' ).val();
			if ( ! text.trim() ) { alert( 'Answer is empty.' ); $btn.prop( 'disabled', false ).text( 'Post Answer' ); return; }

			$.post( ajax, { action: 'ratesight_answer_question', nonce: nonce, question_name: questionName, text: text } )
				.done( function ( r ) {
					if ( r.success ) {
						$area.closest( '.rs-review-card' ).find( '.rs-draft-answer' ).parent().replaceWith(
							'<div class="rs-reply-box"><strong>A:</strong> ' + esc( text ) + '</div>'
						);
					} else {
						alert( r.data && r.data.message ? r.data.message : 'Failed.' );
						$btn.prop( 'disabled', false ).text( 'Post Answer' );
					}
				} )
				.fail( function () { alert( 'Request failed.' ); $btn.prop( 'disabled', false ).text( 'Post Answer' ); } );
		} );

		$( document ).on( 'click', '.rs-cancel-answer', function () {
			$( this ).closest( '.rs-reply-area' ).hide().find( 'textarea' ).val('');
			$( this ).closest( '.rs-review-card' ).find( '.rs-draft-answer' ).prop( 'disabled', false ).text( 'Draft AI Answer' );
		} );

		// ── Keyword cannibalization ────────────────────────────────────────────
		$( document ).on( 'click', '#rs-check-cannibalization', function () {
			var $btn    = $( this ).prop( 'disabled', true ).text( 'Checking…' );
			var $result = $( '#rs-cannibalization-result' );
			$result.html( '<span style="color:#646970;font-size:13px;">Analysing keyword overlaps…</span>' );

			$.post( ajax, { action: 'ratesight_get_cannibalization', nonce: nonce } )
				.done( function ( r ) {
					if ( r.success ) {
						var conflicts = r.data.conflicts || [];

						if ( ! conflicts.length ) {
							$result.html( '<div style="background:#edfaef;border:1px solid #c3eed0;border-radius:4px;padding:10px 14px;font-size:13px;color:#00a32a;">✅ No keyword cannibalization detected.</div>' );
							return;
						}

						var html = '<p style="font-size:13px;color:#d63638;font-weight:600;margin-bottom:12px;">⚠️ ' + conflicts.length + ' cannibalized keyword' + ( conflicts.length !== 1 ? 's' : '' ) + ' detected:</p>';

						html += conflicts.map( function ( c ) {
							var primary   = c.primary;
							var competing = c.competing || [];

							var compHtml = competing.map( function ( p ) {
								return '<li style="font-size:12px;margin-bottom:4px;">' +
									'<strong>' + esc( p.post_title ) + '</strong> — position #' + parseFloat( p.position ).toFixed(1) + ', ' + p.impressions + ' impressions' +
									'<br><span style="color:#787c82;">Fix: ' +
									( parseFloat( p.position ) > parseFloat( primary.position ) + 5
										? 'Add <code>noindex</code> or 301-redirect to the primary page'
										: 'Differentiate the content angle — target a related but distinct keyword'
									) + '</span></li>';
							} ).join('');

							return '<div style="background:#fff8e1;border:1px solid #ffe57f;border-radius:4px;padding:12px 14px;margin-bottom:10px;">' +
								'<strong style="font-size:13px;">Query: "' + esc( c.query ) + '"</strong>' +
								'<div style="font-size:12px;color:#00a32a;margin:6px 0 4px;">✅ Primary: <strong>' + esc( primary.post_title ) + '</strong> — position #' + parseFloat( primary.position ).toFixed(1) + ', ' + primary.impressions + ' impressions (keep this one)</div>' +
								'<ul style="margin:4px 0 0 14px;padding:0;">' + compHtml + '</ul>' +
								'</div>';
						} ).join('');

						$result.html( html );
					} else {
						$result.html( '<p style="color:#d63638;">' + esc( r.data && r.data.message ? r.data.message : 'Failed.' ) + '</p>' );
					}
				} )
				.fail( function () { $result.html( '<p style="color:#d63638;">Request failed.</p>' ); } )
				.always( function () { $btn.prop( 'disabled', false ).text( 'Check for Cannibalization' ); } );
		} );

		// ── Content improvement queue ──────────────────────────────────────────
		$( document ).on( 'click', '#rs-load-improvement-queue', function () {
			var $btn    = $( this ).prop( 'disabled', true ).text( 'Loading…' );
			var $result = $( '#rs-improvement-result' );
			$result.html( '<span style="color:#646970;font-size:13px;">Finding opportunities…</span>' );

			$.post( ajax, { action: 'ratesight_get_improvement_queue', nonce: nonce } )
				.done( function ( r ) {
					if ( r.success ) {
						var pages = r.data.pages || [];

						if ( ! pages.length ) {
							$result.html( '<div style="background:#edfaef;border:1px solid #c3eed0;border-radius:4px;padding:10px 14px;font-size:13px;color:#00a32a;">✅ No obvious CTR improvements needed right now.</div>' );
							return;
						}

						var tableHtml = '<table class="wp-list-table widefat fixed striped" id="rs-improve-table">' +
							'<thead><tr>' +
							'<th>Page</th>' +
							'<th style="width:100px">Impressions</th>' +
							'<th style="width:80px">Position</th>' +
							'<th style="width:70px">CTR</th>' +
							'<th style="width:130px">Action</th>' +
							'</tr></thead><tbody>' +
							pages.map( function ( p ) {
								return '<tr id="rs-improve-row-' + p.post_id + '">' +
									'<td><strong>' + esc( p.post_title ) + '</strong>' +
									( p.edit_url ? ' <a href="' + esc( p.edit_url ) + '" target="_blank" style="font-size:11px;">Edit ↗</a>' : '' ) +
									'<div style="font-size:11px;color:#787c82;margin-top:3px;">' +
										'Current title: ' + esc( p.meta_title || '(none)' ) +
									'</div>' +
									'</td>' +
									'<td><strong>' + parseInt( p.impressions ).toLocaleString() + '</strong></td>' +
									'<td><span style="color:#1877F2;font-weight:600;">#' + parseFloat( p.position ).toFixed(1) + '</span></td>' +
									'<td>' + parseFloat( p.ctr ).toFixed(1) + '%</td>' +
									'<td><button type="button" class="button rs-rewrite-meta"' +
										' data-post-id="' + p.post_id + '"' +
										' data-title="' + esc( p.meta_title || p.post_title ) + '"' +
										' data-desc="' + esc( p.meta_desc || '' ) + '"' +
										' data-impressions="' + p.impressions + '"' +
										' data-position="' + p.position + '"' +
										'>Rewrite</button></td>' +
									'</tr>' +
									'<tr id="rs-improve-edit-' + p.post_id + '" style="display:none;">' +
									'<td colspan="5" style="padding:10px 14px;">' +
										'<div style="background:#f6f7f7;border-radius:4px;padding:12px;">' +
											'<label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Title Tag</label>' +
											'<input type="text" class="large-text rs-new-title" value="" style="margin-bottom:8px;">' +
											'<label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Meta Description</label>' +
											'<textarea class="large-text rs-new-desc" rows="2" style="margin-bottom:8px;"></textarea>' +
											'<div style="display:flex;gap:8px;">' +
												'<button type="button" class="button button-primary rs-save-meta" data-post-id="' + p.post_id + '">Save</button>' +
												'<button type="button" class="button rs-cancel-rewrite" data-post-id="' + p.post_id + '">Cancel</button>' +
											'</div>' +
										'</div>' +
									'</td>' +
									'</tr>';
							} ).join('') +
							'</tbody></table>';

						$result.html( '<p style="font-size:13px;color:#646970;margin-bottom:12px;">' + pages.length + ' pages with ranking potential. Click Rewrite to get an AI-generated title and meta description.</p>' + tableHtml );
						$btn.prop( 'disabled', false ).text( 'Refresh' );
					} else {
						$result.html( '<p style="color:#d63638;">' + esc( r.data && r.data.message ? r.data.message : 'Failed.' ) + '</p>' );
						$btn.prop( 'disabled', false ).text( 'Retry' );
					}
				} )
				.fail( function () { $result.html( '<p style="color:#d63638;">Request failed.</p>' ); $btn.prop( 'disabled', false ).text( 'Retry' ); } );
		} );

		// Get AI rewrite for a page
		$( document ).on( 'click', '.rs-rewrite-meta', function () {
			var $btn      = $( this ).prop( 'disabled', true ).text( 'Writing…' );
			var postId    = $( this ).data( 'post-id' );
			var $editRow  = $( '#rs-improve-edit-' + postId );
			var topQuery  = ''; // Could be enhanced with keyword data

			$.post( ajax, {
				action:       'ratesight_rewrite_meta',
				nonce:         nonce,
				post_id:       postId,
				current_title: $( this ).data( 'title' ),
				current_desc:  $( this ).data( 'desc' ),
				top_query:     topQuery,
				impressions:   $( this ).data( 'impressions' ),
				position:      $( this ).data( 'position' ),
			} )
			.done( function ( r ) {
				if ( r.success ) {
					$editRow.find( '.rs-new-title' ).val( r.data.title );
					$editRow.find( '.rs-new-desc' ).val( r.data.meta_description );
					$editRow.show();
				} else {
					alert( r.data && r.data.message ? r.data.message : 'Could not generate rewrite.' );
				}
				$btn.prop( 'disabled', false ).text( 'Rewrite' );
			} )
			.fail( function () { alert( 'Request failed.' ); $btn.prop( 'disabled', false ).text( 'Rewrite' ); } );
		} );

		// Save rewritten meta
		$( document ).on( 'click', '.rs-save-meta', function () {
			var $btn    = $( this ).prop( 'disabled', true ).text( 'Saving…' );
			var postId  = $( this ).data( 'post-id' );
			var $row    = $( '#rs-improve-edit-' + postId );
			var title   = $row.find( '.rs-new-title' ).val().trim();
			var desc    = $row.find( '.rs-new-desc' ).val().trim();

			if ( ! title ) { alert( 'Title cannot be empty.' ); $btn.prop( 'disabled', false ).text( 'Save' ); return; }

			$.post( ajax, {
				action:           'ratesight_save_meta',
				nonce:             nonce,
				post_id:           postId,
				title:             title,
				meta_description:  desc,
			} )
			.done( function ( r ) {
				if ( r.success ) {
					$row.hide();
					$( '#rs-improve-row-' + postId ).find( 'div[style]' ).text( 'Title: ' + title );
					$( '#rs-improve-row-' + postId ).find( '.rs-rewrite-meta' ).prop( 'disabled', false ).text( 'Rewrite again' );
				} else {
					alert( r.data && r.data.message ? r.data.message : 'Save failed.' );
					$btn.prop( 'disabled', false ).text( 'Save' );
				}
			} )
			.fail( function () { alert( 'Request failed.' ); $btn.prop( 'disabled', false ).text( 'Save' ); } );
		} );

		$( document ).on( 'click', '.rs-cancel-rewrite', function () {
			var postId = $( this ).data( 'post-id' );
			$( '#rs-improve-edit-' + postId ).hide();
			$( '#rs-improve-row-' + postId ).find( '.rs-rewrite-meta' ).prop( 'disabled', false ).text( 'Rewrite' );
		} );

		function renderProfileHealth( r ) {
			var $loading = $( '#rs-profile-health-loading' );
			var $btn     = $( '#rs-load-profile-health' );
			var $content = $( '#rs-profile-health-content' );
			$loading.hide();
			if ( r && r.success ) {
				var d       = r.data;
				var pct     = d.pct || 0;
				var checks  = d.checks || [];
				var color   = pct >= 80 ? '#00a32a' : ( pct >= 50 ? '#7a5800' : '#d63638' );

				var checksHtml = checks.map( function ( c ) {
					var icon   = c.ok === true ? '✅' : ( c.ok === 'warn' ? '⚠️' : '❌' );
					var cls    = c.ok === true ? 'rs-health-ok' : ( c.ok === 'warn' ? 'rs-health-warn' : 'rs-health-fail' );
					return '<div class="rs-health-item">' +
						'<span class="' + cls + '">' + icon + '</span>' +
						'<div><strong style="font-size:13px;">' + esc( c.label ) + '</strong>' +
						'<div class="rs-health-detail">' + esc( c.detail ) + '</div></div>' +
					'</div>';
				} ).join('');

				function dataPanel( label, items, emptyMsg ) {
					var content = Array.isArray( items ) && items.length
						? items.map( function(i) { return '<span style="display:inline-block;background:#f0f0f1;border-radius:3px;padding:2px 8px;font-size:12px;margin:2px;">' + esc(i) + '</span>'; } ).join(' ')
						: '<span style="color:#d63638;font-size:12px;">' + esc( emptyMsg ) + '</span>';
					return '<div style="margin-bottom:12px;"><strong style="font-size:12px;color:#787c82;display:block;margin-bottom:5px;text-transform:uppercase;letter-spacing:.05em;">' + label + '</strong>' + content + '</div>';
				}

				var desc     = d.description || '';
				var descHtml = '<div style="margin-bottom:12px;"><strong style="font-size:12px;color:#787c82;display:block;margin-bottom:5px;text-transform:uppercase;letter-spacing:.05em;">Business Description</strong>' +
					( desc ? '<p style="font-size:13px;color:#1d2327;margin:0;line-height:1.5;">' + esc( desc ) + ' <span style="color:#787c82;font-size:11px;">(' + desc.length + ' chars)</span></p>'
					       : '<span style="color:#d63638;font-size:12px;">Not set — add a description to improve visibility</span>' ) +
					'</div>';

				var profileData =
					'<div style="margin-top:16px;padding-top:14px;border-top:1px solid #f0f0f1;">' +
					descHtml +
					dataPanel( 'Categories', d.all_categories || [], 'No categories set' ) +
					dataPanel( 'Services', d.services || [], 'No services listed' ) +
					dataPanel( 'Business Hours', d.hours || [], 'No hours set' ) +
					( d.phone ? '<div style="font-size:12px;color:#646970;margin-bottom:4px;">📞 ' + esc(d.phone) + '</div>' : '' ) +
					( d.website ? '<div style="font-size:12px;color:#646970;">🌐 <a href="' + esc(d.website) + '" target="_blank">' + esc(d.website) + '</a></div>' : '' ) +
					'</div>';

				$content.html(
					'<div style="display:flex;align-items:center;gap:14px;margin-bottom:14px;">' +
					'<div style="font-size:32px;font-weight:700;color:' + color + ';">' + pct + '%</div>' +
					'<div><strong style="font-size:14px;">' + esc( d.name ) + '</strong>' +
					'<div style="font-size:12px;color:#787c82;">' + esc( d.primary_category || '' ) + '</div></div>' +
					'<button type="button" id="rs-load-profile-health" class="button" style="margin-left:auto;">Refresh</button>' +
					'</div>' +
					'<div class="rs-health-grid">' + checksHtml + '</div>' +
					profileData
				);
			} else {
				$content.html( '<p style="color:#d63638;font-size:13px;">' + esc( r && r.data && r.data.message ? r.data.message : 'Failed to load profile health.' ) + '</p>' +
					'<button type="button" id="rs-load-profile-health" class="button">Retry</button>' );
			}
		}

		$( document ).on( 'click', '#rs-load-profile-health', function () {
			var $btn = $( this ).prop( 'disabled', true ).text( 'Checking…' );
			$( '#rs-profile-health-loading' ).show();
			$.post( ajax, { action: 'ratesight_get_profile_health', nonce: nonce } )
				.done( function(r) { renderProfileHealth(r); } )
				.fail( function() { renderProfileHealth(null); $btn.prop('disabled', false).text('Retry'); } );
		} );

		// Handle auto-load result from inline script
		$( document ).on( 'click-result', '#rs-load-profile-health', function(e, r) {
			renderProfileHealth(r);
		} );

		// ── Reviews ────────────────────────────────────────────────────────────

		$( document ).on( 'click', '#rs-load-reviews', function () {
			var $btn     = $( this ).prop( 'disabled', true ).text( 'Loading…' );
			var $loading = $( '#rs-reviews-loading' ).show();
			var $content = $( '#rs-reviews-content' );

			$.post( ajax, { action: 'ratesight_get_reviews', nonce: nonce } )
				.done( function ( r ) {
					if ( r.success ) {
						var d          = r.data;
						var reviews    = d.reviews || [];
						var unanswered = d.unanswered_count || 0;

						var statsHtml = '<div style="display:flex;gap:20px;margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid #f0f0f1;">' +
							'<div><span style="font-size:24px;font-weight:700;">' + parseFloat( d.avg_rating ).toFixed(1) + '</span>' +
							'<span style="color:#f5a623;font-size:18px;margin-left:4px;">★</span>' +
							'<div style="font-size:12px;color:#787c82;">' + d.total + ' reviews total</div></div>' +
							( unanswered > 0 ? '<div style="background:#fff8e1;border:1px solid #ffe57f;border-radius:4px;padding:8px 12px;font-size:13px;color:#7a5800;align-self:flex-start;">' +
							'⚠️ <strong>' + unanswered + '</strong> review' + ( unanswered !== 1 ? 's' : '' ) + ' need a reply</div>' : '' ) +
							'</div>';

						var reviewsHtml = reviews.slice( 0, 15 ).map( function ( rv ) {
							var stars     = '★'.repeat( rv.starRating === 'FIVE' ? 5 : rv.starRating === 'FOUR' ? 4 : rv.starRating === 'THREE' ? 3 : rv.starRating === 'TWO' ? 2 : 1 );
							var author    = rv.reviewer && rv.reviewer.displayName ? rv.reviewer.displayName : 'Anonymous';
							var body      = rv.comment || '(No comment)';
							var hasReply  = rv.reviewReply && rv.reviewReply.comment;
							var reviewName = rv.name || '';

							return '<div class="rs-review-card">' +
								'<div class="rs-review-meta">' +
									'<strong class="rs-review-author">' + esc( author ) + '</strong>' +
									'<span class="rs-review-stars">' + stars + '</span>' +
									( rv.updateTime ? '<span class="rs-review-date">' + esc( rv.updateTime.split('T')[0] ) + '</span>' : '' ) +
								'</div>' +
								'<div class="rs-review-body">' + esc( body ) + '</div>' +
								( hasReply ? '<div class="rs-reply-box"><strong>Your reply:</strong> ' + esc( rv.reviewReply.comment ) + '</div>' :
									'<div>' +
										'<button type="button" class="button rs-draft-reply" data-review-name="' + esc( reviewName ) + '" data-reviewer="' + esc( author ) + '" data-body="' + esc( body ) + '">Draft AI Reply</button>' +
										'<div class="rs-reply-area" id="rs-reply-' + esc( reviewName.replace(/\//g,'-') ) + '" style="display:none;">' +
											'<textarea class="rs-reply-draft" placeholder="AI reply draft will appear here…"></textarea>' +
											'<div style="display:flex;gap:8px;margin-top:6px;">' +
												'<button type="button" class="button button-primary rs-post-reply" data-review-name="' + esc( reviewName ) + '">Post Reply</button>' +
												'<button type="button" class="button rs-cancel-reply">Cancel</button>' +
											'</div>' +
										'</div>' +
									'</div>'
								) +
							'</div>';
						} ).join('');

						$content.html( statsHtml + reviewsHtml );
						$btn.prop( 'disabled', false ).text( 'Refresh' );
					} else {
						$content.html( '<p style="color:#d63638;">' + esc( r.data && r.data.message ? r.data.message : 'Failed to load reviews.' ) + '</p>' );
						$btn.prop( 'disabled', false ).text( 'Retry' );
					}
				} )
				.fail( function () {
					$content.html( '<p style="color:#d63638;">Request failed.</p>' );
					$btn.prop( 'disabled', false ).text( 'Retry' );
				} )
				.always( function () { $loading.hide(); } );
		} );

		// Draft AI reply for a review
		$( document ).on( 'click', '.rs-draft-reply', function () {
			var $btn      = $( this ).prop( 'disabled', true ).text( 'Drafting…' );
			var name      = $( this ).data( 'review-name' );
			var reviewer  = $( this ).data( 'reviewer' );
			var body      = $( this ).data( 'body' );
			var safeId    = name.replace( /\//g, '-' );
			var $area     = $( '#rs-reply-' + safeId );
			var $textarea = $area.find( '.rs-reply-draft' );

			var prompt = 'Write a professional, friendly reply to this Google review from ' + reviewer + ': "' + body + '". Keep it under 150 words.';

			$.post( ajax, {
				action: 'ratesight_ai_chat',
				nonce:   nonce,
				prompt:  prompt,
				context: 'local'
			} )
			.done( function ( r ) {
				if ( r.success ) {
					$textarea.val( r.data.reply );
					$area.show();
				} else {
					alert( r.data && r.data.message ? r.data.message : 'Could not generate reply.' );
				}
				$btn.prop( 'disabled', false ).text( 'Draft AI Reply' );
			} )
			.fail( function () {
				alert( 'Request failed.' );
				$btn.prop( 'disabled', false ).text( 'Draft AI Reply' );
			} );
		} );

		// Post reply
		$( document ).on( 'click', '.rs-post-reply', function () {
			var $btn        = $( this ).prop( 'disabled', true ).text( 'Posting…' );
			var reviewName  = $( this ).data( 'review-name' );
			var $area       = $( this ).closest( '.rs-reply-area' );
			var comment     = $area.find( '.rs-reply-draft' ).val();

			if ( ! comment.trim() ) { alert( 'Reply is empty.' ); $btn.prop( 'disabled', false ).text( 'Post Reply' ); return; }

			$.post( ajax, {
				action:      'ratesight_reply_review',
				nonce:        nonce,
				review_name:  reviewName,
				comment:      comment
			} )
			.done( function ( r ) {
				if ( r.success ) {
					$area.closest( '.rs-review-card' ).find( '.rs-reply-area' ).parent().replaceWith(
						'<div class="rs-reply-box"><strong>Your reply:</strong> ' + esc( comment ) + '</div>'
					);
				} else {
					alert( r.data && r.data.message ? r.data.message : 'Failed to post reply.' );
					$btn.prop( 'disabled', false ).text( 'Post Reply' );
				}
			} )
			.fail( function () {
				alert( 'Request failed.' );
				$btn.prop( 'disabled', false ).text( 'Post Reply' );
			} );
		} );

		// Cancel reply
		$( document ).on( 'click', '.rs-cancel-reply', function () {
			$( this ).closest( '.rs-reply-area' ).hide().find( 'textarea' ).val('');
			$( this ).closest( '.rs-review-card' ).find( '.rs-draft-reply' ).prop( 'disabled', false ).text( 'Draft AI Reply' );
		} );

		// ── AI Chat (both tabs) ────────────────────────────────────────────────

		function appendMessage( chatId, text, role ) {
			var $msgs = $( '#' + chatId + '-messages' );
			var cls   = role === 'user' ? 'rs-msg-user' : 'rs-msg-ai';
			$msgs.append( '<div class="rs-msg ' + cls + '">' + esc( text ).replace( /\n/g, '<br>' ) + '</div>' );
			$msgs.scrollTop( $msgs[0].scrollHeight );
		}

		function sendChat( chatId ) {
			var $wrap   = $( '#' + chatId );
			var context = $wrap.data( 'context' );
			var $input  = $( '#' + chatId + '-input' );
			var prompt  = $input.val().trim();
			if ( ! prompt ) return;

			$input.val('');
			$( '#' + chatId + '-prompts' ).hide();
			appendMessage( chatId, prompt, 'user' );

			var $thinking = $( '<div class="rs-msg rs-msg-ai" id="' + chatId + '-thinking">Thinking…</div>' );
			$( '#' + chatId + '-messages' ).append( $thinking );

			$.post( ajax, {
				action:  'ratesight_ai_chat',
				nonce:    nonce,
				prompt:   prompt,
				context:  context
			} )
			.done( function ( r ) {
				$thinking.remove();
				if ( r.success ) {
					appendMessage( chatId, r.data.reply, 'ai' );
				} else {
					appendMessage( chatId, '⚠ ' + ( r.data && r.data.message ? r.data.message : 'Something went wrong.' ), 'ai' );
				}
			} )
			.fail( function () {
				$thinking.remove();
				appendMessage( chatId, '⚠ Request failed.', 'ai' );
			} );
		}

		$( document ).on( 'click', '.rs-chat-send', function () {
			sendChat( $( this ).data( 'chat' ) );
		} );

		$( document ).on( 'keydown', '[id$="-input"]', function ( e ) {
			if ( e.key === 'Enter' && !e.shiftKey ) {
				e.preventDefault();
				var chatId = $( this ).attr( 'id' ).replace( '-input', '' );
				sendChat( chatId );
			}
		} );

		$( document ).on( 'click', '.rs-prompt-chip', function () {
			var chatId = $( this ).data( 'chat' );
			$( '#' + chatId + '-input' ).val( $( this ).data( 'prompt' ) );
			sendChat( chatId );
		} );
		$( document ).on( 'click', '#rs-sync-gsc-now, #rs-sync-gsc-now-alt', function ( e ) {
			e.preventDefault();
			var $btn = $( '#rs-sync-gsc-now' ).prop( 'disabled', true ).text( 'Syncing…' );
			var $fb  = $( '#rs-sync-feedback' ).show().text( 'Fetching data from GSC…' ).removeClass( 'rs-feedback-ok rs-feedback-err' );

			// Step 1 — pages + bulk keywords all happen server-side in one call.
			$.post( ajax, { action: 'ratesight_sync_gsc_now', nonce: nonce } )
				.done( function ( r ) {
					if ( ! r.success ) {
						$fb.addClass( 'rs-feedback-err' ).text( '✗ ' + ( r.data && r.data.message ? r.data.message : 'Sync failed.' ) );
						$btn.prop( 'disabled', false ).text( 'Sync Data' );
						return;
					}

					var posts = r.data.posts || [];
					var matchMsg = posts.length > 0
						? 'Finalising… (' + posts.length + ' pages matched)'
						: 'Finalising… (GSC data pulled, no RS pages matched yet)';
					$fb.text( matchMsg );

					// Step 2 — finalise: update last_sync, prune old data, clear transients.
					$.post( ajax, { action: 'ratesight_sync_gsc_finalise', nonce: nonce } )
						.done( function ( fr ) {
							$btn.prop( 'disabled', false ).text( 'Sync Data' );
							if ( fr.success ) {
								$fb.addClass( 'rs-feedback-ok' ).text( '✓ Sync complete — reloading…' );
								window.location.reload();
							} else {
								$fb.addClass( 'rs-feedback-err' ).text( '✗ Finalise failed — please refresh.' );
							}
						} )
						.fail( function () {
							$btn.prop( 'disabled', false ).text( 'Sync Data' );
							$fb.addClass( 'rs-feedback-err' ).text( '✗ Request failed — please refresh.' );
						} );
				} )
				.fail( function () {
					$fb.addClass( 'rs-feedback-err' ).text( '✗ Request failed.' );
					$btn.prop( 'disabled', false ).text( 'Sync Data' );
				} );
		} );

		// ── AI Insights ───────────────────────────────────────────────────────

		function loadInsights( force ) {
			$( '#rs-insights-loading' ).show();
			$( '#rs-insights-cards, #rs-insights-error' ).hide();
			$( '#rs-generate-insights' ).prop( 'disabled', true );

			$.post( ajax, { action: 'ratesight_get_insights', nonce: nonce, force: force ? 1 : 0 } )
				.done( function ( r ) {
					if ( r.success ) {
						var wins = r.data.wins || [];
						var opps = r.data.opportunities || [];
						var genAt = r.data.generated_at || '';

						var postMap = ( r.data.posts || [] ).slice().sort( function ( a, b ) {
							return ( b.post_title || '' ).length - ( a.post_title || '' ).length;
						} );
						function linkify( text ) {
							for ( var i = 0; i < postMap.length; i++ ) {
								var p = postMap[ i ];
								if ( ! p.post_title ) continue;
								var idx = text.indexOf( p.post_title );
								if ( idx !== -1 ) {
									var before  = esc( text.substring( 0, idx ) );
									var after   = esc( text.substring( idx + p.post_title.length ) );
									var editUrl = '/wp-admin/post.php?post=' + encodeURIComponent( p.post_id ) + '&action=edit';
									return before + '<a href="' + editUrl + '" target="_blank" style="color:inherit;text-decoration:underline;text-underline-offset:2px;">' + esc( p.post_title ) + '</a>' + after;
								}
							}
							return esc( text );
						}

						function renderInsightRows( items, checkColor, arrowColor, borderColor ) {
							return items.map( function(t) {
								return '<div style="display:flex;gap:8px;align-items:flex-start;padding:7px 0;border-bottom:1px solid ' + borderColor + ';">' +
									'<span style="color:' + arrowColor + ';font-size:14px;line-height:1;margin-top:2px;flex-shrink:0;">' + checkColor + '</span>' +
									'<span style="font-size:13px;line-height:1.5;">' + linkify( t ) + '</span>' +
									'</div>';
							} ).join('');
						}

						var winsHtml = '';
						if ( wins.length ) {
							winsHtml = '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px 16px;">' +
								'<div style="display:flex;align-items:center;gap:7px;margin-bottom:10px;">' +
								'<span style="font-size:15px;">🏆</span>' +
								'<span style="font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#15803d;">Wins</span></div>' +
								'<div style="color:#14532d;">' + renderInsightRows( wins, '✓', '#16a34a', '#dcfce7' ) + '</div>' +
								'</div>';
						}

						var oppsHtml = '';
						if ( opps.length ) {
							oppsHtml = '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:14px 16px;">' +
								'<div style="display:flex;align-items:center;gap:7px;margin-bottom:10px;">' +
								'<span style="font-size:15px;">🎯</span>' +
								'<span style="font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#b45309;">Opportunities</span></div>' +
								'<div style="color:#78350f;">' + renderInsightRows( opps, '→', '#d97706', '#fef3c7' ) + '</div>' +
								'</div>';
						}

						var html = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:10px;">' + winsHtml + oppsHtml + '</div>' +
							'<p style="font-size:11px;color:#787c82;margin:0 0 16px;">AI insights generated ' + esc( genAt ) + ' &middot; <a href="#" id="rs-refresh-insights">Refresh</a></p>';

						$( '#rs-insights-cards' ).html( html ).show();
					} else {
						var msg = r.data && r.data.message ? r.data.message : 'Could not generate insights.';
						$( '#rs-insights-error' ).html(
							'<div class="notice notice-warning inline"><p>' + esc( msg ) + '</p></div>'
						).show();
						$( '#rs-generate-insights' ).prop( 'disabled', false );
					}
				} )
				.fail( function () {
					$( '#rs-insights-error' ).html(
						'<div class="notice notice-error inline"><p>Request failed — check your connection.</p></div>'
					).show();
					$( '#rs-generate-insights' ).prop( 'disabled', false );
				} )
				.always( function () {
					$( '#rs-insights-loading' ).hide();
				} );
		}

		$( document ).on( 'click', '#rs-generate-insights', function ( e ) {
			e.preventDefault();
			loadInsights( false );
		} );

		$( document ).on( 'click', '#rs-refresh-insights', function ( e ) {
			e.preventDefault();
			loadInsights( true );
		} );

		// ── Attention pages (zero impressions) ───────────────────────────────
		if ( $( '#rs-attention-wrap' ).length ) {
			$.post( ajax, { action: 'ratesight_get_attention_pages', nonce: nonce } )
				.done( function ( r ) {
					if ( ! r.success ) return;
					var pages = r.data.pages || [];
					if ( ! pages.length ) return;

					var label = pages.length === 1
						? '⚠ 1 page needs attention'
						: '⚠ ' + pages.length + ' pages need attention';
					$( '#rs-attention-label' ).text( label );
					$( '#rs-attention-toggle' ).show();

					var rows = pages.map( function ( p ) {
						var badge = p.type === 'never'
							? '<span style="font-size:10px;background:#fef9c3;color:#854d0e;padding:2px 6px;border-radius:10px;margin-left:6px;">never indexed</span>'
							: '<span style="font-size:10px;background:#fef2f2;color:#991b1b;padding:2px 6px;border-radius:10px;margin-left:6px;">dropped off</span>';
						return '<div style="display:flex;align-items:center;gap:10px;padding:8px 14px;border-bottom:1px solid #f0f0f1;">' +
							'<span style="flex:1;font-size:12px;color:#1d2327;">' + esc( p.title ) + badge + '</span>' +
							'<a href="' + esc( p.edit_url ) + '" target="_blank" class="button button-small" style="font-size:11px;white-space:nowrap;">Edit page</a>' +
							'</div>';
					} ).join('');

					$( '#rs-attention-list' ).html( rows );
				} );

			$( document ).on( 'click', '#rs-attention-toggle', function ( e ) {
				e.preventDefault();
				$( '#rs-attention-panel' ).slideToggle( 150 );
			} );

		}


	} );

} )( jQuery );
