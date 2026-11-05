(function () {
	'use strict';

	// Strings come from wp_localize_script (FormTheoryI18n); English literals are the fallback.
	var I18N = window.FormTheoryI18n || {};
	function t(key, fallback) { return I18N[key] || fallback; }
	function fmt(str) {
		var args = Array.prototype.slice.call(arguments, 1);
		var n = 0;
		return str.replace(/%(?:(\d+)\$)?[sd]/g, function (m, pos) { return args[pos ? pos - 1 : n++]; });
	}

	function fieldValue(form, id) {
		var wrap = form.querySelector('[data-ft-field="' + id + '"]');
		if (!wrap || wrap.hidden) return [];
		var inputs = wrap.querySelectorAll('input, select, textarea');
		var out = [];
		inputs.forEach(function (el) {
			if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) return;
			if (el.type === 'file') { if (el.files && el.files.length) out.push(el.files[0].name); return; }
			if (el.value !== '') out.push(el.value);
		});
		return out;
	}

	// Mirror of FormTheory_Fields::rule_matches().
	function ruleMatches(rule, vals) {
		var t = String(rule.value);
		switch (rule.op) {
			case 'is': return vals.indexOf(t) !== -1;
			case 'isnot': return vals.indexOf(t) === -1;
			case 'contains': return t !== '' && vals.some(function (v) { return v.toLowerCase().indexOf(t.toLowerCase()) !== -1; });
			case 'gt': return vals.length > 0 && vals[0] !== '' && !isNaN(vals[0]) && parseFloat(vals[0]) > parseFloat(t);
			case 'lt': return vals.length > 0 && vals[0] !== '' && !isNaN(vals[0]) && parseFloat(vals[0]) < parseFloat(t);
			case 'empty': return vals.length === 0;
			case 'notempty': return vals.length > 0;
		}
		return false;
	}

	function applyConditions(form) {
		var wraps = form.querySelectorAll('[data-ft-cond]');
		for (var pass = 0; pass <= wraps.length; pass++) {
			var changed = false;
			wraps.forEach(function (w) {
				var c = JSON.parse(w.getAttribute('data-ft-cond'));
				var hits = c.rules.map(function (r) { return ruleMatches(r, fieldValue(form, r.field)); });
				var match = c.logic === 'any' ? hits.indexOf(true) !== -1 : hits.indexOf(false) === -1;
				var show = c.action === 'show' ? match : !match;
				if (w.hidden === show) {
					w.hidden = !show;
					w.querySelectorAll('input, select, textarea').forEach(function (el) { el.disabled = !show; });
					changed = true;
				}
			});
			if (!changed) break;
		}
	}

	function setError(wrap, msg) {
		var err = wrap.querySelector('.ft-error');
		var ctl = wrap.getAttribute('data-ft-type') === 'consent' ? wrap.querySelector('input') : (wrap.querySelector('fieldset') || wrap.querySelector('input, select, textarea'));
		err.textContent = msg;
		err.hidden = !msg;
		wrap.classList.toggle('ft-has-error', !!msg);
		if (!ctl) return;
		var ids = (ctl.getAttribute('aria-describedby') || '').split(' ').filter(function (x) { return x && x !== err.id; });
		if (msg) { ctl.setAttribute('aria-invalid', 'true'); ids.push(err.id); }
		else ctl.removeAttribute('aria-invalid');
		if (ids.length) ctl.setAttribute('aria-describedby', ids.join(' ')); else ctl.removeAttribute('aria-describedby');
	}

	function validateWrap(wrap) {
		if (wrap.hidden) { setError(wrap, ''); return true; }
		var type = wrap.getAttribute('data-ft-type');
		var vals = fieldValue(wrap.closest('form'), wrap.getAttribute('data-ft-field'));
		var msg = '';
		if (!vals.length) {
			if (wrap.getAttribute('data-ft-required') === '1') msg = type === 'file' ? t('attach_file', 'Please attach a file.') : t('required', 'This field is required.');
		} else if (type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(vals[0])) {
			msg = t('invalid_email', 'Enter a valid email address.');
		} else if (type === 'number' && isNaN(vals[0])) {
			msg = t('invalid_number', 'Enter a number.');
		} else if (type === 'phone' && !/^[0-9 +().\-]{5,25}$/.test(vals[0])) {
			msg = t('invalid_phone', 'Enter a valid phone number.');
		} else if (type === 'file') {
			var input = wrap.querySelector('input[type=file]');
			var max = parseInt(input.getAttribute('data-ft-maxmb'), 10) || 0;
			if (max && input.files[0].size > max * 1048576) msg = fmt(t('file_too_big', 'File must be under %d MB.'), max);
		}
		setError(wrap, msg);
		return !msg;
	}

	function validatePage(page) {
		var first = null;
		page.querySelectorAll('[data-ft-field]').forEach(function (w) {
			if (!validateWrap(w) && !first) first = w;
		});
		if (first) {
			var el = first.querySelector('input:not([type=hidden]), select, textarea');
			if (el) el.focus();
		}
		return !first;
	}

	function init(form) {
		var pages = Array.prototype.slice.call(form.querySelectorAll('[data-ft-page]'));
		var progress = form.querySelector('[data-ft-progress]');
		var current = 0;

		function show(i, focus) {
			current = i;
			pages.forEach(function (p, n) { p.hidden = n !== i; });
			if (progress) progress.textContent = fmt(t('step_of', 'Step %1$d of %2$d'), i + 1, pages.length);
			if (focus) {
				var target = pages[i].querySelector('.ft-page-title, input:not([type=hidden]), select, textarea');
				if (target) { if (target.classList.contains('ft-page-title')) target.tabIndex = -1; target.focus(); }
			}
		}

		// Server errors: open first page containing one.
		var errPage = pages.findIndex(function (p) { return p.querySelector('.ft-has-error'); });
		if (pages.length > 1) show(errPage > -1 ? errPage : 0, false);
		var summary = form.querySelector('.ft-error-summary');
		if (summary) summary.focus();

		applyConditions(form);
		form.addEventListener('input', function () { applyConditions(form); });
		form.addEventListener('change', function (e) {
			applyConditions(form);
			var w = e.target.closest('[data-ft-field]');
			if (w && w.classList.contains('ft-has-error')) validateWrap(w);
		});

		form.addEventListener('click', function (e) {
			var go = e.target.closest('[data-ft-goto]');
			if (go) {
				e.preventDefault();
				var w = form.querySelector('[data-ft-field="' + go.getAttribute('data-ft-goto') + '"]');
				if (!w) return;
				var pi = pages.findIndex(function (pg) { return pg.contains(w); });
				if (pages.length > 1 && pi > -1) show(pi, false);
				var c = w.querySelector('input:not([type=hidden]), select, textarea');
				if (c) c.focus();
				return;
			}
			if (e.target.closest('[data-ft-next]')) {
				if (validatePage(pages[current])) show(current + 1, true);
			} else if (e.target.closest('[data-ft-prev]')) {
				show(current - 1, true);
			}
		});

		function showServerErrors(errors) {
			var summary = form.querySelector('.ft-error-summary');
			if (!summary) {
				summary = document.createElement('div');
				summary.className = 'ft-error-summary';
				summary.setAttribute('role', 'alert');
				summary.tabIndex = -1;
				form.insertBefore(summary, form.querySelector('[data-ft-progress], [data-ft-page]'));
			}
			summary.textContent = '';
			var p = document.createElement('p');
			p.textContent = errors._form || t('fix_errors', 'Please fix the errors below.');
			summary.appendChild(p);
			var list = document.createElement('ul');
			form.querySelectorAll('[data-ft-field]').forEach(function (w) {
				var m = errors[w.getAttribute('data-ft-field')];
				if (!m) return;
				var lab = w.querySelector('legend, label');
				var li = document.createElement('li');
				var a = document.createElement('a');
				a.href = '#' + w.id;
				a.setAttribute('data-ft-goto', w.getAttribute('data-ft-field'));
				a.textContent = (lab ? lab.firstChild.textContent.trim() + ': ' : '') + m;
				li.appendChild(a);
				list.appendChild(li);
			});
			if (list.childNodes.length) summary.appendChild(list);
			var firstWrap = null;
			form.querySelectorAll('[data-ft-field]').forEach(function (w) {
				var msg = errors[w.getAttribute('data-ft-field')] || '';
				setError(w, msg);
				if (msg && !firstWrap) firstWrap = w;
			});
			if (firstWrap) {
				var pi = pages.findIndex(function (pg) { return pg.contains(firstWrap); });
				if (pages.length > 1 && pi > -1) show(pi, false);
				var ctl = firstWrap.querySelector('input:not([type=hidden]), select, textarea');
				if (ctl) { ctl.focus(); return; }
			}
			summary.focus();
		}

		function ajaxSubmit(btn) {
			var data = new FormData(form);
			data.append('action', 'formtheory_submit');
			form.setAttribute('aria-busy', 'true');
			fetch(form.getAttribute('data-ft-ajax'), { method: 'POST', body: data, credentials: 'same-origin' })
				.then(function (r) {
					return r.json().catch(function () { return { ok: false, unparsable: true, errors: { _form: t('went_wrong', 'Something went wrong. Please reload the page before submitting again.') } }; });
				})
				.then(function (res) {
					form.removeAttribute('aria-busy');
					if (res.ok && res.redirect) { window.location.assign(res.redirect); return; }
					if (res.ok) {
						var conf = document.createElement('div');
						conf.id = form.id;
						conf.className = 'ft-confirmation';
						conf.setAttribute('role', 'status');
						conf.tabIndex = -1;
						conf.innerHTML = res.message || ''; // Server-sanitized via wp_kses_post.
						form.parentNode.replaceChild(conf, form);
						conf.focus();
						return;
					}
					if (btn) btn.disabled = false;
					var rcv3 = form.querySelector('[data-ft-recaptcha-v3]');
					if (rcv3) rcv3.value = ''; // v3 tokens are single use.
					if (window.turnstile) { try { window.turnstile.reset(); } catch (e) { /* no widget */ } }
					showServerErrors(res.errors || {});
				})
				.catch(function () {
					// Network failure (request never answered): fall back to a normal POST.
					form.removeAttribute('aria-busy');
					form.removeAttribute('data-ft-ajax');
					HTMLFormElement.prototype.submit.call(form);
				});
		}

		form.addEventListener('submit', function (e) {
			var ok = true;
			for (var i = 0; i < pages.length; i++) {
				if (!validatePage(pages[i])) { if (pages.length > 1) show(i, false); validatePage(pages[i]); ok = false; break; }
			}
			if (!ok) { e.preventDefault(); return; }
			var btn = form.querySelector('.ft-submit');
			var rc = form.querySelector('[data-ft-recaptcha-v3]');
			if (rc && !rc.value && window.grecaptcha) {
				// v3: fetch a fresh token, then submit for real (form.submit() skips this handler).
				e.preventDefault();
				if (btn) btn.disabled = true;
				window.grecaptcha.ready(function () {
					window.grecaptcha.execute(rc.getAttribute('data-ft-recaptcha-v3'), { action: rc.getAttribute('data-ft-recaptcha-action') }).then(function (token) {
						rc.value = token;
						if (form.getAttribute('data-ft-ajax') && window.fetch && window.FormData) ajaxSubmit(btn); else form.submit();
					}, function () {
						if (btn) btn.disabled = false;
					});
				});
				return;
			}
			if (btn) btn.disabled = true;
			if (form.getAttribute('data-ft-ajax') && window.fetch && window.FormData) {
				e.preventDefault();
				ajaxSubmit(btn);
			}
		});
	}

	function boot() {
		document.querySelectorAll('[data-ft-form]').forEach(init);
		var conf = document.querySelector('.ft-confirmation');
		if (conf) conf.focus();
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
