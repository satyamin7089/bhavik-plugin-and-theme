/**
 * ASRS Forms Engine - Lean Frontend AJAX Submission
 */
(function () {
	'use strict';

	if (typeof window.ASRSFormsConfig === 'undefined') {
		return;
	}

	var config = window.ASRSFormsConfig;

	function setNotice(container, type, text) {
		if (!container) return;
		container.innerHTML = '';
		if (!text) return;
		var box = document.createElement('div');
		box.className = 'asrs-notice-' + type;
		box.textContent = text;
		container.appendChild(box);
	}

	function clearFieldErrors(formEl) {
		formEl.querySelectorAll('.asrs-field-group').forEach(function (group) {
			group.classList.remove('has-error');
			var err = group.querySelector('.asrs-field-error');
			if (err) err.textContent = '';
		});
	}

	function showFieldErrors(formEl, fieldErrors) {
		if (!fieldErrors) return;
		Object.keys(fieldErrors).forEach(function (fieldName) {
			var group = formEl.querySelector('.asrs-field-group[data-field-name="' + fieldName + '"]');
			if (!group) return;
			group.classList.add('has-error');
			var err = group.querySelector('.asrs-field-error');
			if (err) err.textContent = fieldErrors[fieldName];
		});
	}

	function collectFields(formEl) {
		var data = {};
		formEl.querySelectorAll('[name^="fields["]').forEach(function (el) {
			var match = el.name.match(/^fields\[([^\]]+)\]$/);
			if (!match) return;
			var key = match[1];
			if (el.type === 'checkbox') {
				data[key] = el.checked ? '1' : '';
			} else if (el.type === 'radio') {
				if (el.checked) data[key] = el.value;
			} else {
				data[key] = el.value;
			}
		});
		return data;
	}

	function postAjax(action, payload) {
		var body = new URLSearchParams();
		body.append('action', action);
		body.append('nonce', config.nonce);
		body.append('page_url', window.location.href);

		Object.keys(payload).forEach(function (key) {
			var val = payload[key];
			if (val !== null && typeof val === 'object') {
				Object.keys(val).forEach(function (subKey) {
					body.append(key + '[' + subKey + ']', val[subKey]);
				});
			} else if (val !== undefined && val !== null) {
				body.append(key, String(val));
			}
		});

		return fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			body: body.toString()
		}).then(function (res) {
			return res.json();
		});
	}

	function syncSelectState(selectEl) {
		if (!selectEl) return;
		if (selectEl.value === '') {
			selectEl.classList.add('asrs-select-placeholder');
		} else {
			selectEl.classList.remove('asrs-select-placeholder');
		}
	}

	function setButtonLabel(btn, text) {
		if (!btn) return;
		var labelEl = btn.querySelector('.asrs-btn-label');
		if (labelEl) {
			labelEl.textContent = text;
		} else {
			btn.textContent = text;
		}
	}

	/* ---- Google reCAPTCHA (optional, configured in ASRS Forms → Settings) ---- */
	var recaptchaCfg = config.recaptcha || {};
	var recaptchaOn = !!(recaptchaCfg.enabled && recaptchaCfg.siteKey);

	function whenRecaptchaReady(cb, tries) {
		tries = tries || 0;
		if (window.grecaptcha && typeof window.grecaptcha.ready === 'function') {
			window.grecaptcha.ready(cb);
			return;
		}
		if (tries < 150) {
			setTimeout(function () { whenRecaptchaReady(cb, tries + 1); }, 200);
		}
	}

	function renderRecaptchaWidgets() {
		if (!recaptchaOn || recaptchaCfg.version === 'v3') return;
		whenRecaptchaReady(function () {
			if (typeof window.grecaptcha.render !== 'function') return;
			document.querySelectorAll('.asrs-form-wrapper .asrs-g-recaptcha').forEach(function (el) {
				if (el.getAttribute('data-widget-id') !== null) return;
				try {
					var id = window.grecaptcha.render(el, { sitekey: el.getAttribute('data-sitekey') || recaptchaCfg.siteKey });
					el.setAttribute('data-widget-id', String(id));
				} catch (err) {}
			});
		});
	}

	function getRecaptchaWidgetId(formEl) {
		var el = formEl.querySelector('.asrs-g-recaptcha');
		if (!el) return null;
		var id = el.getAttribute('data-widget-id');
		return id === null ? null : parseInt(id, 10);
	}

	function getRecaptchaToken(formEl) {
		if (!recaptchaOn) return Promise.resolve('');
		if (recaptchaCfg.version === 'v3') {
			return new Promise(function (resolve) {
				if (!window.grecaptcha || typeof window.grecaptcha.ready !== 'function') {
					resolve('');
					return;
				}
				window.grecaptcha.ready(function () {
					try {
						window.grecaptcha.execute(recaptchaCfg.siteKey, { action: recaptchaCfg.action || 'asrs_form_submit' })
							.then(function (token) { resolve(token || ''); }, function () { resolve(''); });
					} catch (err) {
						resolve('');
					}
				});
			});
		}
		var widgetId = getRecaptchaWidgetId(formEl);
		var token = '';
		if (window.grecaptcha && widgetId !== null) {
			try { token = window.grecaptcha.getResponse(widgetId) || ''; } catch (err) {}
		}
		if (!token) {
			var field = formEl.querySelector('[name="g-recaptcha-response"]');
			token = field ? field.value : '';
		}
		return Promise.resolve(token);
	}

	function resetRecaptcha(formEl) {
		if (!recaptchaOn || recaptchaCfg.version === 'v3' || !window.grecaptcha) return;
		var widgetId = getRecaptchaWidgetId(formEl);
		if (widgetId === null) return;
		try { window.grecaptcha.reset(widgetId); } catch (err) {}
	}

	function initWrapper(wrapper) {
		var formId = wrapper.getAttribute('data-form-id');
		var formEl = wrapper.querySelector('form.asrs-form');
		if (!formEl) return;

		var messagesEl = formEl.querySelector('.asrs-form-messages');
		var submitBtn = formEl.querySelector('.asrs-submit-btn');
		var isSubmitting = false;

		formEl.querySelectorAll('select.asrs-select').forEach(function (sel) {
			syncSelectState(sel);
			sel.addEventListener('change', function () {
				syncSelectState(sel);
			});
		});

		formEl.addEventListener('submit', function (e) {
			e.preventDefault();
			if (isSubmitting) return;

			clearFieldErrors(formEl);
			setNotice(messagesEl, '', '');
			isSubmitting = true;

			if (submitBtn) {
				submitBtn.disabled = true;
				setButtonLabel(submitBtn, submitBtn.getAttribute('data-loading-label') || 'Sending...');
			}

			var formData = new FormData(formEl);
			var payload = {
				form_id: formId,
				asrs_form_nonce: formData.get('asrs_form_nonce') || '',
				asrs_rendered_at: formData.get('asrs_rendered_at') || '',
				asrs_hp_website: formData.get('asrs_hp_website') || '',
				'cf-turnstile-response': formData.get('cf-turnstile-response') || '',
				fields: collectFields(formEl)
			};

			getRecaptchaToken(formEl)
				.then(function (recaptchaToken) {
					if (recaptchaOn) {
						payload['g-recaptcha-response'] = recaptchaToken;
					}
					return postAjax('asrs_submit_form', payload);
				})
				.then(function (resp) {
					if (resp && resp.success) {
						setNotice(messagesEl, 'success', resp.data.message);
						formEl.reset();
						formEl.querySelectorAll('select.asrs-select').forEach(syncSelectState);
					} else {
						var errData = resp && resp.data ? resp.data : {};
						setNotice(messagesEl, 'error', errData.message || config.i18n.genericError);
						if (errData.field_errors) {
							showFieldErrors(formEl, errData.field_errors);
						}
					}
				})
				.catch(function () {
					setNotice(messagesEl, 'error', config.i18n.genericError);
				})
				.finally(function () {
					isSubmitting = false;
					if (submitBtn) {
						submitBtn.disabled = false;
						setButtonLabel(submitBtn, submitBtn.getAttribute('data-default-label') || 'Send Enquiry');
					}
					if (typeof window.turnstile !== 'undefined') {
						try {
							window.turnstile.reset();
						} catch (err) {}
					}
					resetRecaptcha(formEl);
				});
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('.asrs-form-wrapper').forEach(initWrapper);
		renderRecaptchaWidgets();
	});
})();
