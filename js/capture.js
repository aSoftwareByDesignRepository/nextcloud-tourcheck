/* TourCheck worker capture — scan-first flow (UX-2, UX-3, UX-6). */
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
	function route(path) { return OC.generateUrl('/apps/' + appId + path); }
	function headers() {
		return { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken || '' };
	}
	function api(method, path, body) {
		return fetch(route(path), { method: method, headers: headers(), credentials: 'same-origin', body: body === undefined ? undefined : JSON.stringify(body) })
			.then(function (r) { return r.json(); });
	}
	function announce(text) {
		var r = document.getElementById('tck-alert-region');
		if (r) { r.textContent = ''; setTimeout(function () { r.textContent = text; }, 30); }
	}

	var scanInput = document.querySelector('[data-tck-scan-input]');
	var confirmBox = document.querySelector('[data-tck-confirm]');
	var confirmBtn = document.querySelector('[data-tck-confirm-btn]');
	var cpName = document.querySelector('[data-tck-cpname]');
	var reject = document.querySelector('[data-tck-reject]');
	var corrective = document.querySelector('[data-tck-corrective]');
	var progress = document.querySelector('[data-tck-progress]');
	var scanErr = document.getElementById('tck-scan-error');
	var pendingToken = null;

	var siteId = null;
	var progressEl = document.querySelector('[data-tck-progress]');
	(function () {
		var el = document.querySelector('[data-tck-site-id]');
		if (el) { siteId = parseInt(el.getAttribute('data-tck-site-id'), 10); }
	})();

	function showReject(msg) {
		reject.textContent = msg;
		reject.classList.remove('tck-hidden');
		corrective.innerHTML = '';
		/* UX-1: every rejection offers the corrective action inline — never a
		   bare error. With a known site we notify its supervisors; without
		   one (multi-site worker, no active round) the fallback is a way
		   forward, not a dead end. */
		if (siteId) {
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'tck-btn';
			btn.textContent = t('Ask a supervisor');
			btn.addEventListener('click', function () {
				btn.disabled = true;
				api('POST', '/api/capture/signal', { site_id: siteId, signal: 'unknown_token' }).then(function (j) {
					btn.disabled = false;
					if (j.success) { corrective.classList.add('tck-hidden'); reject.classList.add('tck-hidden'); announce(t('Supervisor notified')); }
					else { reject.textContent = j.message || msg; }
				}).catch(function () { btn.disabled = false; reject.textContent = t('Network error'); });
			});
			corrective.appendChild(btn);
			btn.focus();
		} else {
			var link = document.createElement('a');
			link.className = 'tck-btn';
			link.href = route('/rounds');
			link.textContent = t('Go to today’s rounds');
			corrective.appendChild(link);
			link.focus();
		}
		corrective.classList.remove('tck-hidden');
	}
	function clearReject() {
		reject.classList.add('tck-hidden');
		corrective.classList.add('tck-hidden');
		if (scanErr) { scanErr.hidden = true; }
	}

	if (scanInput) {
		scanInput.addEventListener('keydown', function (e) {
			if (e.key !== 'Enter') { return; }
			e.preventDefault();
			var token = scanInput.value.trim();
			if (!token) { return; }
			clearReject();
			api('POST', '/api/capture/resolve', { token: token }).then(function (j) {
				if (j.success) {
					pendingToken = token;
					cpName.textContent = j.data.checkpointName;
					confirmBox.classList.remove('tck-hidden');
					confirmBtn.focus();
				} else {
					scanInput.value = '';
					showReject(j.message || t('Unknown code'));
				}
			}).catch(function () { showReject(t('Network error')); });
		});
	}
	if (confirmBtn) {
		confirmBtn.addEventListener('click', function () {
			if (!pendingToken) { return; }
			confirmBtn.disabled = true;
			api('POST', '/api/capture/confirm', { token: pendingToken }).then(function (j) {
				confirmBtn.disabled = false;
				if (j.success) {
					confirmBox.classList.add('tck-hidden');
					scanInput.value = '';
					pendingToken = null;
					if (progress && j.data.progress) { progress.textContent = j.data.progress; }
					announce(j.data.progress || t('Checkpoint confirmed'));
					scanInput.focus();
				} else {
					confirmBox.classList.add('tck-hidden');
					pendingToken = null;
					showReject(j.message || t('Not confirmed'));
				}
			}).catch(function () {
				/* Network drop: keep the confirm visible + re-enable so the
				   worker can retry — the token is still pending. */
				confirmBtn.disabled = false;
				showReject(t('Network error'));
			});
		});
	}
	if (scanInput && scanInput.value.trim()) {
		scanInput.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
	}
	document.querySelectorAll('[data-tck-close-round]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			api('POST', '/api/capture/close', { round_id: parseInt(btn.getAttribute('data-tck-close-round'), 10) }).then(function (j) {
				if (j.success) { announce(t('Round closed')); location.reload(); }
				else { showReject(j.message || t('Cannot close'), null); }
			}).catch(function () { showReject(t('Network error')); });
		});
	});
})();
