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

	// calc:start -- Mirror of FormTheory_Calc (tokenizer + shunting-yard; no eval/Function).
	var CALC_PREC = { '+': 1, '-': 1, '*': 2, '/': 2, neg: 3 };
	function compileFormula(s) {
		s = String(s || '');
		if (!s.length || s.length > 500) return null;
		var out = [], ops = [], depth = 0, expect = true, i = 0, m;
		var numRe = /^(?:\d+(?:\.\d+)?|\.\d+)/, refRe = /^\{field:([a-z0-9_]+)\}/;
		while (i < s.length) {
			var c = s.charAt(i);
			if (c === ' ' || c === '\t' || c === '\n' || c === '\r') { i++; continue; }
			if (expect) {
				var rest = s.slice(i);
				if ((m = numRe.exec(rest))) { out.push(['num', parseFloat(m[0])]); i += m[0].length; expect = false; }
				else if ((m = refRe.exec(rest))) { out.push(['ref', m[1]]); i += m[0].length; expect = false; }
				else if (c === '(') { if (++depth > 50) return null; ops.push('('); i++; }
				else if (c === '-') { ops.push('neg'); i++; }
				else if (c === '+') { i++; }
				else return null;
				continue;
			}
			if (c === ')') {
				if (depth < 1) return null;
				while (ops[ops.length - 1] !== '(') out.push(['op', ops.pop()]);
				ops.pop(); depth--; i++;
				continue;
			}
			if (!Object.prototype.hasOwnProperty.call(CALC_PREC, c) || c === 'neg') return null;
			while (ops.length && ops[ops.length - 1] !== '(' && CALC_PREC[ops[ops.length - 1]] >= CALC_PREC[c]) out.push(['op', ops.pop()]);
			ops.push(c); expect = true; i++;
		}
		if (expect || depth) return null;
		while (ops.length) out.push(['op', ops.pop()]);
		return out;
	}
	function toNumber(v) {
		v = String(v == null ? '' : v).trim();
		return /^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/.test(v) ? parseFloat(v) : 0;
	}
	// getValue(id) returns the raw value of field id; non-numeric => 0. Null on syntax error / division by zero.
	function evaluateFormula(formula, getValue) {
		var rpn = compileFormula(formula);
		if (!rpn) return null;
		var st = [];
		for (var k = 0; k < rpn.length; k++) {
			var tk = rpn[k];
			if (tk[0] === 'num') st.push(tk[1]);
			else if (tk[0] === 'ref') st.push(toNumber(getValue(tk[1])));
			else if (tk[1] === 'neg') st.push(-st.pop());
			else {
				var b = st.pop(), a = st.pop();
				if (tk[1] === '+') st.push(a + b);
				else if (tk[1] === '-') st.push(a - b);
				else if (tk[1] === '*') st.push(a * b);
				else { if (b === 0) return null; st.push(a / b); }
			}
		}
		var r = st.pop();
		return isFinite(r) ? r : null;
	}
	// Mirror of FormTheory_Calc::format(): half away from zero, fixed places, no "-0".
	function formatCalc(v, d) {
		d = Math.max(0, Math.min(6, parseInt(d, 10) || 0));
		var p = Math.pow(10, d);
		var r = (v < 0 ? -1 : 1) * Math.round(Math.abs(v) * p * (1 + Number.EPSILON)) / p;
		if (r === 0) r = 0;
		return r.toFixed(d);
	}
	// calc:end

	// rep:start -- Pure repeater helpers (row re-indexing and limits); exercised from Node, no layout/global access.
	// Rename one attribute value from row index `from` to `to`: name `ft[ID][from]...`, id/for `PREFIX-from-...`, row label id `PREFIX-rfrom-...`.
	function repRename(val, prefix, name, from, to) {
		var pairs = [
			[name + '[' + from + ']', name + '[' + to + ']'],
			[prefix + '-' + from + '-', prefix + '-' + to + '-'],
			[prefix + '-r' + from + '-', prefix + '-r' + to + '-']
		];
		for (var i = 0; i < pairs.length; i++) {
			if (val.indexOf(pairs[i][0]) === 0) return pairs[i][1] + val.slice(pairs[i][0].length);
		}
		return val;
	}
	// Re-index a row element (and its descendants) from index `from` to `to`; DOM use limited to get/setAttribute + querySelectorAll.
	function repReindexRow(row, prefix, name, from, to) {
		var nodes = [row].concat(Array.prototype.slice.call(row.querySelectorAll('[name],[id],[for],[aria-labelledby],[aria-describedby]')));
		nodes.forEach(function (n) {
			['name', 'id', 'for', 'aria-labelledby', 'aria-describedby'].forEach(function (a) {
				var v = n.getAttribute(a);
				if (v != null) n.setAttribute(a, repRename(v, prefix, name, from, to));
			});
		});
		row.setAttribute('data-ft-rep-idx', String(to));
	}
	function repCanAdd(count, max) { return count < max; }
	function repCanRemove(count, min) { return count > Math.max(1, min); }
	// Rows a (possibly required) repeater needs: mirrors FormTheory_Frontend::validate_repeater().
	function repNeed(required, min) { return Math.max(required ? 1 : 0, min); }
	// After removing the row at `idx` leaving `count` rows: index of the row to focus, or -1 for the Add button.
	function repFocusAfterRemove(idx, count) { return idx < count ? idx : -1; }
	// rep:end

	// Recompute every calc output in field order; later/unknown calc refs read as 0 (matches the server).
	function updateCalcs(form) {
		var outs = Array.prototype.slice.call(form.querySelectorAll('output[data-ft-calc]'));
		var done = {};
		var calcIds = {};
		outs.forEach(function (o) { calcIds[o.closest('[data-ft-field]').getAttribute('data-ft-field')] = true; });
		outs.forEach(function (o) {
			var wrap = o.closest('[data-ft-field]');
			var id = wrap.getAttribute('data-ft-field');
			var r = evaluateFormula(o.getAttribute('data-ft-calc'), function (ref) {
				if (calcIds[ref]) return done[ref] || ''; // '' when hidden (set below).
				var v = fieldValue(form, ref);
				return v.length ? v[0] : '';
			});
			var val = r === null ? '' : formatCalc(r, o.getAttribute('data-ft-decimals'));
			done[id] = wrap.hidden ? '' : val; // Hidden calc feeds 0 into later formulas (server: compute() with $visible).
			o.setAttribute('data-ft-value', val);
			o.textContent = val === '' ? '' : (o.getAttribute('data-ft-prefix') || '') + val + (o.getAttribute('data-ft-suffix') || '');
		});
	}

	function fieldValue(form, id) {
		var wrap = form.querySelector('[data-ft-field="' + id + '"]');
		if (!wrap || wrap.hidden) return [];
		var calc = wrap.querySelector('output[data-ft-calc]');
		if (calc) { var cv = calc.getAttribute('data-ft-value') || ''; return cv === '' ? [] : [cv]; }
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

	// Type-specific format message for one non-empty value, or ''.
	function valueMsg(type, v) {
		if (type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) return t('invalid_email', 'Enter a valid email address.');
		if (type === 'number' && isNaN(v)) return t('invalid_number', 'Enter a number.');
		if (type === 'phone' && !/^[0-9 +().\-]{5,25}$/.test(v)) return t('invalid_phone', 'Enter a valid phone number.');
		return '';
	}

	// Basic mirror of the server repeater rules: row count, per-column required, per-cell email/number/phone. First problem wins.
	function repeaterMsg(wrap) {
		var fs = wrap.querySelector('[data-ft-repeater]');
		if (!fs) return '';
		var isReq = wrap.getAttribute('data-ft-required') === '1';
		var allRows = Array.prototype.slice.call(fs.querySelectorAll('[data-ft-rep-row]'));
		var filled = allRows.filter(function (r) {
			return Array.prototype.some.call(r.querySelectorAll('input, select, textarea'), function (c) { return c.value.trim() !== ''; });
		});
		if (!filled.length) return isReq ? t('required', 'This field is required.') : '';
		var need = repNeed(isReq, parseInt(fs.getAttribute('data-ft-min'), 10) || 0);
		if (filled.length < need) return need === 1 ? t('min_row', 'Add at least 1 row.') : fmt(t('min_rows', 'Add at least %d rows.'), need);
		var kinds = { email: 'email', number: 'number', tel: 'phone' };
		for (var i = 0; i < filled.length; i++) {
			var ctls = filled[i].querySelectorAll('input, select, textarea');
			for (var j = 0; j < ctls.length; j++) {
				var c = ctls[j], v = c.value.trim(), m = '';
				if (v === '') { if (c.getAttribute('aria-required') === 'true') m = t('required', 'This field is required.'); }
				else m = valueMsg(kinds[c.type] || '', v);
				if (m) {
					var lab = filled[i].querySelector('label[for="' + c.id + '"]');
					return fmt(t('row_error', 'Row %1$d, %2$s: %3$s'), allRows.indexOf(filled[i]) + 1, lab && lab.firstChild ? lab.firstChild.textContent.trim() : '', m);
				}
			}
		}
		return '';
	}

	function validateWrap(wrap) {
		if (wrap.hidden) { setError(wrap, ''); return true; }
		var type = wrap.getAttribute('data-ft-type');
		var vals = fieldValue(wrap.closest('form'), wrap.getAttribute('data-ft-field'));
		var msg = '';
		if (type === 'repeater') {
			msg = repeaterMsg(wrap);
		} else if (type === 'address') {
			// Mirror of the server rule: required = every part marked aria-required (all enabled parts but street2) filled.
			var isReq = wrap.getAttribute('data-ft-required') === '1';
			var anyFilled = vals.some(function (v) { return v.trim() !== ''; });
			if (!anyFilled) {
				if (isReq) msg = t('required', 'This field is required.');
			} else if (isReq && Array.prototype.some.call(wrap.querySelectorAll('input[aria-required="true"]'), function (el) { return el.value.trim() === ''; })) {
				msg = t('complete_address', 'Please complete the address.');
			}
		} else if (!vals.length) {
			if (wrap.getAttribute('data-ft-required') === '1') msg = type === 'file' ? t('attach_file', 'Please attach a file.') : t('required', 'This field is required.');
		} else if (valueMsg(type, vals[0])) {
			msg = valueMsg(type, vals[0]);
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

	// Progressive enhancement: reveals Add/Remove, clones the server-rendered <template>, keeps indexes contiguous.
	function initRepeater(fs) {
		var rowsBox = fs.querySelector('[data-ft-rep-rows]');
		var tpl = fs.querySelector('template[data-ft-rep-template]');
		var addBtn = fs.querySelector('[data-ft-rep-add]');
		var maxMsg = fs.querySelector('[data-ft-rep-max]');
		var live = fs.querySelector('[data-ft-rep-live]');
		if (!rowsBox || !tpl || !tpl.content || !tpl.content.firstElementChild || !addBtn || !maxMsg || !live) return; // No template support: rows stay fixed.
		var prefix = fs.getAttribute('data-ft-rep-prefix');
		var name = fs.getAttribute('data-ft-rep-name');
		var min = parseInt(fs.getAttribute('data-ft-min'), 10) || 0;
		var max = parseInt(fs.getAttribute('data-ft-max'), 10) || 1;

		function rows() { return Array.prototype.slice.call(rowsBox.querySelectorAll(':scope > [data-ft-rep-row]')); }
		function say(msg) { live.textContent = ''; setTimeout(function () { live.textContent = msg; }, 30); }
		function focusFirst(row) { var c = row.querySelector('input, select, textarea'); if (c) c.focus(); }
		function sync() {
			var rs = rows(), n = rs.length;
			rs.forEach(function (r, i) {
				var cur = r.getAttribute('data-ft-rep-idx');
				if (cur !== String(i)) repReindexRow(r, prefix, name, cur, i);
				r.querySelector('[data-ft-rep-title]').textContent = fmt(t('row_n', 'Row %d'), i + 1);
				var rb = r.querySelector('[data-ft-rep-remove]');
				rb.hidden = false;
				rb.setAttribute('aria-label', fmt(t('remove_row_n', 'Remove row %d'), i + 1));
				if (repCanRemove(n, min)) rb.removeAttribute('aria-disabled'); else rb.setAttribute('aria-disabled', 'true');
			});
			var full = !repCanAdd(n, max);
			addBtn.hidden = false;
			if (full) { addBtn.setAttribute('aria-disabled', 'true'); addBtn.setAttribute('aria-describedby', maxMsg.id); }
			else { addBtn.removeAttribute('aria-disabled'); addBtn.removeAttribute('aria-describedby'); }
			maxMsg.hidden = !full;
		}

		fs.addEventListener('click', function (e) {
			var rm = e.target.closest('[data-ft-rep-remove]');
			if (rm) {
				if (rm.getAttribute('aria-disabled') === 'true') return;
				var row = rm.closest('[data-ft-rep-row]');
				var idx = rows().indexOf(row);
				row.parentNode.removeChild(row);
				sync();
				var rs = rows(), j = repFocusAfterRemove(idx, rs.length);
				if (j >= 0) focusFirst(rs[j]); else addBtn.focus();
				say(t('row_removed', 'Row removed'));
				return;
			}
			if (e.target.closest('[data-ft-rep-add]')) {
				if (addBtn.getAttribute('aria-disabled') === 'true') return;
				var fresh = tpl.content.firstElementChild.cloneNode(true);
				rowsBox.appendChild(fresh);
				repReindexRow(fresh, prefix, name, '__ROW__', rows().length - 1);
				sync();
				focusFirst(fresh);
				say(t('row_added', 'Row added'));
			}
		});
		sync();
	}

	function init(form) {
		form.querySelectorAll('[data-ft-repeater]').forEach(initRepeater);
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

		function refresh() { updateCalcs(form); applyConditions(form); updateCalcs(form); }
		refresh();
		form.addEventListener('input', refresh);
		form.addEventListener('change', function (e) {
			refresh();
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
