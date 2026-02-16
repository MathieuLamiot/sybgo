/**
 * Sybgo Dashboard Widget JavaScript
 *
 * @package Rocket\Sybgo
 */

(function($) {
	'use strict';

	/**
	 * Dashboard Widget functionality
	 */
	var SybgoWidget = {
		/**
		 * Initialize the widget
		 */
		init: function() {
			this.bindEvents();
		},

		/**
		 * Bind event handlers
		 */
		bindEvents: function() {
			// Filter buttons
			$('.sybgo-filter-btn').on('click', this.handleFilterClick.bind(this));

			// Preview button
			$('.sybgo-preview-btn').on('click', this.handlePreviewClick.bind(this));

			// Modal close
			$('.sybgo-modal-close, .sybgo-modal').on('click', this.handleModalClose.bind(this));

			// Prevent modal content clicks from closing
			$('.sybgo-modal-content').on('click', function(e) {
				e.stopPropagation();
			});
		},

		/**
		 * Handle filter button click
		 */
		handleFilterClick: function(e) {
			e.preventDefault();

			var $btn = $(e.currentTarget);
			var filter = $btn.data('filter');

			// Update active state
			$('.sybgo-filter-btn').removeClass('active');
			$btn.addClass('active');

			// Show loading
			$('.sybgo-events-list').addClass('loading');

			// AJAX request
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
						$('.sybgo-events-list').html(response.data.html);
						$('.sybgo-event-stats strong').text(response.data.count);
					}
				},
				error: function() {
					alert('Failed to filter events. Please try again.');
				},
				complete: function() {
					$('.sybgo-events-list').removeClass('loading');
				}
			});
		},

		/**
		 * Handle preview button click
		 */
		handlePreviewClick: function(e) {
			e.preventDefault();

			var $modal = $('#sybgo-preview-modal');
			var $modalBody = $modal.find('.sybgo-modal-body');

			// Show loading
			$modalBody.html('<p class="loading">Loading preview...</p>');
			$modal.fadeIn(200);

			// AJAX request
			$.ajax({
				url: sybgoWidget.ajaxUrl,
				type: 'POST',
				data: {
					action: 'sybgo_preview_digest',
					nonce: sybgoWidget.nonce
				},
				success: function(response) {
					if (response.success) {
						$modalBody.html(response.data.html);
					} else {
						var errorHtml = '<p class="error">Failed to generate preview.</p>';
						if (response.data) {
							errorHtml += '<pre style="background: #f0f0f0; padding: 10px; overflow: auto; font-size: 11px;">';
							errorHtml += 'Message: ' + (response.data.message || 'Unknown error') + '\n';
							if (response.data.file) {
								errorHtml += 'File: ' + response.data.file + ':' + response.data.line + '\n';
							}
							if (response.data.trace) {
								errorHtml += '\nStack trace:\n' + response.data.trace;
							}
							errorHtml += '</pre>';
						}
						$modalBody.html(errorHtml);
					}
				},
				error: function(xhr, status, error) {
					var errorHtml = '<p class="error">An error occurred. Please try again.</p>';
					errorHtml += '<pre style="background: #f0f0f0; padding: 10px; overflow: auto; font-size: 11px;">';
					errorHtml += 'Status: ' + status + '\n';
					errorHtml += 'Error: ' + error + '\n';
					errorHtml += 'Response: ' + xhr.responseText;
					errorHtml += '</pre>';
					$modalBody.html(errorHtml);
				}
			});
		},

		/**
		 * Handle modal close
		 */
		handleModalClose: function(e) {
			if ($(e.target).hasClass('sybgo-modal') || $(e.target).hasClass('sybgo-modal-close')) {
				$('#sybgo-preview-modal').fadeOut(200);
			}
		}
	};

	// Initialize on document ready
	$(document).ready(function() {
		SybgoWidget.init();
	});

})(jQuery);
