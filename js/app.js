/* TourCheck web app runtime — vanilla JS, no build step. */
(function () {
	'use strict';
	function t(key) {
		if (window.OC && OC.L10N && typeof OC.L10N.translate === 'function') {
			try {
				var tr = OC.L10N.translate('tourcheck', key);
				if (tr) { return tr; }
			} catch (e) { /* fall through */ }
		}
		return key;
	}

	var appId = 'tourcheck';

	if (window.CheckFieldErrors) {
		CheckFieldErrors.install({ prefix: 'tck' });
	}

	function route(name, params) {
		return OC.generateUrl('/apps/' + appId + name, params || {});
	}

	function headers(extra) {
		var h = { 'Content-Type': 'application/json', 'OCS-APIREQUEST': 'true' };
		if (typeof OC !== 'undefined' && OC.requestToken) { h.requesttoken = OC.requestToken; }
		return Object.assign(h, extra || {});
	}

	function api(method, path, body) {
		return fetch(route(path), {
			method: method,
			headers: headers(),
			credentials: 'same-origin',
			body: body === undefined ? undefined : JSON.stringify(body)
		}).then(function (res) {
			return res.json().catch(function () { return { success: false, error: 'BAD_RESPONSE', message: res.statusText, fields: {} }; })
				.then(function (json) { json._status = res.status; return json; });
		});
	}

	/* --- toasts: app-owned, dedup on kind+text with timer reset (§6 contract) --- */
	var toastBox = null;
	var toastTimers = {};
	function toast(kind, text) {
		if (!toastBox) {
			toastBox = document.createElement('div');
			toastBox.className = 'tck-toasts';
			toastBox.id = 'tck-toasts';
			document.body.appendChild(toastBox);
		}
		var ttl = kind === 'error' ? 7000 : 4000;
		var key = kind + '|' + text;
		var existing = toastBox.querySelector('[data-tck-toast-key="' + CSS.escape(key) + '"]');
		if (existing && toastTimers[key]) {
			clearTimeout(toastTimers[key]);
			toastTimers[key] = setTimeout(function () { existing.remove(); delete toastTimers[key]; }, ttl);
			return;
		}
		var el = document.createElement('div');
		el.className = 'tck-toast' + (kind === 'error' ? ' tck-toast--error' : '');
		el.setAttribute('role', kind === 'error' ? 'alert' : 'status');
		el.setAttribute('data-tck-toast-key', key);
		var content = document.createElement('span');
		content.className = 'tck-toast__content';
		var textEl = document.createElement('span');
		textEl.className = 'tck-toast__text';
		textEl.textContent = String(text);
		content.appendChild(textEl);
		var close = document.createElement('button');
		close.type = 'button';
		close.className = 'tck-toast__close';
		close.setAttribute('aria-label', t('Dismiss'));
		close.textContent = '✕';
		close.addEventListener('click', function () {
			if (toastTimers[key]) { clearTimeout(toastTimers[key]); delete toastTimers[key]; }
			el.remove();
		});
		el.appendChild(content);
		el.appendChild(close);
		toastBox.appendChild(el);
		toastTimers[key] = setTimeout(function () { el.remove(); delete toastTimers[key]; }, ttl);
	}

	function announce(text) {
		var region = document.getElementById('tck-live-region');
		if (region) { region.textContent = text; }
	}

	/* --- field errors: aria-invalid + inline, per _shared/field-errors contract.
	 * Form-scoped variant: site.php hosts multiple forms sharing input names
	 * (name, label), so the document-scoped canonical module would mark the
	 * wrong control. Slots resolve [name]→#<input.id>-error→[data-tck-err]. --- */
	function clearFieldErrors(form) {
		form.querySelectorAll('[data-tck-invalid="1"]').forEach(function (el) {
			el.removeAttribute('aria-invalid');
			el.removeAttribute('data-tck-invalid');
			var prev = el.getAttribute('data-tck-prev-describedby');
			el.removeAttribute('data-tck-prev-describedby');
			if (prev === null || prev === '') {
				el.removeAttribute('aria-describedby');
			} else {
				el.setAttribute('aria-describedby', prev);
			}
		});
		form.querySelectorAll('.tck-field-error').forEach(function (el) {
			el.hidden = true; el.textContent = '';
		});
	}
	function applyFieldErrors(form, fields) {
		var firstInput = null;
		Object.keys(fields || {}).forEach(function (name) {
			var input = form.querySelector('[name="' + CSS.escape(name) + '"]');
			var err = (input && input.id ? document.getElementById(input.id + '-error') : null)
				|| form.querySelector('[data-tck-err="' + CSS.escape(name) + '"]');
			if (!err) {
				// No slotted element — delegate to the canonical shared
				// module (injects its own slot, marks aria-invalid, and
				// focuses). Only safe when the field name is unique
				// page-wide; otherwise mark the in-form input ourselves.
				if (window.CheckFieldErrors && input
					&& document.querySelectorAll('#app-content [name="' + CSS.escape(name) + '"]').length === 1) {
					var one = {}; one[name] = fields[name];
					CheckFieldErrors.markValidationFields(one);
					return;
				}
				if (input) {
					input.setAttribute('aria-invalid', 'true');
					input.setAttribute('data-tck-invalid', '1');
					if (!firstInput) { firstInput = input; }
				}
				return;
			}
			if (input) {
				input.setAttribute('aria-invalid', 'true');
				input.setAttribute('data-tck-invalid', '1');
				input.setAttribute('data-tck-prev-describedby', input.getAttribute('aria-describedby') || '');
				var prev = input.getAttribute('data-tck-prev-describedby') || '';
				input.setAttribute('aria-describedby', (prev ? prev + ' ' : '') + err.id);
				if (!firstInput) { firstInput = input; }
			}
			err.textContent = fields[name];
			err.hidden = false;
		});
		if (firstInput) { firstInput.focus(); }
	}

	function handleError(form, json) {
		if (json && json.fields && Object.keys(json.fields).length && form) {
			applyFieldErrors(form, json.fields);
		}
		toast('error', (json && json.message) || t('Error'));
	}

	/* Instant required-field check — same slots as server `fields` errors.
	   Empty required inputs get inline feedback without a network round
	   trip; slots that can't resolve (repeatable row names) still get
	   aria-invalid + focus. */
	function validateRequired(form) {
		var bad = [];
		form.querySelectorAll('[required]').forEach(function (el) {
			var empty = el.type === 'checkbox' ? !el.checked : String(el.value || '').trim() === '';
			if (empty && !el.disabled && el.offsetParent !== null) { bad.push(el); }
		});
		if (bad.length === 0) { return true; }
		var fields = {};
		bad.forEach(function (el) {
			var name = el.name || '';
			var err = (el.id ? document.getElementById(el.id + '-error') : null)
				|| (name !== '' ? form.querySelector('[data-tck-err="' + CSS.escape(name) + '"]') : null);
			if (err) { fields[name] = t('This field is required.'); }
			else {
				el.setAttribute('aria-invalid', 'true');
				el.setAttribute('data-tck-invalid', '1');
			}
		});
		if (Object.keys(fields).length) { applyFieldErrors(form, fields); }
		bad[0].focus();
		return false;
	}

	function serializeForm(form) {
		var data = {};
		var fd = new FormData(form);
		fd.forEach(function (v, k) { data[k] = v; });
		form.querySelectorAll('input[type="checkbox"]').forEach(function (cb) {
			data[cb.name] = cb.checked ? '1' : '0';
		});
		return data;
	}

	function submitForm(form, path, done, method) {
		clearFieldErrors(form);
		if (!validateRequired(form)) { return; }
		var btn = form.querySelector('[type="submit"]');
		if (btn) { btn.disabled = true; }
		api(method || 'POST', path, serializeForm(form)).then(function (json) {
			if (btn) { btn.disabled = false; }
			if (json.success) {
				toast('ok', json.data && json.data.message ? json.data.message : t('Saved'));
				announce(t('Saved'));
				if (done) { done(json); } else { location.reload(); }
			} else {
				handleError(form, json);
			}
		}).catch(function () {
			if (btn) { btn.disabled = false; }
			toast('error', t('Network error'));
		});
	}


	/* --- site scope selector: reload page with site param --- */
	var siteSel = document.getElementById('tck-site-select');
	if (siteSel) {
		siteSel.addEventListener('change', function () {
			var url = new URL(location.href);
			url.searchParams.set('site', siteSel.value);
			location.href = url.toString();
		});
	}

	/* --- sites page --- */
	var siteForm = document.querySelector('[data-tck-site-form]');
	var newSiteBtn = document.querySelector('[data-tck-new-site]');
	if (newSiteBtn && siteForm) {
		newSiteBtn.addEventListener('click', function () {
			siteForm.classList.toggle('tck-hidden');
			var input = siteForm.querySelector('input[name="name"]');
			if (input && !siteForm.classList.contains('tck-hidden')) { input.focus(); }
		});
		siteForm.addEventListener('submit', function (e) {
			e.preventDefault();
			/* Onboarding continuation: a fresh site's next step is always
			   its detail page (checkpoints/template + plan) — not a list. */
			submitForm(siteForm, '/api/sites', function (json) {
				var id = json.data && json.data.id;
				location.href = id ? route('/sites/' + id) : location.href;
			});
		});
	}
	/* Empty-state guidance: when the thing a create-form makes does not
	   exist yet, the form IS the primary action — open it with focus.
	   The toggle button and Cancel still work. */
	document.querySelectorAll('form[data-tck-auto-open]').forEach(function (form) {
		form.classList.remove('tck-hidden');
		var input = form.querySelector('input, select, textarea');
		if (input) { input.focus(); }
	});
	document.querySelectorAll('[data-tck-cancel]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var form = btn.closest('form');
			if (form) { form.classList.add('tck-hidden'); clearFieldErrors(form); }
		});
	});
	document.querySelectorAll('[data-tck-show]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var form = document.getElementById(btn.getAttribute('data-tck-show'));
			if (!form) { return; }
			form.classList.toggle('tck-hidden');
			var input = form.querySelector('input, select, textarea');
			if (input && !form.classList.contains('tck-hidden')) { input.focus(); }
		});
	});

	/* --- round detail: supervisor skips a not-yet-finished round --- */
	var skipForm = document.querySelector('[data-tck-skip-form]');
	if (skipForm) {
		skipForm.addEventListener('submit', function (e) {
			e.preventDefault();
			submitForm(skipForm, '/api/rounds/' + skipForm.getAttribute('data-round-id') + '/skip');
		});
	}

	/* --- journal: manual RFC-3161 anchor --- */
	document.querySelectorAll('[data-tck-anchor-now]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			btn.disabled = true;
			api('POST', '/api/sites/' + btn.getAttribute('data-site-id') + '/anchor', {}).then(function (json) {
				btn.disabled = false;
				if (json.success) {
					toast('ok', json.data && json.data.delivered ? t('Anchored') : t('Anchor requested — delivery pending'));
					setTimeout(function () { location.reload(); }, 600);
				} else {
					toast('error', (json.error && json.error.message) ? json.error.message : t('Anchor failed'));
				}
			}).catch(function () {
				btn.disabled = false;
				toast('error', t('Network error'));
			});
		});
	});

	/* --- empty site: apply a vertical template (checkpoints + draft plan) --- */
	var tplForm = document.querySelector('[data-tck-template-form]');
	if (tplForm) {
		tplForm.addEventListener('submit', function (e) {
			e.preventDefault();
			var sel = tplForm.querySelector('select[name="tkey"]');
			var key = sel ? sel.value : '';
			if (!/^[a-z0-9_-]+$/.test(key)) { return; }
			submitForm(tplForm, '/api/sites/' + tplForm.getAttribute('data-site-id') + '/template/' + key);
		});
	}

	/* --- site privacy: GDPR erasure of one uid's PII (irreversible) --- */
	var eraseForm = document.querySelector('[data-tck-erase-form]');
	if (eraseForm) {
		eraseForm.addEventListener('submit', function (e) {
			e.preventDefault();
			clearFieldErrors(eraseForm);
			if (!validateRequired(eraseForm)) { return; }
			var uidInput = eraseForm.querySelector('input[name="uid"]');
			var target = uidInput ? uidInput.value.trim() : '';
			if (!confirm(t('Erase all personal data for "%s" on this site? This is journaled and irreversible.').replace('%s', target))) { return; }
			clearFieldErrors(eraseForm);
			var btn = eraseForm.querySelector('[type="submit"]');
			if (btn) { btn.disabled = true; }
			api('POST', '/api/sites/' + eraseForm.getAttribute('data-site-id') + '/erase', serializeForm(eraseForm)).then(function (json) {
				if (btn) { btn.disabled = false; }
				if (json.success) {
					var n = (json.data && typeof json.data.rows === 'number') ? json.data.rows : 0;
					// Row count surfaced — 0 means the uid had no data (typo guard).
					toast('ok', t('Erased %s personal-data rows — journaled.').replace('%s', String(n)));
					announce(t('Saved'));
					setTimeout(function () { location.reload(); }, 900);
				} else {
					handleError(eraseForm, json);
				}
			}).catch(function () {
				if (btn) { btn.disabled = false; }
				toast('error', t('Network error'));
			});
		});
	}

	/* --- site detail: checkpoints --- */
	var cpForm = document.querySelector('[data-tck-cp-form]');
	var newCpBtn = document.querySelector('[data-tck-new-cp]');
	if (newCpBtn && cpForm) {
		var siteId = cpForm.getAttribute('data-site-id');
		newCpBtn.addEventListener('click', function () {
			cpForm.classList.toggle('tck-hidden');
			var input = cpForm.querySelector('input[name="name"]');
			if (input && !cpForm.classList.contains('tck-hidden')) { input.focus(); }
		});
		// Checklist (profile) editor — rows serialize to the nested `profile`
		// payload the API validates (keys derive from labels).
		var itemsBox = cpForm.querySelector('[data-tck-profile-items]');
		var itemTpl = document.getElementById('tck-profile-item-tpl');
		function piRow() {
			var node = itemTpl.content.firstElementChild.cloneNode(true);
			var kind = node.querySelector('[name="pi_kind"]');
			var band = node.querySelector('[data-pi-band]');
			kind.addEventListener('change', function () { band.hidden = kind.value !== 'number'; });
			node.querySelector('[data-pi-remove]').addEventListener('click', function () { node.remove(); });
			itemsBox.appendChild(node);
		}
		var addBtn = cpForm.querySelector('[data-tck-profile-add]');
		if (addBtn) { addBtn.addEventListener('click', piRow); }
		function collectProfile() {
			var items = [];
			itemsBox.querySelectorAll('.tck-profile-item').forEach(function (row, i) {
				var label = row.querySelector('[name="pi_label"]').value.trim();
				if (!label) { return; }
				var it = {
					key: 'item_' + (i + 1),
					label: label,
					kind: row.querySelector('[name="pi_kind"]').value,
					required: row.querySelector('[name="pi_required"]').checked
				};
				if (it.kind === 'number') {
					var min = row.querySelector('[name="pi_min"]').value.trim();
					var max = row.querySelector('[name="pi_max"]').value.trim();
					var unit = row.querySelector('[name="pi_unit"]').value.trim();
					if (min !== '') { it.min = parseFloat(min); }
					if (max !== '') { it.max = parseFloat(max); }
					if (unit !== '') { it.unit = unit; }
				}
				items.push(it);
			});
			return items.length ? { items: items } : null;
		}
		cpForm.addEventListener('submit', function (e) {
			e.preventDefault();
			var data = serializeForm(cpForm);
			var profile = collectProfile();
			if (profile) { data.profile = profile; }
			delete data.pi_label; delete data.pi_kind; delete data.pi_required;
			delete data.pi_min; delete data.pi_max; delete data.pi_unit;
			clearFieldErrors(cpForm);
			if (!validateRequired(cpForm)) { return; }
			var btn = cpForm.querySelector('[type="submit"]');
			if (btn) { btn.disabled = true; }
			api('POST', '/api/sites/' + siteId + '/checkpoints', data).then(function (json) {
				if (btn) { btn.disabled = false; }
				if (json.success) { toast('ok', t('Saved')); announce(t('Saved')); location.reload(); }
				else { handleError(cpForm, json); }
			}).catch(function () {
				if (btn) { btn.disabled = false; }
				toast('error', t('Network error'));
			});
		});
	}
	document.querySelectorAll('[data-tck-archive-cp]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			if (!confirm(t('Archive this checkpoint? Its codes stop working.'))) { return; }
			api('DELETE', '/api/checkpoints/' + btn.getAttribute('data-tck-archive-cp')).then(function (json) {
				if (json.success) { location.reload(); } else { handleError(null, json); }
			});
		});
	});

	/* --- round page: attest --- */
	document.querySelectorAll('[data-tck-attest]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			api('POST', '/api/events/attest', { round_id: parseInt(btn.getAttribute('data-tck-attest'), 10) }).then(function (json) {
				if (json.success) { toast('ok', t('Attested')); setTimeout(function () { location.reload(); }, 600); }
				else { handleError(null, json); }
			});
		});
	});

	/* --- settings forms --- */
	document.querySelectorAll('[data-tck-settings]').forEach(function (form) {
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var data = serializeForm(form);
			data._section = form.getAttribute('data-tck-settings');
			clearFieldErrors(form);
			var btn = form.querySelector('[type="submit"]');
			if (btn) { btn.disabled = true; }
			api('POST', '/api/admin/settings', data).then(function (json) {
				if (btn) { btn.disabled = false; }
				if (json.success) { toast('ok', t('Saved')); announce(t('Settings saved')); }
				else { handleError(form, json); }
			}).catch(function () {
				if (btn) { btn.disabled = false; }
				toast('error', t('Network error'));
			});
		});
	});

	/* --- site detail: live links --- */
	var liveForm = document.querySelector('[data-tck-live-form]');
	var newLiveBtn = document.querySelector('[data-tck-new-live]');
	if (newLiveBtn && liveForm) {
		var liveSiteId = liveForm.getAttribute('data-site-id');
		var liveBase = liveForm.getAttribute('data-live-base') || '';
		newLiveBtn.addEventListener('click', function () {
			liveForm.classList.toggle('tck-hidden');
			var input = liveForm.querySelector('input[name="label"]');
			if (input && !liveForm.classList.contains('tck-hidden')) { input.focus(); }
		});
		liveForm.addEventListener('submit', function (e) {
			e.preventDefault();
			clearFieldErrors(liveForm);
			var data = serializeForm(liveForm);
			if (!data.expires_at) { delete data.expires_at; }
			api('POST', '/api/sites/' + liveSiteId + '/live-links', data).then(function (json) {
				if (json.success && json.data && json.data.token) {
					var url = liveBase.replace('TCKTOKENPLACEHOLDERTCKTOKENPLACEHOLDERTCK1', json.data.token);
					var box = document.getElementById('tck-live-created');
					var urlEl = document.getElementById('tck-live-url');
					if (urlEl) { urlEl.textContent = url; }
					if (box) { box.classList.remove('tck-hidden'); }
					liveForm.classList.add('tck-hidden');
					liveForm.reset();
					toast('ok', t('Link created'));
				} else { handleError(liveForm, json); }
			}).catch(function () { toast('error', t('Network error')); });
		});
	}
	var liveCopy = document.getElementById('tck-live-copy');
	if (liveCopy) {
		liveCopy.addEventListener('click', function () {
			var urlEl = document.getElementById('tck-live-url');
			if (urlEl && navigator.clipboard) {
				navigator.clipboard.writeText(urlEl.textContent).then(function () {
					toast('ok', t('Copied'));
				}).catch(function () { toast('error', t('Network error')); });
			}
		});
	}
	document.querySelectorAll('[data-tck-revoke-live]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			if (!confirm(t('Revoke this live link? Its URL stops working immediately.'))) { return; }
			api('POST', '/api/live-links/' + btn.getAttribute('data-tck-revoke-live') + '/revoke', {}).then(function (json) {
				if (json.success) { toast('ok', t('Revoked')); setTimeout(function () { location.reload(); }, 600); }
				else { handleError(null, json); }
			}).catch(function () { toast('error', t('Network error')); });
		});
	});

	/* --- NH-10 key register --- */
	var keyForm = document.querySelector('[data-tck-key-form]');
	var newKeyBtn = document.querySelector('[data-tck-new-key]');
	if (newKeyBtn && keyForm) {
		newKeyBtn.addEventListener('click', function () {
			keyForm.classList.toggle('tck-hidden');
			var input = keyForm.querySelector('input[name="label"]');
			if (input && !keyForm.classList.contains('tck-hidden')) { input.focus(); }
		});
		keyForm.addEventListener('submit', function (e) {
			e.preventDefault();
			clearFieldErrors(keyForm);
			submitForm(keyForm, '/api/sites/' + keyForm.getAttribute('data-site-id') + '/keys');
		});
	}
	document.querySelectorAll('[data-tck-key-act]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			btn.disabled = true;
			var act = btn.getAttribute('data-act') === 'return' ? 'return' : 'take';
			api('POST', '/api/keys/' + btn.getAttribute('data-tck-key-act') + '/' + act, {}).then(function (json) {
				btn.disabled = false;
				if (json.success) { toast('ok', act === 'take' ? t('Key taken') : t('Key returned')); setTimeout(function () { location.reload(); }, 600); }
				else { handleError(null, json); }
			}).catch(function () { btn.disabled = false; toast('error', t('Network error')); });
		});
	});

	var pairCard = document.getElementById('tck-pair-card');

	/* --- station create --- */
	var stForm = document.querySelector('[data-tck-station-form]');
	var newStBtn = document.querySelector('[data-tck-new-station]');
	if (newStBtn && stForm) {
		newStBtn.addEventListener('click', function () {
			stForm.classList.toggle('tck-hidden');
			var input = stForm.querySelector('input[name="label"]');
			if (input && !stForm.classList.contains('tck-hidden')) { input.focus(); }
		});
		stForm.addEventListener('submit', function (e) {
			e.preventDefault();
			clearFieldErrors(stForm);
			if (!validateRequired(stForm)) { return; }
			var btn = stForm.querySelector('[type="submit"]');
			if (btn) { btn.disabled = true; }
			api('POST', '/api/sites/' + stForm.elements.site_id.value + '/stations', { label: stForm.elements.label.value }).then(function (json) {
				if (btn) { btn.disabled = false; }
				if (json.success && json.data && json.data.station) {
					/* Zero-typing onboarding: a new station's next step is
					   always the pairing card — issue it immediately. */
					api('POST', '/api/stations/' + json.data.station.id + '/pairing-code', {}).then(function (pj) {
						if (pj.success && pj.data && pairCard) {
							var qr = document.getElementById('tck-pair-qr');
							var code = document.getElementById('tck-pair-code');
							if (qr && pj.data.svg) { qr.innerHTML = pj.data.svg; }
							if (code) { code.textContent = pj.data.code; }
							pairCard.classList.remove('tck-hidden');
							toast('ok', t('Station created — scan the code on the kiosk.'));
							setTimeout(function () { location.reload(); }, 4000);
						} else { toast('ok', t('Station created')); setTimeout(function () { location.reload(); }, 600); }
					});
				} else { handleError(stForm, json); }
			}).catch(function () { if (btn) { btn.disabled = false; } toast('error', t('Network error')); });
		});
	}

	/* --- NH-12 station pairing card (QR + code, one scan pairs) --- */
	document.querySelectorAll('[data-tck-pair-station]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			btn.disabled = true;
			api('POST', '/api/stations/' + btn.getAttribute('data-tck-pair-station') + '/pairing-code', {}).then(function (json) {
				btn.disabled = false;
				if (json.success && json.data && pairCard) {
					var qr = document.getElementById('tck-pair-qr');
					var code = document.getElementById('tck-pair-code');
					if (qr) { qr.innerHTML = ''; if (json.data.svg) { qr.innerHTML = json.data.svg; } }
					if (code) { code.textContent = json.data.code; }
					pairCard.classList.remove('tck-hidden');
					pairCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
				} else { handleError(null, json); }
			}).catch(function () { btn.disabled = false; toast('error', t('Network error')); });
		});
	});
	var pairCopy = document.getElementById('tck-pair-copy');
	if (pairCopy) {
		pairCopy.addEventListener('click', function () {
			var code = document.getElementById('tck-pair-code');
			if (code && code.textContent) { navigator.clipboard.writeText(code.textContent); toast('ok', t('Copied')); }
		});
	}
	var pairPrint = document.getElementById('tck-pair-print');
	if (pairPrint) {
		pairPrint.addEventListener('click', function () {
			document.body.classList.add('tck-printing-pair');
			window.print();
		});
		window.addEventListener('afterprint', function () {
			document.body.classList.remove('tck-printing-pair');
		});
	}

	/* --- station checkpoint bind --- */
	document.querySelectorAll('[data-tck-bind-station]').forEach(function (sel) {
		sel.addEventListener('change', function () {
			var cp = sel.value === '' ? null : parseInt(sel.value, 10);
			api('POST', '/api/stations/' + sel.getAttribute('data-tck-bind-station') + '/checkpoint', {
				site_id: parseInt(sel.getAttribute('data-site-id'), 10),
				checkpoint_id: cp,
			}).then(function (json) {
				if (json.success) { toast('ok', t('Saved')); }
				else { handleError(null, json); }
			}).catch(function () { toast('error', t('Network error')); });
		});
	});

	/* --- station revoke --- */
	document.querySelectorAll('[data-tck-revoke-station]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			if (!confirm(t('Revoke this station? It stops working immediately.'))) { return; }
			api('POST', '/api/stations/' + btn.getAttribute('data-tck-revoke-station') + '/revoke', {}).then(function (json) {
				if (json.success) { toast('ok', t('Revoked')); setTimeout(function () { location.reload(); }, 600); }
				else { handleError(null, json); }
			}).catch(function () { toast('error', t('Network error')); });
		});
	});

	/* --- site detail: plan form (create + activate in one submit) --- */
	var planForm = document.querySelector('[data-tck-plan-form]');
	var newPlanBtn = document.querySelector('[data-tck-new-plan]');
	if (newPlanBtn && planForm) {
		var planSiteId = planForm.getAttribute('data-site-id');
		var typeSel = planForm.querySelector('[data-tck-plan-type]');
		function syncPlanFields() {
			var type = typeSel.value;
			planForm.querySelectorAll('[data-tck-plan-when]').forEach(function (el) {
				el.classList.toggle('tck-hidden', el.getAttribute('data-tck-plan-when').indexOf(type) === -1);
			});
		}
		typeSel.addEventListener('change', syncPlanFields);
		syncPlanFields();
		newPlanBtn.addEventListener('click', function () {
			planForm.classList.toggle('tck-hidden');
			var input = planForm.querySelector('input[name="name"]');
			if (input && !planForm.classList.contains('tck-hidden')) { input.focus(); input.select(); }
		});
		planForm.addEventListener('submit', function (e) {
			e.preventDefault();
			clearFieldErrors(planForm);
			if (!validateRequired(planForm)) { return; }
			var cps = [];
			planForm.querySelectorAll('input[name="cp_ids"]:checked').forEach(function (cb) {
				cps.push({ checkpoint_id: parseInt(cb.value, 10) });
			});
			var cpsErr = document.getElementById('tck-plan-cps-error');
			if (cps.length === 0) {
				if (cpsErr) { cpsErr.textContent = t('A plan needs at least one checkpoint.'); cpsErr.hidden = false; }
				return;
			}
			var type = typeSel.value;
			if (type === 'weekly' && planForm.querySelectorAll('input[name="day_ids"]:checked').length === 0) {
				var daysErr = document.getElementById('tck-plan-days-error');
				if (daysErr) { daysErr.textContent = t('Pick at least one weekday.'); daysErr.hidden = false; }
				return;
			}
			var rec = { type: type, duration_min: parseInt(planForm.elements.duration_min.value, 10) || 60 };
			var checkinRaw = (planForm.elements.checkin_interval_min.value || '').trim();
			var checkin = checkinRaw === '' ? null : parseInt(checkinRaw, 10);
			if (type === 'interval') {
				rec.interval_hours = parseInt(planForm.elements.rec_hours.value, 10) || 8;
				rec.anchor = new Date().toISOString().slice(0, 19) + '+00:00'; /* strict ATOM — no ms, no Z */
			} else {
				rec.time = planForm.elements.rec_time.value || '18:00';
				if (type === 'weekly') {
					rec.days = [];
					planForm.querySelectorAll('input[name="day_ids"]:checked').forEach(function (cb) {
						rec.days.push(parseInt(cb.value, 10));
					});
				}
			}
			var btn = planForm.querySelector('[type="submit"]');
			btn.disabled = true;
			api('POST', '/api/sites/' + planSiteId + '/plans', { name: planForm.elements.name.value, checkpoints: cps, recurrence: rec, checkin_interval_min: checkin }).then(function (json) {
				btn.disabled = false;
				if (json.success && json.data && json.data.id) {
					/* create+activate collapsed: rounds schedule immediately */
					api('POST', '/api/plans/' + json.data.id + '/activate', {}).then(function (aj) {
						toast(aj.success ? 'ok' : 'error', aj.success ? t('Plan created — rounds schedule themselves.') : (aj.message || t('Plan created, activation failed')));
						setTimeout(function () { location.reload(); }, 600);
					});
				} else { handleError(planForm, json); }
			}).catch(function () { btn.disabled = false; toast('error', t('Network error')); });
		});
	}
	document.querySelectorAll('[data-tck-plan-state]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var action = btn.getAttribute('data-active') === '1' ? 'deactivate' : 'activate';
			btn.disabled = true;
			api('POST', '/api/plans/' + btn.getAttribute('data-tck-plan-state') + '/' + action, {}).then(function (json) {
				btn.disabled = false;
				if (json.success) { toast('ok', action === 'activate' ? t('Plan activated') : t('Plan deactivated')); setTimeout(function () { location.reload(); }, 600); }
				else { handleError(null, json); }
			});
		});
	});

	/* --- site detail: privacy opt-in (NH-09) — CAS-guarded PUT --- */
	var livePosCb = document.querySelector('[data-tck-live-pos]');
	if (livePosCb) {
		livePosCb.addEventListener('change', function () {
			var wanted = livePosCb.checked;
			livePosCb.disabled = true;
			api('PUT', '/api/sites/' + livePosCb.getAttribute('data-site-id'), {
				live_position: wanted ? 1 : 0,
				revision: parseInt(livePosCb.getAttribute('data-revision'), 10)
			}).then(function (json) {
				livePosCb.disabled = false;
				if (json.success) {
					toast('ok', wanted ? t('Live positions enabled') : t('Live positions disabled'));
					announce(t('Saved'));
					setTimeout(function () { location.reload(); }, 600);
				} else {
					livePosCb.checked = !wanted;
					if (json._status === 409) { setTimeout(function () { location.reload(); }, 900); }
					handleError(null, json);
				}
			}).catch(function () {
				livePosCb.disabled = false;
				livePosCb.checked = !wanted;
				toast('error', t('Network error'));
			});
		});
	}

	/* --- site detail: members --- */
	var memberForm = document.querySelector('[data-tck-member-form]');
	var newMemberBtn = document.querySelector('[data-tck-new-member]');
	if (newMemberBtn && memberForm) {
		var memSiteId = memberForm.getAttribute('data-site-id');
		newMemberBtn.addEventListener('click', function () {
			memberForm.classList.toggle('tck-hidden');
			var input = memberForm.querySelector('input[name="uid"]');
			if (input && !memberForm.classList.contains('tck-hidden')) { input.focus(); }
		});
		memberForm.addEventListener('submit', function (e) {
			e.preventDefault();
			submitForm(memberForm, '/api/sites/' + memSiteId + '/members', null, 'PUT');
		});
	}
	document.querySelectorAll('[data-tck-remove-member]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var uid = btn.getAttribute('data-tck-remove-member');
			if (!confirm(t('Remove this member from the site?'))) { return; }
			api('DELETE', '/api/sites/' + btn.getAttribute('data-site-id') + '/members/' + encodeURIComponent(uid)).then(function (json) {
				if (json.success) { toast('ok', t('Member removed')); setTimeout(function () { location.reload(); }, 600); }
				else { handleError(null, json); }
			}).catch(function () { toast('error', t('Network error')); });
		});
	});

	/* --- incidents: create + close --- */
	var incForm = document.querySelector('[data-tck-incident-form]');
	var newIncBtn = document.querySelector('[data-tck-new-incident]');
	if (newIncBtn && incForm) {
		newIncBtn.addEventListener('click', function () {
			incForm.classList.toggle('tck-hidden');
			var input = incForm.querySelector('input[name="title"]');
			if (input && !incForm.classList.contains('tck-hidden')) { input.focus(); }
		});
		incForm.addEventListener('submit', function (e) {
			e.preventDefault();
			submitForm(incForm, '/api/incidents');
		});
	}
	document.querySelectorAll('[data-tck-close-incident]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			// 'closed' is a terminal status — same confirm bar as the other
			// irreversible actions (archive checkpoint, revoke link).
			if (!confirm(t('Close this incident? It cannot be reopened.'))) { return; }
			btn.disabled = true;
			api('PUT', '/api/incidents/' + btn.getAttribute('data-tck-close-incident'), {
				status: 'closed',
				revision: parseInt(btn.getAttribute('data-revision'), 10)
			}).then(function (json) {
				btn.disabled = false;
				if (json.success) { toast('ok', t('Incident closed')); setTimeout(function () { location.reload(); }, 600); }
				else { if (json._status === 409) { setTimeout(function () { location.reload(); }, 900); } handleError(null, json); }
			}).catch(function () { btn.disabled = false; toast('error', t('Network error')); });
		});
	});

	/* --- incidents: detail expand + corrective lifecycle ---
	   The status machine (open→action→verify→closed) lives in the API;
	   the panel lazy-loads actions on first expand and offers the
	   status-appropriate next step — never a dead end. */
	var corrStatusLabel = { open: t('Open'), done: t('Done'), verified: t('Verified') };

	function corrRow(c, canSupervise, myUid) {
		var li = document.createElement('li');
		li.className = 'tck-list__row';
		var main = document.createElement('span');
		main.className = 'tck-list__main';
		var title = document.createElement('span');
		title.className = 'tck-list__title';
		title.textContent = c.text;
		main.appendChild(title);
		var meta = [];
		if (c.assigneeUid) { meta.push(t('assigned to %s').replace('%s', c.assigneeUid)); }
		if (c.dueAt) { meta.push(t('due %s').replace('%s', String(c.dueAt).slice(0, 10))); }
		if (c.resolution) { meta.push(c.resolution); }
		if (meta.length) {
			var sub = document.createElement('span');
			sub.className = 'tck-list__sub';
			sub.textContent = meta.join(' · ');
			main.appendChild(sub);
		}
		li.appendChild(main);
		var aside = document.createElement('span');
		aside.className = 'tck-list__aside';
		var badge = document.createElement('span');
		badge.className = 'tck-badge';
		badge.textContent = corrStatusLabel[c.status] || c.status;
		aside.appendChild(badge);
		var act = null;
		if (c.status === 'open' && (canSupervise || (c.assigneeUid && c.assigneeUid === myUid))) {
			act = document.createElement('button');
			act.type = 'button';
			act.className = 'tck-btn';
			act.textContent = t('Mark done');
			act.addEventListener('click', function () {
				act.disabled = true;
				api('PUT', '/api/actions/' + c.id, { status: 'done', revision: c.revision }).then(function (json) {
					act.disabled = false;
					if (json.success) { toast('ok', t('Action marked done')); setTimeout(function () { location.reload(); }, 600); }
					else { if (json._status === 409) { setTimeout(function () { location.reload(); }, 900); } handleError(null, json); }
				}).catch(function () { act.disabled = false; toast('error', t('Network error')); });
			});
		} else if (c.status === 'done' && canSupervise) {
			act = document.createElement('button');
			act.type = 'button';
			act.className = 'tck-btn';
			act.textContent = t('Verify');
			act.addEventListener('click', function () {
				act.disabled = true;
				api('POST', '/api/actions/' + c.id + '/verify', {}).then(function (json) {
					act.disabled = false;
					if (json.success) { toast('ok', t('Action verified')); setTimeout(function () { location.reload(); }, 600); }
					else { if (json._status === 409) { setTimeout(function () { location.reload(); }, 900); } handleError(null, json); }
				}).catch(function () { act.disabled = false; toast('error', t('Network error')); });
			});
		}
		if (act) { aside.appendChild(act); }
		li.appendChild(aside);
		return li;
	}

	function loadIncidentDetail(panel) {
		var listEl = panel.querySelector('[data-tck-corr-list]');
		var id = panel.getAttribute('data-tck-incident-detail');
		var supervise = panel.getAttribute('data-supervise') === '1';
		var myUid = panel.getAttribute('data-uid') || '';
		api('GET', '/api/incidents/' + id).then(function (json) {
			if (!json.success) { corrLoadError(panel, listEl); return; }
			panel.setAttribute('data-loaded', '1');
			var actions = (json.data && json.data.actions) || [];
			listEl.innerHTML = '';
			if (!actions.length) {
				var none = document.createElement('p');
				none.className = 'tck-hint';
				none.textContent = t('No actions yet.');
				listEl.appendChild(none);
				return;
			}
			var ul = document.createElement('ul');
			ul.className = 'tck-list';
			actions.forEach(function (c) { ul.appendChild(corrRow(c, supervise, myUid)); });
			listEl.appendChild(ul);
		}).catch(function () { corrLoadError(panel, listEl); });
	}

	function corrLoadError(panel, listEl) {
		/* Failed load must offer a way forward — inline error + retry, not
		   a spinner forever. */
		listEl.innerHTML = '';
		var err = document.createElement('p');
		err.className = 'tck-field-error';
		err.hidden = false;
		err.textContent = t('Could not load actions.');
		var retry = document.createElement('button');
		retry.type = 'button';
		retry.className = 'tck-btn';
		retry.textContent = t('Retry');
		retry.addEventListener('click', function () { loadIncidentDetail(panel); });
		listEl.appendChild(err);
		listEl.appendChild(retry);
	}

	document.querySelectorAll('[data-tck-incident-toggle]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var panel = document.getElementById(btn.getAttribute('aria-controls'));
			if (!panel) { return; }
			var opening = panel.hidden;
			panel.hidden = !opening;
			btn.setAttribute('aria-expanded', opening ? 'true' : 'false');
			if (opening && !panel.getAttribute('data-loaded')) { loadIncidentDetail(panel); }
		});
	});
	document.querySelectorAll('[data-tck-corr-form]').forEach(function (form) {
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			submitForm(form, '/api/incidents/' + form.getAttribute('data-tck-corr-form') + '/actions');
		});
	});

	/* --- reports: generate --- */
	var repForm = document.querySelector('[data-tck-report-form]');
	var newRepBtn = document.querySelector('[data-tck-new-report]');
	if (newRepBtn && repForm) {
		var repSiteId = repForm.getAttribute('data-site-id');
		newRepBtn.addEventListener('click', function () {
			repForm.classList.toggle('tck-hidden');
			var input = repForm.querySelector('input[name="period"]');
			if (input && !repForm.classList.contains('tck-hidden')) { input.focus(); }
		});
		repForm.addEventListener('submit', function (e) {
			e.preventDefault();
			submitForm(repForm, '/api/sites/' + repSiteId + '/reports');
		});
	}

	/* --- reports: scheduled deliveries (MH-15) --- */
	var schedForm = document.querySelector('[data-tck-schedule-form]');
	var newSchedBtn = document.querySelector('[data-tck-new-schedule]');
	if (newSchedBtn && schedForm) {
		var schedSiteId = schedForm.getAttribute('data-site-id');
		newSchedBtn.addEventListener('click', function () {
			schedForm.classList.toggle('tck-hidden');
			var input = schedForm.querySelector('input[name="recipient"]');
			if (input && !schedForm.classList.contains('tck-hidden')) { input.focus(); }
		});
		schedForm.addEventListener('submit', function (e) {
			e.preventDefault();
			submitForm(schedForm, '/api/sites/' + schedSiteId + '/report-schedules', null, 'PUT');
		});
	}

	/* One-click enable/disable on existing schedules — upsert by natural
	   key, no separate delete flow needed (UX: a typo'd recipient can be
	   silenced; previously there was NO way to stop a schedule). */
	document.querySelectorAll('[data-tck-toggle-sched]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			btn.disabled = true;
			api('PUT', '/api/sites/' + btn.getAttribute('data-site-id') + '/report-schedules', {
				cadence: btn.getAttribute('data-cadence'),
				recipient: btn.getAttribute('data-recipient'),
				audience: btn.getAttribute('data-audience'),
				active: btn.getAttribute('data-active') !== '1',
			}).then(function (json) {
				btn.disabled = false;
				if (json.success) { toast('ok', t('Saved')); setTimeout(function () { location.reload(); }, 600); }
				else { handleError(null, json); }
			}).catch(function () { btn.disabled = false; toast('error', t('Network error')); });
		});
	});

	/* --- license: apply + remove --- */
	var licForm = document.querySelector('[data-tck-license]');
	if (licForm) {
		licForm.addEventListener('submit', function (e) {
			e.preventDefault();
			clearFieldErrors(licForm);
			var btn = licForm.querySelector('[type="submit"]');
			if (btn) { btn.disabled = true; }
			api('POST', '/api/admin/license', {
				license_key: licForm.querySelector('[name="license"]').value.trim()
			}).then(function (json) {
				if (btn) { btn.disabled = false; }
				if (json.success) { toast('ok', t('License applied')); setTimeout(function () { location.reload(); }, 600); }
				else { handleError(licForm, json); }
			}).catch(function () {
				if (btn) { btn.disabled = false; }
				toast('error', t('Network error'));
			});
		});
	}
	var clearLic = document.querySelector('[data-tck-clear-license]');
	if (clearLic) {
		clearLic.addEventListener('click', function () {
			if (!confirm(t('Remove this license? Stations and the companion app stop working.'))) { return; }
			api('DELETE', '/api/admin/license').then(function (json) {
				if (json.success) { toast('ok', t('License removed')); setTimeout(function () { location.reload(); }, 600); }
				else { handleError(null, json); }
			}).catch(function () { toast('error', t('Network error')); });
		});
	}

	/* --- uid/gid pickers: debounced search dropdown (combobox pattern) ---
	   Multi fields (access settings, comma-separated) append; single fields
	   (member uid) replace. Keyboard: ↓/↑ cycle, Enter pick, Esc close. */
	function attachPicker(input, path, multi) {
		var wrap = document.createElement('div');
		wrap.className = 'tck-picker-wrap';
		input.parentNode.insertBefore(wrap, input);
		wrap.appendChild(input);
		var box = document.createElement('ul');
		box.className = 'tck-picker';
		box.setAttribute('role', 'listbox');
		box.hidden = true;
		var boxId = input.id + '-picker';
		box.id = boxId;
		wrap.appendChild(box);
		input.setAttribute('role', 'combobox');
		input.setAttribute('aria-expanded', 'false');
		input.setAttribute('aria-controls', boxId);
		input.setAttribute('aria-autocomplete', 'list');

		var items = [];
		var active = -1;
		var timer = null;

		function lastToken() {
			var v = input.value;
			return multi ? v.slice(v.lastIndexOf(',') + 1).trim() : v.trim();
		}
		function close() {
			box.hidden = true;
			items = [];
			active = -1;
			input.setAttribute('aria-expanded', 'false');
			input.removeAttribute('aria-activedescendant');
		}
		function pick(i) {
			var val = items[i].id;
			if (multi) {
				var v = input.value;
				var prefix = v.slice(0, v.lastIndexOf(',') + 1);
				input.value = (prefix ? prefix + ' ' : '') + val + ', ';
			} else {
				input.value = val;
			}
			close();
			input.focus();
		}
		function render() {
			box.innerHTML = '';
			items.forEach(function (it, i) {
				var li = document.createElement('li');
				li.className = 'tck-picker__item';
				li.setAttribute('role', 'option');
				li.id = boxId + '-opt-' + i;
				if (i === active) { li.setAttribute('aria-selected', 'true'); }
				var name = document.createElement('span');
				name.className = 'tck-picker__name';
				name.textContent = it.display_name || it.id;
				var idEl = document.createElement('span');
				idEl.className = 'tck-picker__id';
				idEl.textContent = it.id;
				li.appendChild(name);
				li.appendChild(idEl);
				li.addEventListener('mousedown', function (e) { e.preventDefault(); pick(i); });
				box.appendChild(li);
			});
			box.hidden = items.length === 0;
			input.setAttribute('aria-expanded', items.length > 0 ? 'true' : 'false');
			if (active >= 0) {
				input.setAttribute('aria-activedescendant', boxId + '-opt-' + active);
			} else {
				input.removeAttribute('aria-activedescendant');
			}
		}
		function search() {
			var q = lastToken();
			if (q.length < 2) { close(); return; }
			api('GET', path + '?q=' + encodeURIComponent(q)).then(function (json) {
				if (lastToken() !== q) { return; } /* stale response — input moved on */
				items = (json && json.success && json.data ? json.data : []).map(function (o) {
					return { id: o.uid || o.gid || '', display_name: o.display_name || '' };
				});
				active = items.length ? 0 : -1;
				render();
			}).catch(function () { close(); });
		}
		input.addEventListener('input', function () {
			clearTimeout(timer);
			timer = setTimeout(search, 200);
		});
		input.addEventListener('keydown', function (e) {
			if (box.hidden) { return; }
			if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
				e.preventDefault();
				active = (active + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
				render();
			} else if (e.key === 'Enter' && active >= 0) {
				e.preventDefault();
				pick(active);
			} else if (e.key === 'Escape') {
				close();
			}
		});
		input.addEventListener('blur', function () { setTimeout(close, 150); });
	}
	document.querySelectorAll('[data-tck-uid-picker]').forEach(function (input) {
		attachPicker(input, '/api/admin/users/search', input.name !== 'uid');
	});
	document.querySelectorAll('[data-tck-gid-picker]').forEach(function (input) {
		attachPicker(input, '/api/admin/groups/search', true);
	});
})();
