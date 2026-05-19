/**
 * MCP Helper — Connect to AI Tools
 *
 * Watches for new Application Passwords, injects a "Connect to AI" button,
 * and drives the modal (tool selection → JSON generation → clipboard copy).
 *
 * All data comes from the `mcpHelper` object localised by MCP_Helper::enqueue_assets().
 *
 * @package WPMedia\MCPHelper
 * @since   1.0.0
 */

(function ($) {
	'use strict';

	/** Currently captured application password (set when modal opens). */
	var currentPassword = '';

	/** Currently selected tool id. */
	var currentTool = 'claude-desktop';

	// -------------------------------------------------------------------------
	// MutationObserver — watch for new application password rows
	// -------------------------------------------------------------------------

	function watchForNewPasswords() {
		var section = document.getElementById('application-passwords-section');
		if (!section) {
			return;
		}

		var observer = new MutationObserver(function (mutations) {
			mutations.forEach(function (mutation) {
				mutation.addedNodes.forEach(function (node) {
					if (node.nodeType !== Node.ELEMENT_NODE) {
						return;
					}

					// WordPress 5.6+ renders a .new-application-password-notice element
					// when a password is successfully created via the REST API.
					var noticeEl = node.classList && node.classList.contains('new-application-password-notice')
						? node
						: node.querySelector && node.querySelector('.new-application-password-notice');

					if (noticeEl) {
						injectConnectButton(noticeEl);
					}
				});
			});
		});

		observer.observe(section, { childList: true, subtree: true });
	}

	/**
	 * Inject the "Connect to AI" button inside the new-application-password-notice element.
	 *
	 * @param {Element} noticeEl  The .new-application-password-notice div WordPress renders.
	 */
	function injectConnectButton(noticeEl) {
		if (noticeEl.querySelector('.mcp-helper-connect-btn')) {
			return; // already injected
		}

		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'button button-secondary mcp-helper-connect-btn';
		btn.textContent = mcpHelper.i18n.connectBtn;

		btn.addEventListener('click', function () {
			// WordPress places the plain-text password in input.code (or input#new-application-password-value).
			var codeEl = noticeEl.querySelector('input.code, #new-application-password-value');
			currentPassword = codeEl ? codeEl.value.trim() : '';
			openModal();
		});

		// Place the button after the existing "Copy" button inside .application-password-display.
		var displayEl = noticeEl.querySelector('.application-password-display');
		if (displayEl) {
			displayEl.appendChild(btn);
		} else {
			noticeEl.appendChild(btn);
		}
	}

	// -------------------------------------------------------------------------
	// Modal open / close
	// -------------------------------------------------------------------------

	function openModal() {
		currentTool = 'claude-desktop';
		refreshTabs();
		refreshJson();
		$('#mcp-helper-modal').fadeIn(200);
	}

	function closeModal() {
		$('#mcp-helper-modal').fadeOut(200);
	}

	// -------------------------------------------------------------------------
	// Tab switching
	// -------------------------------------------------------------------------

	function refreshTabs() {
		$('.mcp-helper-tab-btn').each(function () {
			var active = $(this).data('tool') === currentTool;
			$(this)
				.toggleClass('active', active)
				.attr('aria-selected', active ? 'true' : 'false');
		});
	}

	// -------------------------------------------------------------------------
	// JSON generation (fully client-side)
	// -------------------------------------------------------------------------

	function buildJson(toolId, password) {
		var siteUrl = mcpHelper.siteUrl.replace(/\/$/, '');
		var wpApiUrl = siteUrl + '/wp-json/mcp/mcp-adapter-default-server';

		var serverEntry = {
			type: 'stdio',
			command: 'npx',
			args: ['-y', '@automattic/mcp-wordpress-remote@latest'],
			env: {
				WP_API_URL: wpApiUrl,
				WP_API_USERNAME: mcpHelper.username,
				WP_API_PASSWORD: password,
				OAUTH_ENABLED: 'false',
			},
			description: 'WordPress MCP connection',
		};

		if (toolId === 'claude-desktop') {
			return { mcpServers: { 'my-plugin': serverEntry } };
		}

		if (toolId === 'github-copilot') {
			return {
				'github.copilot.chat.mcp.enabled': true,
				'github.copilot.chat.mcp.servers': { 'my-plugin': serverEntry },
			};
		}

		return {};
	}

	function refreshJson() {
		var config = buildJson(currentTool, currentPassword);
		$('#mcp-helper-json-output').text(JSON.stringify(config, null, 2));
		refreshInstructions();
	}

	// -------------------------------------------------------------------------
	// Instructions panel
	// -------------------------------------------------------------------------

	function refreshInstructions() {
		var tool = mcpHelper.tools[currentTool];
		if (!tool) {
			return;
		}

		var html = '';

		// Config file paths
		if (tool.config_paths && Object.keys(tool.config_paths).length) {
			html += '<p><strong>' + escHtml(mcpHelper.i18n.configPaths) + '</strong></p><ul>';
			$.each(tool.config_paths, function (os, path) {
				html += '<li><code>' + escHtml(path) + '</code></li>';
			});
			html += '</ul>';
		}

		// Step-by-step instructions
		if (tool.instructions && tool.instructions.length) {
			html += '<p><strong>' + escHtml(mcpHelper.i18n.instructions) + '</strong></p><ol>';
			$.each(tool.instructions, function (i, step) {
				html += '<li>' + escHtml(step) + '</li>';
			});
			html += '</ol>';
		}

		$('#mcp-helper-instructions').html(html);

		// Update filename label in the code header
		var firstPath = tool.config_paths ? Object.values(tool.config_paths)[0] : '';
		$('.mcp-helper-filename-label').text(firstPath || '');
	}

	// -------------------------------------------------------------------------
	// Copy to clipboard
	// -------------------------------------------------------------------------

	function copyJson() {
		var text = $('#mcp-helper-json-output').text();
		var $btn = $('#mcp-helper-copy-btn');

		function showCopied() {
			$btn.text(mcpHelper.i18n.copied);
			setTimeout(function () {
				$btn.text(mcpHelper.i18n.copy);
			}, 2000);
		}

		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(showCopied);
		} else {
			// Legacy fallback.
			var ta = document.createElement('textarea');
			ta.value = text;
			ta.style.position = 'fixed';
			ta.style.opacity = '0';
			document.body.appendChild(ta);
			ta.focus();
			ta.select();
			try {
				document.execCommand('copy');
				showCopied();
			} finally {
				document.body.removeChild(ta);
			}
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	function escHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	// -------------------------------------------------------------------------
	// Event bindings
	// -------------------------------------------------------------------------

	$(document).ready(function () {
		watchForNewPasswords();

		// Tab click
		$(document).on('click', '.mcp-helper-tab-btn', function () {
			currentTool = $(this).data('tool');
			refreshTabs();
			refreshJson();
		});

		// Copy button
		$(document).on('click', '#mcp-helper-copy-btn', copyJson);

		// Close on × button
		$(document).on('click', '.mcp-helper-modal-close', closeModal);

		// Close on backdrop click
		$(document).on('click', '#mcp-helper-modal', function (e) {
			if ($(e.target).is('#mcp-helper-modal')) {
				closeModal();
			}
		});

		// Close on Escape key
		$(document).on('keydown', function (e) {
			if (e.key === 'Escape' && $('#mcp-helper-modal').is(':visible')) {
				closeModal();
			}
		});
	});
}(jQuery));
