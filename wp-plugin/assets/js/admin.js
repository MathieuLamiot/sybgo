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
			$(document).on('click', '.sybgo-filter-buttons button', this.handleFilterClick);

			// Preview button
			$(document).on('click', '.sybgo-preview-digest', this.handlePreviewClick);

			// AI Summary button
			$(document).on('click', '.sybgo-ai-summary', this.handleAISummaryClick);

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
			const $widget = $('#sybgo_activity_digest');
			const $eventsList = $widget.find('.recent-events');

			// Add loading state
			$eventsList.addClass('sybgo-loading');

			$.ajax({
				url: sybgoAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'sybgo_filter_events',
					nonce: sybgoAdmin.nonce,
					filter: filter
				},
				success: function(response) {
					if (response.success) {
						$eventsList.html(response.data.html);
					}
				},
				complete: function() {
					$eventsList.removeClass('sybgo-loading');
				}
			});
		},

		handlePreviewClick: function(e) {
			e.preventDefault();

			// Make AJAX call to generate preview
			$.ajax({
				url: sybgoAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'sybgo_preview_digest',
					nonce: sybgoAdmin.nonce
				},
				success: function(response) {
					if (response.success) {
						SybgoDashboard.showModal(response.data.html);
					}
				}
			});
		},

		handleAISummaryClick: function(e) {
			e.preventDefault();

			// Placeholder for AI integration
			alert('AI integration coming soon! This will use OpenAI/Claude API to generate intelligent summaries of your activity.');
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
