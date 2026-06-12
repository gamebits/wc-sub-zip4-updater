( function ( $ ) {
	'use strict';

	var $form = $( '#wc-zip4-subscriptions-form' );
	var $button = $( '#wc-zip4-process-subscriptions' );
	var $progress = $( '#wc-zip4-progress' );
	var $progressText = $( '#wc-zip4-progress-text' );
	var $progressStatus = $( '#wc-zip4-progress-status' );
	var $progressLog = $( '#wc-zip4-progress-log' );
	var $wrap = $( '.woocommerce-zip4-admin' );

	function getSelectedStatuses() {
		var statuses = {};

		$form.find( 'input[name^="wc_zip4_statuses"]' ).each( function () {
			var name = this.name.match( /\[([^\]]+)\]/ );
			if ( name ) {
				statuses[ name[1] ] = this.checked ? 1 : 0;
			}
		} );

		return statuses;
	}

	function appendLog( message ) {
		$progressLog.append( $( '<li></li>' ).text( message ) );
		$progressLog.scrollTop( $progressLog[0].scrollHeight );
	}

	function wait( seconds ) {
		return new Promise( function ( resolve ) {
			window.setTimeout( resolve, seconds * 1000 );
		} );
	}

	function prepareBatch() {
		return $.post( wcZip4Admin.ajaxUrl, {
			action: 'wc_zip4_prepare_subscriptions',
			nonce: wcZip4Admin.nonce,
			statuses: getSelectedStatuses(),
		} );
	}

	function processSubscription( subscriptionId, current, total ) {
		return $.post( wcZip4Admin.ajaxUrl, {
			action: 'wc_zip4_process_subscription',
			nonce: wcZip4Admin.nonce,
			subscription_id: subscriptionId,
			current: current,
			total: total,
		} );
	}

	async function runBatch() {
		$wrap.addClass( 'is-processing' );
		$progress.removeAttr( 'hidden' );
		$progressLog.empty();
		$progressStatus.text( wcZip4Admin.i18n.processing + '…' );

		try {
			var prepareResponse = await prepareBatch();

			if ( ! prepareResponse.success ) {
				throw new Error(
					prepareResponse.data && prepareResponse.data.message
						? prepareResponse.data.message
						: wcZip4Admin.i18n.error
				);
			}

			var ids = prepareResponse.data.ids || [];
			var total = prepareResponse.data.total || 0;

			if ( ! total ) {
				$progressText.text( '0/0' );
				$progressStatus.text( wcZip4Admin.i18n.none );
				return;
			}

			for ( var index = 0; index < ids.length; index += 1 ) {
				var current = index + 1;

				if ( index > 0 ) {
					$progressStatus.text( wcZip4Admin.i18n.waiting );
					await wait( wcZip4Admin.rateLimit );
				}

				$progressStatus.text( wcZip4Admin.i18n.processing + '…' );
				$progressText.text( current + '/' + total );

				var processResponse = await processSubscription(
					ids[ index ],
					current,
					total
				);

				if ( ! processResponse.success ) {
					throw new Error(
						processResponse.data && processResponse.data.message
							? processResponse.data.message
							: wcZip4Admin.i18n.error
					);
				}

				if ( processResponse.data.message ) {
					appendLog( processResponse.data.message );
				}
			}

			$progressStatus.text( wcZip4Admin.i18n.complete );
		} catch ( error ) {
			$progressStatus.text(
				error && error.message ? error.message : wcZip4Admin.i18n.error
			);
		} finally {
			$wrap.removeClass( 'is-processing' );
		}
	}

	$button.on( 'click', function () {
		runBatch();
	} );
}( jQuery ) );
