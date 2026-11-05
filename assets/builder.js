(function () {
	'use strict';

	var root = document.getElementById('ft-builder');
	var store = document.getElementById('ft-config');
	var form = document.getElementById('ft-builder-form');
	if (!root || !store || !form) return;

	var cfg = JSON.parse(store.value || '{}');
	cfg.fields = cfg.fields || [];
	var TYPES = JSON.parse(root.getAttribute('data-types'));
	// Strings come from wp_localize_script (FormTheoryBuilderI18n); English literals are the fallback.
	var I18N = window.FormTheoryBuilderI18n || {};
	function t(key, fallback) { return I18N[key] || fallback; }
	function fmt(str) {
		var args = Array.prototype.slice.call(arguments, 1);
		var n = 0;
		return str.replace(/%(?:(\d+)\$)?[sd]/g, function (m, pos) { return args[pos ? pos - 1 : n++]; });
	}
	// Split a format string on %N$s placeholders and fill each with a node (for sentences containing controls).
	function sentence(str, nodes) {
		var out = [];
		str.split(/(%\d+\$s|%s)/).forEach(function (part) {
			var m = /^%(?:(\d+)\$)?s$/.exec(part);
			if (m) out.push(nodes[m[1] ? m[1] - 1 : 0]); else if (part) out.push(part);
		});
		return out;
	}
	var LABELS = Object.assign({ text: 'Text', email: 'Email', phone: 'Phone', number: 'Number', date: 'Date', textarea: 'Paragraph', select: 'Dropdown', radio: 'Radio buttons', checkbox: 'Checkboxes', file: 'File upload', page: 'Page break', hidden: 'Hidden', consent: 'Consent', html: 'HTML block', calc: 'Calculation' }, I18N.types || {});
	var OPS = Object.assign({ is: 'is', isnot: 'is not', contains: 'contains', gt: 'greater than', lt: 'less than', empty: 'is empty', notempty: 'is not empty' }, I18N.ops || {});
	var uid = 0;

	function nextId() {
		var max = 0;
		cfg.fields.forEach(function (f) { var n = parseInt(String(f.id).replace(/\D/g, ''), 10); if (n > max) max = n; });
		return 'f' + (Math.max(max, uid) + 1);
	}

	function el(tag, attrs, kids) {
		var n = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if (attrs[k] == null) return;
			if (k === 'text') n.textContent = attrs[k];
			else if (k.slice(0, 2) === 'on') n.addEventListener(k.slice(2), attrs[k]);
			else if (k === 'checked' || k === 'value' || k === 'hidden') n[k] = attrs[k];
			else n.setAttribute(k, attrs[k]);
		});
		(kids || []).forEach(function (c) { if (c) n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
		return n;
	}

	function labelled(text, input) {
		var id = 'ftb-' + (++uid);
		input.id = id;
		return el('p', { class: 'ftb-row' }, [el('label', { for: id, text: text }), input]);
	}

	function check(text, val, onchange) {
		var id = 'ftb-' + (++uid);
		return el('p', { class: 'ftb-row ftb-check' }, [
			el('input', { type: 'checkbox', id: id, checked: !!val, onchange: function (e) { onchange(e.target.checked); } }),
			el('label', { for: id, text: text })
		]);
	}

	function textIn(val, oninput, type) {
		return el('input', { type: type || 'text', class: 'regular-text', autocomplete: type === 'password' ? 'new-password' : null, value: val == null ? '' : String(val), oninput: function (e) { oninput(e.target.value); } });
	}

	function select(opts, val, onchange) {
		var s = el('select', { onchange: function (e) { onchange(e.target.value); } });
		Object.keys(opts).forEach(function (k) { s.appendChild(el('option', { value: k, text: opts[k] })); });
		s.value = val;
		return s;
	}

	var live = document.createElement('div');
	live.id = 'ftb-live';
	live.className = 'screen-reader-text';
	live.setAttribute('role', 'status');
	live.setAttribute('aria-live', 'polite');
	root.parentNode.insertBefore(live, root.nextSibling);

	function announce(msg) {
		live.textContent = '';
		setTimeout(function () { live.textContent = msg; }, 30);
	}

	function move(i, d) {
		var j = i + d;
		if (j < 0 || j >= cfg.fields.length) return;
		var tmp = cfg.fields[i]; cfg.fields[i] = cfg.fields[j]; cfg.fields[j] = tmp;
		render(cfg.fields[j].id, d < 0 ? 'up' : 'down');
		announce(fmt(t('moved_to', 'Moved to position %d'), j + 1));
	}

	function condEditor(f) {
		f.cond = f.cond || { enabled: false, action: 'show', logic: 'all', rules: [] };
		var box = el('div', { class: 'ftb-cond' });
		var others = {};
		cfg.fields.forEach(function (o) { if (o.id !== f.id && o.type !== 'page' && o.type !== 'html') others[o.id] = (o.label || t('no_label', '(no label)')) + ' [' + o.id + ']'; });

		function draw() {
			box.textContent = '';
			box.appendChild(check(t('enable_cond', 'Enable conditional logic'), f.cond.enabled, function (v) {
				f.cond.enabled = v;
				if (v && !f.cond.rules.length && Object.keys(others).length) f.cond.rules.push({ field: Object.keys(others)[0], op: 'is', value: '' });
				draw(); refocus(box, 'toggle');
			}));
			if (!f.cond.enabled) return;
			if (!Object.keys(others).length) { box.appendChild(el('p', { text: t('add_fields_first', 'Add other fields first.') })); return; }
				box.appendChild(el('p', { class: 'ftb-inline' }, sentence(t('cond_sentence', '%1$s this field if %2$s of these match:'), [
				select({ show: t('show', 'Show'), hide: t('hide', 'Hide') }, f.cond.action, function (v) { f.cond.action = v; }),
				select({ all: t('all', 'all'), any: t('any', 'any') }, f.cond.logic, function (v) { f.cond.logic = v; })
			])));
			f.cond.rules.forEach(function (r, i) {
				var val = textIn(r.value, function (v) { r.value = v; });
				val.className = '';
				val.setAttribute('aria-label', t('value', 'Value'));
				val.hidden = r.op === 'empty' || r.op === 'notempty';
				var fs = select(others, r.field, function (v) { r.field = v; });
				fs.setAttribute('aria-label', t('field', 'Field'));
				var os = select(OPS, r.op, function (v) { r.op = v; val.hidden = v === 'empty' || v === 'notempty'; });
				os.setAttribute('aria-label', t('operator', 'Operator'));
				box.appendChild(el('p', { class: 'ftb-inline' }, [fs, ' ', os, ' ', val, ' ',
					el('button', { type: 'button', class: 'button-link ftb-danger', 'aria-label': fmt(t('remove_rule', 'Remove rule %d'), i + 1), onclick: function () { f.cond.rules.splice(i, 1); draw(); refocus(box, 'add'); } }, [t('remove', 'Remove')])
				]));
			});
			box.appendChild(el('button', { type: 'button', class: 'button', text: t('add_rule', 'Add rule'), onclick: function () {
				f.cond.rules.push({ field: Object.keys(others)[0], op: 'is', value: '' }); draw(); refocus(box, 'lastrule');
			} }));
		}
		draw();
		return box;
	}

	// Redrawing a panel destroys the focused control; put focus back (WCAG 2.4.3).
	function refocus(box, where) {
		var target = null;
		if (where === 'toggle') target = box.querySelector('input[type=checkbox]');
		else if (where === 'add') target = box.querySelector(':scope > button');
		else if (where === 'lastrule') { var rows = box.querySelectorAll('.ftb-inline'); target = rows.length ? rows[rows.length - 1].querySelector('select') : null; }
		if (target) target.focus();
	}

	var TAG_HINT = fmt(t('merge_tags', 'Merge tags: %s'), '{field:ID}, {all_fields}, {form_title}, {entry_id}, {date}, {site_name}, {admin_email}');

	function newCond() { return { enabled: false, action: 'show', logic: 'all', rules: [] }; }

	// Shared rules editor for notifications/webhooks: "only when all/any of these match".
	function ruleEditor(item, labelText) {
		item.cond = item.cond || newCond();
		var box = el('div', { class: 'ftb-cond' });
		var others = {};
		cfg.fields.forEach(function (o) { if (o.type !== 'page' && o.type !== 'html') others[o.id] = (o.label || t('no_label', '(no label)')) + ' [' + o.id + ']'; });
		function draw() {
			box.textContent = '';
			box.appendChild(check(labelText, item.cond.enabled, function (v) {
				item.cond.enabled = v;
				if (v && !item.cond.rules.length && Object.keys(others).length) item.cond.rules.push({ field: Object.keys(others)[0], op: 'is', value: '' });
				draw(); refocus(box, 'toggle');
			}));
			if (!item.cond.enabled || !Object.keys(others).length) return;
			box.appendChild(el('p', { class: 'ftb-inline' }, sentence(t('when_sentence', 'When %s of these match:'), [select({ all: t('all', 'all'), any: t('any', 'any') }, item.cond.logic, function (v) { item.cond.logic = v; })])));
			item.cond.rules.forEach(function (r, i) {
				var val = textIn(r.value, function (v) { r.value = v; });
				val.className = ''; val.setAttribute('aria-label', t('value', 'Value'));
				val.hidden = r.op === 'empty' || r.op === 'notempty';
				var fs = select(others, r.field, function (v) { r.field = v; }); fs.setAttribute('aria-label', t('field', 'Field'));
				var os = select(OPS, r.op, function (v) { r.op = v; val.hidden = v === 'empty' || v === 'notempty'; }); os.setAttribute('aria-label', t('operator', 'Operator'));
				box.appendChild(el('p', { class: 'ftb-inline' }, [fs, ' ', os, ' ', val, ' ',
					el('button', { type: 'button', class: 'button-link ftb-danger', 'aria-label': fmt(t('remove_rule', 'Remove rule %d'), i + 1), onclick: function () { item.cond.rules.splice(i, 1); draw(); refocus(box, 'add'); } }, [t('remove', 'Remove')])]));
			});
			box.appendChild(el('button', { type: 'button', class: 'button', text: t('add_rule', 'Add rule'), onclick: function () { item.cond.rules.push({ field: Object.keys(others)[0], op: 'is', value: '' }); draw(); refocus(box, 'lastrule'); } }));
		}
		draw();
		return box;
	}

	// Repeater panel: list of objects in cfg[key], each drawn by drawItem(item, body).
	function repeater(L, key, make, drawItem) {
		cfg[key] = cfg[key] || [];
		var fs = el('fieldset', { class: 'ftb-settings' }, [el('legend', { text: L.title })]);
		function draw() {
			while (fs.childNodes.length > 1) fs.removeChild(fs.lastChild);
			cfg[key].forEach(function (item, i) {
				var body = el('div', { class: 'ftb-body' });
				drawItem(item, body);
				body.appendChild(el('button', { type: 'button', class: 'button ftb-danger', text: t('remove', 'Remove'), 'aria-label': fmt(L.removeN, i + 1), onclick: function () { cfg[key].splice(i, 1); draw(); fs.lastChild.focus(); announce(L.removed); } }));
				var d = el('details', { class: 'ftb-card' }, [el('summary', { text: fmt(L.itemN, i + 1) }), body]);
				d.open = true;
				fs.appendChild(d);
			});
			fs.appendChild(el('button', { type: 'button', class: 'button', text: L.add, onclick: function () { cfg[key].push(make()); draw(); var sm = fs.querySelectorAll('summary'); if (sm.length) sm[sm.length - 1].focus(); announce(L.added); } }));
		}
		draw();
		return fs;
	}

	function notificationsEditor() {
		return repeater({ title: t('notifications', 'Notifications'), itemN: t('notification_n', 'Notification %d'), removeN: t('remove_notif_n', 'Remove notification %d'), removed: t('notif_removed', 'Notification removed'), add: t('add_notif', 'Add notification'), added: t('notif_added', 'Notification added') }, 'notifications', function () {
			return { id: 'n' + Date.now(), to: '{admin_email}', subject: t('default_subject', 'New submission: {form_title}'), body: '{all_fields}', reply_to: '', attach_files: false, cond: newCond() };
		}, function (n, body) {
			body.appendChild(el('p', { class: 'description', text: TAG_HINT }));
			body.appendChild(labelled(t('send_to', 'Send to (emails or {field:ID}, comma separated)'), textIn(n.to, function (v) { n.to = v; })));
			body.appendChild(labelled(t('subject', 'Subject'), textIn(n.subject, function (v) { n.subject = v; })));
			var ta = el('textarea', { rows: 4, class: 'large-text', oninput: function (e) { n.body = e.target.value; } });
			ta.value = n.body || '';
			body.appendChild(labelled(t('message', 'Message'), ta));
			body.appendChild(labelled(t('reply_to', 'Reply-To (email or {field:ID})'), textIn(n.reply_to, function (v) { n.reply_to = v; })));
			body.appendChild(check(t('attach_files', 'Attach uploaded files'), n.attach_files, function (v) { n.attach_files = v; }));
			body.appendChild(ruleEditor(n, t('only_send_when', 'Only send when conditions match')));
		});
	}

	function webhooksEditor() {
		return repeater({ title: t('webhooks', 'Webhooks'), itemN: t('webhook_n', 'Webhook %d'), removeN: t('remove_hook_n', 'Remove webhook %d'), removed: t('hook_removed', 'Webhook removed'), add: t('add_hook', 'Add webhook'), added: t('hook_added', 'Webhook added') }, 'webhooks', function () {
			return { url: '', format: 'json', secret: '', cond: newCond() };
		}, function (h, body) {
			body.appendChild(labelled(t('endpoint_url', 'Endpoint URL (https only)'), textIn(h.url, function (v) { h.url = v; }, 'url')));
			body.appendChild(labelled(t('format', 'Format'), select({ json: 'JSON', form: t('form_encoded', 'Form-encoded') }, h.format || 'json', function (v) { h.format = v; })));
			body.appendChild(labelled(t('signing_secret', 'Signing secret (optional, sent as X-FormTheory-Signature)'), textIn(h.secret, function (v) { h.secret = v; }, 'password')));
			body.appendChild(ruleEditor(h, t('only_send_when', 'Only send when conditions match')));
		});
	}

	function card(f, i) {
		var n = cfg.fields.length;
		function cardTitle(label) { var tl = LABELS[f.type] || f.type; return label ? fmt(t('card_title', '%1$s: %2$s'), tl, label) : tl; }
		var title = cardTitle(f.label);
		var body = el('div', { class: 'ftb-body' });
		var summary = el('span', { class: 'ftb-title', text: title });

		body.appendChild(labelled(f.type === 'page' ? t('page_title', 'Page title') : t('label', 'Label'), textIn(f.label, function (v) {
			f.label = v; summary.textContent = cardTitle(v);
		})));

		if (f.type === 'hidden') {
			body.appendChild(labelled(t('default_value', 'Default value (use {query:param} to fill from URL)'), textIn(f.default, function (v) { f.default = v; })));
		}
		if (f.type === 'consent' || f.type === 'html') {
			var key = f.type === 'consent' ? 'text' : 'content';
			var rich = el('textarea', { rows: 3, class: 'large-text', oninput: function (e) { f[key] = e.target.value; } });
			rich.value = f[key] || '';
			body.appendChild(labelled(f.type === 'consent' ? t('consent_text', 'Consent text (HTML links allowed)') : t('html_content', 'HTML content'), rich));
		}
		if (f.type === 'calc') {
			var refs = [];
			for (var ri = 0; ri < i; ri++) { var o = cfg.fields[ri]; if (o.type !== 'page' && o.type !== 'html') refs.push('{field:' + o.id + '}'); }
			var hintId = 'ftb-' + (++uid);
			var fx = textIn(f.formula, function (v) { f.formula = v; });
			fx.setAttribute('aria-describedby', hintId);
			fx.setAttribute('spellcheck', 'false');
			body.appendChild(labelled(t('formula', 'Formula'), fx));
			body.appendChild(el('p', { class: 'description', id: hintId, text: refs.length ? fmt(t('formula_hint', 'Use numbers, + - * /, parentheses and field references: %s'), refs.join(', ')) : t('formula_no_refs', 'Use numbers, + - * / and parentheses. Add fields above this one to reference them.') }));
			var dec = {}; for (var dn = 0; dn <= 6; dn++) dec[dn] = String(dn);
			body.appendChild(labelled(t('decimals', 'Decimal places'), select(dec, String(f.decimals == null ? 2 : f.decimals), function (v) { f.decimals = parseInt(v, 10); })));
			var pre = textIn(f.prefix, function (v) { f.prefix = v; }); pre.maxLength = 20;
			body.appendChild(labelled(t('prefix', 'Prefix (for example $)'), pre));
			var suf = textIn(f.suffix, function (v) { f.suffix = v; }); suf.maxLength = 20;
			body.appendChild(labelled(t('suffix', 'Suffix (for example kg)'), suf));
			body.appendChild(labelled(t('help_text', 'Help text'), textIn(f.help, function (v) { f.help = v; })));
		}
		if (['page', 'html', 'hidden', 'calc'].indexOf(f.type) === -1) {
			body.appendChild(check(t('required', 'Required'), f.required, function (v) { f.required = v; }));
			if (['text', 'email', 'phone', 'number', 'textarea', 'select'].indexOf(f.type) !== -1) {
				body.appendChild(labelled(t('placeholder', 'Placeholder'), textIn(f.placeholder, function (v) { f.placeholder = v; })));
			}
			body.appendChild(labelled(t('help_text', 'Help text'), textIn(f.help, function (v) { f.help = v; })));
		}
		if (['select', 'radio', 'checkbox'].indexOf(f.type) !== -1) {
			var ta = el('textarea', { rows: 4, class: 'large-text', oninput: function (e) { f.options = e.target.value.split('\n').map(function (s) { return s.trim(); }).filter(Boolean); } });
			ta.value = (f.options || []).join('\n');
			body.appendChild(labelled(t('choices', 'Choices (one per line)'), ta));
		}
		if (f.type === 'file') {
			body.appendChild(labelled(t('allowed_ext', 'Allowed extensions (comma separated)'), textIn(f.allowed || 'jpg,jpeg,png,pdf', function (v) { f.allowed = v; })));
			var mb = textIn(f.max_mb || 5, function (v) { f.max_mb = parseInt(v, 10) || 5; }, 'number');
			mb.min = 1; mb.max = 100;
			body.appendChild(labelled(t('max_size', 'Max size (MB)'), mb));
		}
		if (f.type !== 'page') body.appendChild(condEditor(f));

		var tools = el('div', { class: 'ftb-tools' }, [
			el('button', { type: 'button', class: 'button', 'data-dir': 'up', disabled: i === 0 ? 'disabled' : null, 'aria-label': fmt(t('move_up', 'Move %s up'), title), text: t('up', 'Up'), onclick: function () { move(i, -1); } }),
			el('button', { type: 'button', class: 'button', 'data-dir': 'down', disabled: i === n - 1 ? 'disabled' : null, 'aria-label': fmt(t('move_down', 'Move %s down'), title), text: t('down', 'Down'), onclick: function () { move(i, 1); } }),
			el('button', { type: 'button', class: 'button', 'aria-label': fmt(t('duplicate_field', 'Duplicate %s'), title), text: t('duplicate', 'Duplicate'), onclick: function () {
				var copy = JSON.parse(JSON.stringify(f)); copy.id = nextId();
				cfg.fields.splice(i + 1, 0, copy); render(copy.id); announce(t('field_duplicated', 'Field duplicated'));
			} }),
			el('button', { type: 'button', class: 'button ftb-danger', 'aria-label': fmt(t('delete_field', 'Delete %s'), title), text: t('delete', 'Delete'), onclick: function () {
				if (!window.confirm(t('confirm_delete', 'Delete this field?'))) return;
				cfg.fields.splice(i, 1);
				cfg.fields.forEach(function (o) { if (o.cond) o.cond.rules = (o.cond.rules || []).filter(function (r) { return r.field !== f.id; }); });
				render();
				var cards = root.querySelectorAll('.ftb-list > details.ftb-card > summary');
				var nx = cards[Math.min(i, cards.length - 1)] || root.querySelector('.ftb-palette button');
				if (nx) nx.focus();
				announce(t('field_deleted', 'Field deleted'));
			} })
		]);

		var details = el('details', { class: 'ftb-card' + (f.type === 'page' ? ' ftb-page' : ''), 'data-id': f.id }, [
			el('summary', {}, [summary, el('code', { class: 'ftb-id', text: f.id })]), body, tools
		]);
		return details;
	}

	function render(focusId, focusDir) {
		var open = {};
		root.querySelectorAll('details[open]').forEach(function (d) { open[d.getAttribute('data-id')] = true; });
		root.textContent = '';

		var palette = el('div', { class: 'ftb-palette', role: 'group', 'aria-label': t('add_field', 'Add field') }, [el('strong', { text: t('add_field', 'Add field') + ': ' })]);
		TYPES.forEach(function (tp) {
			palette.appendChild(el('button', { type: 'button', class: 'button', text: LABELS[tp] || tp, onclick: function () {
				var f = { id: nextId(), type: tp, label: tp === 'page' ? '' : LABELS[tp], required: false, options: ['select', 'radio', 'checkbox'].indexOf(tp) !== -1 ? [t('option_1', 'Option 1'), t('option_2', 'Option 2')] : [], cond: { enabled: false, action: 'show', logic: 'all', rules: [] } };
				if (tp === 'file') { f.allowed = 'jpg,jpeg,png,pdf'; f.max_mb = 5; }
				if (tp === 'calc') { f.formula = ''; f.decimals = 2; f.prefix = ''; f.suffix = ''; }
				cfg.fields.push(f);
				render(f.id, null, true);
				announce(fmt(t('field_added', '%s field added'), LABELS[tp] || tp));
			} }));
		});

		var list = el('div', { class: 'ftb-list' });
		if (!cfg.fields.length) list.appendChild(el('p', { class: 'ftb-empty', text: t('no_fields', 'No fields yet. Use the buttons above to add one.') }));
		cfg.fields.forEach(function (f, i) {
			var c = card(f, i);
			if (open[f.id] || (focusId === f.id && !focusDir)) c.open = true;
			list.appendChild(c);
		});

		var settings = el('fieldset', { class: 'ftb-settings' }, [el('legend', { text: t('settings', 'Settings') })]);
		settings.appendChild(labelled(t('submit_text', 'Submit button text'), textIn(cfg.submit_label, function (v) { cfg.submit_label = v; })));
		var conf = el('textarea', { rows: 3, class: 'large-text', oninput: function (e) { cfg.confirmation = e.target.value; } });
		conf.value = cfg.confirmation || '';
		settings.appendChild(labelled(t('confirmation_msg', 'Confirmation message'), conf));
		settings.appendChild(check(t('ajax', 'Submit without page reload (AJAX)'), cfg.ajax !== false, function (v) { cfg.ajax = v; }));
		var afterSel = select({ message: t('show_message', 'Show message'), page: t('go_to_page', 'Go to page (ID)'), redirect: t('redirect_to_url', 'Redirect to URL') }, cfg.confirmation_type || 'message', function (v) { cfg.confirmation_type = v; render(); var again = root.querySelector('[data-ftb-after]'); if (again) again.focus(); });
		afterSel.setAttribute('data-ftb-after', '1');
		settings.appendChild(labelled(t('after_submit', 'After submit'), afterSel));
		if (cfg.confirmation_type === 'page') {
			settings.appendChild(labelled(t('page_id', 'Page ID'), textIn(cfg.confirmation_page || '', function (v) { cfg.confirmation_page = parseInt(v, 10) || 0; }, 'number')));
		}
		if (cfg.confirmation_type === 'redirect') {
			settings.appendChild(labelled(t('redirect_url', 'Redirect URL (merge tags allowed in path/query)'), textIn(cfg.confirmation_url, function (v) { cfg.confirmation_url = v; }, 'url')));
		}

		root.appendChild(palette);
		root.appendChild(list);
		root.appendChild(settings);
		root.appendChild(notificationsEditor());
		root.appendChild(webhooksEditor());

		if (focusId) {
			var target = root.querySelector('details[data-id="' + focusId + '"]');
			if (target) {
				var btn = focusDir && target.querySelector('[data-dir="' + focusDir + '"]:not([disabled])');
				(btn || target.querySelector('summary')).focus();
			}
		}
	}

	form.addEventListener('submit', function () { store.value = JSON.stringify(cfg); });
	render();
})();
