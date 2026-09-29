/**
 * TargetWeb CRM — Request Information modal.
 *
 * Owns the full open/close + form + submit flow. Does NOT load targetWeb.js
 * and does NOT call displayQuotationForm() — this plugin renders and submits
 * its own form, which maps to the documented TargetWeb API:
 *   1. GetDmsSetupId          (resolved server-side, lazily on first open)
 *   2. GetDmsSetupLocations   (resolved server-side, lazily on first open)
 *   3. AddCustomerQuotation   (on submit)
 *
 * Auto-binds to #tw-request-info-btn (or [data-tw-crm-open]). Modal source
 * comes from settings:
 *   - plugin: always build #tw-crm-app-popup (never reuse theme markup)
 *   - theme:  inject the form into the theme's #tw-crm-popup / #leads-form-div
 */
(function () {
	'use strict';

	var cfg = window.twCrmConfig || {};

	// Soft-fail: never throw if config/DOM isn't what we expect.
	if (!cfg || typeof document === 'undefined') {
		return;
	}

	var useInline = cfg.modalSource === 'inline';
	var useThemeModal = cfg.modalSource === 'theme';
	var POPUP_ID = cfg.themeModalId || 'tw-crm-popup';
	var PLUGIN_POPUP_ID = cfg.pluginModalId || 'tw-crm-app-popup';
	var FORM_MOUNT_ID = cfg.formMountId || 'leads-form-div';
	var TRIGGER_ID = cfg.triggerId || 'tw-request-info-btn';

	function pageHasTrigger() {
		return !!(document.getElementById(TRIGGER_ID) || document.querySelector('[data-tw-crm-open]'));
	}

	function detectDisplayMode() {
		var popup = document.getElementById(POPUP_ID);
		var mount = document.getElementById(FORM_MOUNT_ID);
		var markedInline =
			(popup && popup.hasAttribute('data-tw-crm-inline')) ||
			(mount && mount.hasAttribute('data-tw-crm-inline'));
		// Theme markup wins: a product page can host a simple form even if
		// settings still say "plugin modal".
		if (markedInline || cfg.modalSource === 'inline' || (!pageHasTrigger() && (popup || mount))) {
			return 'inline';
		}
		// Theme popup + trigger: the theme owns open/close and design.
		if (popup && pageHasTrigger()) {
			return 'theme';
		}
		if (cfg.modalSource === 'plugin') {
			return 'plugin';
		}
		return 'theme';
	}

	var popupEl = null;
	var panelEl = null;
	var mountEl = null;
	var formEl = null;
	var feedbackEl = null;
	var submitBtn = null;
	var locationFieldEl = null;
	var lastFocusedEl = null;
	var initialized = false;

	// Lazy-loaded once per page view; null = not yet fetched.
	var formDataState = null; // 'loading' | 'ready' | 'error'
	var locations = [];
	var currentProductId = 0;

	function t(key, fallback) {
		return (cfg.i18n && cfg.i18n[key]) || fallback;
	}

	function ensurePopupShell() {
		if (useInline) {
			mountEl = document.getElementById(FORM_MOUNT_ID);
			popupEl = document.getElementById(POPUP_ID);
			if (!mountEl && popupEl) {
				mountEl = document.createElement('div');
				mountEl.id = FORM_MOUNT_ID;
				popupEl.appendChild(mountEl);
			}
			if (!mountEl) {
				return false;
			}
			panelEl = popupEl || mountEl;
			return true;
		}

		popupEl = document.getElementById(POPUP_ID);

		if (useThemeModal) {
			if (!popupEl) {
				return false;
			}
			panelEl = popupEl.querySelector('.tw-crm-popup__panel') || popupEl;
			mountEl = document.getElementById(FORM_MOUNT_ID);
			if (!mountEl) {
				mountEl = document.createElement('div');
				mountEl.id = FORM_MOUNT_ID;
				panelEl.appendChild(mountEl);
			}
			return true;
		}

		// Plugin modal: always use our own shell. Never reuse a theme #tw-crm-popup.
		popupEl = document.getElementById(PLUGIN_POPUP_ID);
		if (!popupEl) {
			popupEl = document.createElement('div');
			popupEl.id = PLUGIN_POPUP_ID;
			popupEl.className = 'tw-crm-popup';
			popupEl.setAttribute('aria-hidden', 'true');
			popupEl.setAttribute('role', 'dialog');
			popupEl.setAttribute('aria-modal', 'true');
			popupEl.innerHTML =
				'<div class="tw-crm-popup__overlay" data-tw-crm-close></div>' +
				'<div class="tw-crm-popup__panel" role="document">' +
				'<button type="button" class="tw-crm-popup__close" data-tw-crm-close aria-label="' +
				(t('close', 'Close')) +
				'"><span aria-hidden="true">&times;</span></button>' +
				'<div id="tw-crm-app-form"></div>' +
				'</div>';
			document.body.appendChild(popupEl);
		}

		panelEl = popupEl.querySelector('.tw-crm-popup__panel') || popupEl;
		mountEl = document.getElementById('tw-crm-app-form');

		if (!mountEl) {
			mountEl = document.createElement('div');
			mountEl.id = 'tw-crm-app-form';
			panelEl.appendChild(mountEl);
		}

		if (!popupEl.querySelector('[data-tw-crm-close]')) {
			var closeBtn = document.createElement('button');
			closeBtn.type = 'button';
			closeBtn.className = 'tw-crm-popup__close';
			closeBtn.setAttribute('data-tw-crm-close', '');
			closeBtn.setAttribute('aria-label', t('close', 'Close'));
			closeBtn.innerHTML = '<span aria-hidden="true">&times;</span>';
			panelEl.insertBefore(closeBtn, panelEl.firstChild);
		}

		return true;
	}

	function escapeHtml(str) {
		var div = document.createElement('div');
		div.textContent = String(str == null ? '' : str);
		return div.innerHTML;
	}

	function renderForm() {
		if (!mountEl) {
			return;
		}

		mountEl.innerHTML =
			'<h2 class="tw-crm-form__title">' + escapeHtml(cfg.modalTitle || 'Request Information') + '</h2>' +
			'<form id="tw-crm-lead-form" class="tw-crm-form" novalidate>' +
			'<div class="tw-crm-form__row">' +
			'<div class="tw-crm-form__field">' +
			'<label for="tw-crm-first-name">' + escapeHtml(t('firstName', 'First Name')) + '<span class="tw-crm-form__req">*</span></label>' +
			'<input type="text" id="tw-crm-first-name" name="first_name" required autocomplete="given-name">' +
			'</div>' +
			'<div class="tw-crm-form__field">' +
			'<label for="tw-crm-last-name">' + escapeHtml(t('lastName', 'Last Name')) + '</label>' +
			'<input type="text" id="tw-crm-last-name" name="last_name" autocomplete="family-name">' +
			'</div>' +
			'</div>' +
			'<div class="tw-crm-form__row">' +
			'<div class="tw-crm-form__field">' +
			'<label for="tw-crm-email">' + escapeHtml(t('email', 'Email')) + '<span class="tw-crm-form__req">*</span></label>' +
			'<input type="email" id="tw-crm-email" name="email" required autocomplete="email">' +
			'</div>' +
			'<div class="tw-crm-form__field">' +
			'<label for="tw-crm-phone">' + escapeHtml(t('phone', 'Phone')) + (cfg.phoneRequired ? '<span class="tw-crm-form__req">*</span>' : '') + '</label>' +
			'<input type="tel" id="tw-crm-phone" name="phone" ' + (cfg.phoneRequired ? 'required' : '') + ' autocomplete="tel" placeholder="(555) 555-5555">' +
			'</div>' +
			'</div>' +
			'<div class="tw-crm-form__field" id="tw-crm-location-field" hidden>' +
			'<label for="tw-crm-location">' + escapeHtml(t('location', 'Location')) + '</label>' +
			'<select id="tw-crm-location" name="location_id"></select>' +
			'</div>' +
			'<div class="tw-crm-form__field">' +
			'<label for="tw-crm-message">' + escapeHtml(t('message', 'Message')) + '</label>' +
			'<textarea id="tw-crm-message" name="message" rows="4"></textarea>' +
			'</div>' +
			'<div class="tw-crm-form__feedback" id="tw-crm-form-feedback" role="alert" hidden></div>' +
			'<button type="submit" class="tw-crm-form__submit" id="tw-crm-form-submit">' + escapeHtml(cfg.submitText || 'Submit') + '</button>' +
			'</form>';

		formEl = document.getElementById('tw-crm-lead-form');
		feedbackEl = document.getElementById('tw-crm-form-feedback');
		submitBtn = document.getElementById('tw-crm-form-submit');
		locationFieldEl = document.getElementById('tw-crm-location-field');

		if (!cfg.endpointConfigured) {
			showFeedback(t('notConfigured', 'This form is not accepting submissions yet. Please contact us directly.'), 'error');
			if (submitBtn) {
				submitBtn.disabled = true;
			}
		}

		if (formEl) {
			formEl.addEventListener('submit', onSubmit);
		}
	}

	function clearFeedback() {
		if (!feedbackEl) {
			return;
		}
		feedbackEl.hidden = true;
		feedbackEl.textContent = '';
		feedbackEl.classList.remove('is-success', 'is-error');
	}

	function showFeedback(message, type) {
		if (!feedbackEl) {
			return;
		}
		feedbackEl.hidden = false;
		feedbackEl.textContent = message;
		feedbackEl.classList.remove('is-success', 'is-error');
		feedbackEl.classList.add(type === 'success' ? 'is-success' : 'is-error');
	}

	function clearInvalid() {
		if (!formEl) {
			return;
		}
		formEl.querySelectorAll('.is-invalid').forEach(function (el) {
			el.classList.remove('is-invalid');
		});
	}

	function isValidEmail(value) {
		return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
	}

	function renderLocationPicker() {
		if (!locationFieldEl) {
			return;
		}
		var select = document.getElementById('tw-crm-location');
		if (!select) {
			return;
		}

		// Per API docs: skip the picker entirely when there's 0 or 1 location
		// (a single location is auto-assigned server-side).
		if (!locations || locations.length < 2) {
			locationFieldEl.hidden = true;
			select.innerHTML = '';
			return;
		}

		var options = '<option value="">' + escapeHtml(t('selectLocation', 'Select a location…')) + '</option>';
		locations.forEach(function (loc) {
			var label = loc.name || '';
			if (loc.city) {
				label += (label ? ' — ' : '') + loc.city + (loc.state ? ', ' + loc.state : '');
			}
			options += '<option value="' + escapeHtml(loc.id) + '"' + (loc.isDefault ? ' selected' : '') + '>' + escapeHtml(label || loc.id) + '</option>';
		});
		select.innerHTML = options;
		locationFieldEl.hidden = false;
	}

	function loadFormDataIfNeeded() {
		if (formDataState === 'loading' || formDataState === 'ready') {
			return;
		}
		if (!cfg.endpointConfigured) {
			return;
		}

		formDataState = 'loading';

		var body = new FormData();
		body.append('action', cfg.formDataAction || 'tw_crm_get_form_data');
		body.append('nonce', cfg.nonce || '');

		fetch(cfg.ajaxUrl || '/wp-admin/admin-ajax.php', {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		})
			.then(function (res) {
				return res
					.json()
					.catch(function () {
						return { success: false };
					});
			})
			.then(function (json) {
				if (json && json.success && json.data) {
					locations = Array.isArray(json.data.locations) ? json.data.locations : [];
					formDataState = 'ready';
					renderLocationPicker();
				} else {
					formDataState = 'error';
					locations = [];
				}
			})
			.catch(function () {
				formDataState = 'error';
				locations = [];
			});
	}

	function resolveProductId(trigger) {
		return (
			(trigger && parseInt(trigger.getAttribute('data-product-id'), 10)) ||
			parseInt(window.targetWebShopifyProductId, 10) ||
			parseInt(cfg.currentProductId, 10) ||
			parseInt(cfg.defaultProductId, 10) ||
			0
		);
	}

	function openModal(trigger) {
		if (!popupEl) {
			return;
		}

		currentProductId = resolveProductId(trigger);
		lastFocusedEl = document.activeElement;
		popupEl.classList.add('is-open');
		popupEl.setAttribute('aria-hidden', 'false');

		// Theme modal: never force display. The theme's own CSS/JS owns
		// show/hide. Forcing display:flex|none is what breaks theme popups.
		if (!useThemeModal) {
			popupEl.style.display = 'flex';
			document.body.classList.add('tw-crm-popup-open');
		}

		clearFeedback();
		clearInvalid();
		loadFormDataIfNeeded();

		var firstField = mountEl && mountEl.querySelector('input, textarea, button');
		if (firstField) {
			setTimeout(function () {
				firstField.focus();
			}, 30);
		}
	}

	function closeModal() {
		if (useInline || !popupEl) {
			return;
		}
		popupEl.classList.remove('is-open');
		popupEl.setAttribute('aria-hidden', 'true');

		if (!useThemeModal) {
			popupEl.style.display = 'none';
			document.body.classList.remove('tw-crm-popup-open');
		}

		if (lastFocusedEl && typeof lastFocusedEl.focus === 'function') {
			lastFocusedEl.focus();
		}
	}

	function setSubmitting(on) {
		if (!submitBtn) {
			return;
		}
		submitBtn.disabled = !!on;
		submitBtn.textContent = on
			? t('sending', 'Sending…')
			: (cfg.submitText || 'Submit');
	}

	function onSubmit(e) {
		e.preventDefault();

		if (!cfg.endpointConfigured) {
			showFeedback(t('notConfigured', 'This form is not accepting submissions yet. Please contact us directly.'), 'error');
			return;
		}

		clearFeedback();
		clearInvalid();

		var firstNameEl = formEl.querySelector('[name="first_name"]');
		var lastNameEl = formEl.querySelector('[name="last_name"]');
		var emailEl = formEl.querySelector('[name="email"]');
		var phoneEl = formEl.querySelector('[name="phone"]');
		var messageEl = formEl.querySelector('[name="message"]');
		var locationEl = formEl.querySelector('[name="location_id"]');

		var ok = true;
		if (!firstNameEl || !firstNameEl.value.trim()) {
			if (firstNameEl) firstNameEl.classList.add('is-invalid');
			ok = false;
		}
		if (!emailEl || !emailEl.value.trim()) {
			if (emailEl) emailEl.classList.add('is-invalid');
			ok = false;
		} else if (!isValidEmail(emailEl.value.trim())) {
			emailEl.classList.add('is-invalid');
			showFeedback(t('invalidEmail', 'Please enter a valid email address.'), 'error');
			return;
		}
		if (cfg.phoneRequired && (!phoneEl || !phoneEl.value.trim())) {
			if (phoneEl) phoneEl.classList.add('is-invalid');
			ok = false;
		}

		if (!ok) {
			showFeedback(t('required', 'Please fill in all required fields.'), 'error');
			return;
		}

		var body = new FormData();
		body.append('action', cfg.action || 'tw_crm_submit_lead');
		body.append('nonce', cfg.nonce || '');
		var productId = currentProductId || cfg.currentProductId || cfg.defaultProductId || 0;
		if (productId) {
			body.append('product_id', String(productId));
		}
		body.append('first_name', firstNameEl.value.trim());
		body.append('last_name', lastNameEl ? lastNameEl.value.trim() : '');
		body.append('email', emailEl.value.trim());
		body.append('phone', phoneEl ? phoneEl.value.trim() : '');
		body.append('message', messageEl ? messageEl.value.trim() : '');
		if (locationEl && locationEl.value) {
			body.append('location_id', locationEl.value);
		}

		setSubmitting(true);

		fetch(cfg.ajaxUrl || '/wp-admin/admin-ajax.php', {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		})
			.then(function (res) {
				return res
					.json()
					.catch(function () {
						return { success: false };
					})
					.then(function (data) {
						return { ok: res.ok, data: data };
					});
			})
			.then(function (result) {
				if (result.data && result.data.success) {
					showFeedback(t('success', 'Thank you! We will be in touch shortly.'), 'success');
					formEl.reset();
					if (!useInline && !useThemeModal) {
						setTimeout(closeModal, 1800);
					}
					return;
				}
				var msg =
					(result.data && result.data.data && result.data.data.message) ||
					t('genericError', 'Something went wrong. Please try again.');
				showFeedback(msg, 'error');
			})
			.catch(function () {
				showFeedback(t('genericError', 'Something went wrong. Please try again.'), 'error');
			})
			.finally(function () {
				setSubmitting(false);
			});
	}

	function findTrigger(el) {
		if (!el || !el.closest) {
			return null;
		}
		return el.closest('#' + TRIGGER_ID) || el.closest('[data-tw-crm-open]');
	}

	function init() {
		if (initialized) {
			return;
		}
		initialized = true;

		var mode = detectDisplayMode();
		useInline = mode === 'inline';
		useThemeModal = mode === 'theme';

		if (!ensurePopupShell()) {
			// Theme-modal mode but the theme never output #tw-crm-popup — stay silent.
			return;
		}

		renderForm();

		currentProductId = resolveProductId(null);
		loadFormDataIfNeeded();

		if (useInline) {
			return;
		}

		if (popupEl) {
			popupEl.addEventListener('click', function (e) {
				if (e.target.closest('[data-tw-crm-close]')) {
					closeModal();
				}
			});
		}

		if (!useThemeModal) {
			document.addEventListener('keydown', function (e) {
				if (popupEl && e.key === 'Escape' && popupEl.classList.contains('is-open')) {
					closeModal();
				}
			});
		}

		// Delegated so it keeps working even if the theme re-renders the button.
		document.addEventListener('click', function (e) {
			var trigger = findTrigger(e.target);
			if (!trigger) {
				return;
			}
			currentProductId = resolveProductId(trigger);
			loadFormDataIfNeeded();

			if (useThemeModal) {
				// Let the theme open/close its own popup. We only capture
				// the product id and make sure the form/locations are ready.
				return;
			}

			e.preventDefault();
			e.stopPropagation();
			openModal(trigger);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
