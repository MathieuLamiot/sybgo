/**
 * Sybgo Admin JavaScript
 *
 * @package Rocket\Sybgo
 * @since 1.0.0
 */

(function($) {
	'use strict';

	/**
	 * Dashboard Widget functionality
	 */
	const SybgoDashboard = {
		init: function() {
			this.bindEvents();
		},

		bindEvents: function() {
			// Filter buttons
			$(document).on('click', '.sybgo-filters .sybgo-filter-btn', this.handleFilterClick);

			// Preview button (this week)
			$(document).on('click', '.sybgo-preview-btn', this.handlePreviewClick);

			// View Previous Digest button
			$(document).on('click', '.sybgo-preview-last-btn', this.handlePreviewLastClick);

			// AI Summary button
			$(document).on('click', '.sybgo-widget-ai-btn', this.handleAISummaryClick);

			// Modal close
			$(document).on('click', '.sybgo-modal-close, .sybgo-modal-overlay', this.handleModalClose);
		},

		handleFilterClick: function(e) {
			e.preventDefault();
			const $button = $(this);
			const filter = $button.data('filter');

			// Update active state
			$button.siblings().removeClass('active');
			$button.addClass('active');

			// Make AJAX call to filter events
			SybgoDashboard.filterEvents(filter);
		},

		filterEvents: function(filter) {
			const $eventsList = $('.sybgo-events-list');

			// Add loading state
			$eventsList.addClass('sybgo-loading');

			$.ajax({
				url: sybgoWidget.ajaxUrl,
				type: 'POST',
				data: {
					action: 'sybgo_filter_events',
					nonce: sybgoWidget.nonce,
					filter: filter
				},
				success: function(response) {
					if (response.success) {
						$eventsList.html(response.data.html);
						$('.sybgo-event-stats strong').text(response.data.count);
					}
				},
				complete: function() {
					$eventsList.removeClass('sybgo-loading');
				}
			});
		},

		handlePreviewClick: function(e) {
			e.preventDefault();

			$.ajax({
				url: sybgoWidget.ajaxUrl,
				type: 'POST',
				data: {
					action: 'sybgo_preview_digest',
					nonce: sybgoWidget.nonce
				},
				success: function(response) {
					if (response.success) {
						SybgoDashboard.showModal(response.data.html);
					}
				}
			});
		},

		handlePreviewLastClick: function(e) {
			e.preventDefault();

			$.ajax({
				url: sybgoWidget.ajaxUrl,
				type: 'POST',
				data: {
					action: 'sybgo_preview_last_digest',
					nonce: sybgoWidget.nonce
				},
				success: function(response) {
					if (response.success) {
						SybgoDashboard.showModal(response.data.html);
					} else {
						// eslint-disable-next-line no-alert
						alert(response.data && response.data.message ? response.data.message : 'No previous digest available.');
					}
				}
			});
		},

		handleAISummaryClick: function(e) {
			e.preventDefault();
			var $btn = $(this);
			$btn.prop('disabled', true).text('Generating…');
			var $result = $('#sybgo-widget-ai-summary');

			$.ajax({
				url: sybgoWidget.ajaxUrl,
				type: 'POST',
				data: {
					action: 'sybgo_widget_ai_summary',
					nonce: sybgoWidget.nonce
				},
				success: function(response) {
					if (response.success) {
						$result.text(response.data.summary).show();
						$btn.text('Regenerate AI Summary');
					} else {
						// eslint-disable-next-line no-alert
						alert(response.data && response.data.message ? response.data.message : 'Could not generate summary. Please try again.');
					}
				},
				complete: function() {
					$btn.prop('disabled', false);
				}
			});
		},

		showModal: function(content) {
			const $modal = $('<div class="sybgo-modal-overlay active">' +
				'<div class="sybgo-modal-content">' +
				'<span class="sybgo-modal-close">&times;</span>' +
				content +
				'</div>' +
				'</div>');

			$('body').append($modal);
		},

		handleModalClose: function(e) {
			if ($(e.target).hasClass('sybgo-modal-overlay') || $(e.target).hasClass('sybgo-modal-close')) {
				$('.sybgo-modal-overlay').remove();
			}
		}
	};

	/**
	 * Reports Page functionality
	 */
	const SybgoReports = {
		init: function() {
			this.bindEvents();
		},

		bindEvents: function() {
			// Manual freeze button
			$(document).on('click', '.sybgo-manual-freeze', this.handleManualFreeze);

			// Resend email button
			$(document).on('click', '.sybgo-resend-email', this.handleResendEmail);

			// Generate / Regenerate AI summary button
			$(document).on('click', '.sybgo-generate-ai-btn', this.handleGenerateAISummary);
		},

		handleGenerateAISummary: function(e) {
			e.preventDefault();
			var $btn = $(this);
			var reportId = $btn.data('report-id');
			$btn.prop('disabled', true).text('Generating\u2026');

			$.ajax({
				url: sybgoAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'sybgo_generate_ai_summary',
					nonce: sybgoAdmin.nonce,
					report_id: reportId
				},
				success: function(response) {
					if (response.success) {
						$('#sybgo-ai-summary-text').text(response.data.summary);
						$('#sybgo-ai-summary-box').show();
						$btn.text('Regenerate AI Summary');
					} else {
						// eslint-disable-next-line no-alert
						alert(response.data && response.data.message ? response.data.message : 'Could not generate summary. Please try again.');
					}
				},
				complete: function() {
					$btn.prop('disabled', false);
				}
			});
		},

		handleManualFreeze: function(e) {
			e.preventDefault();

			if (!confirm('Are you sure you want to freeze the current report and send it now? This will end the current weekly period early.')) {
				return;
			}

			// Make AJAX call to freeze report
			$.ajax({
				url: sybgoAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'sybgo_manual_freeze',
					nonce: sybgoAdmin.nonce
				},
				success: function(response) {
					if (response.success) {
						location.reload();
					} else {
						alert('Error: ' + response.data.message);
					}
				}
			});
		},

		handleResendEmail: function(e) {
			e.preventDefault();
			const $button = $(this);
			const reportId = $button.data('report-id');

			// Make AJAX call to resend email
			$.ajax({
				url: sybgoAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'sybgo_resend_email',
					nonce: sybgoAdmin.nonce,
					report_id: reportId
				},
				success: function(response) {
					if (response.success) {
						alert('Email sent successfully!');
					} else {
						alert('Error: ' + response.data.message);
					}
				}
			});
		}
	};

	/**
	 * Initialize on document ready
	 */
	$(document).ready(function() {
		SybgoDashboard.init();
		SybgoReports.init();
	});

})(jQuery);
