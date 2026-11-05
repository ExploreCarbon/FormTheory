(function () {
	'use strict';

	var root = document.getElementById('ft-builder');
	var store = document.getElementById('ft-config');
	var form = document.getElementById('ft-builder-form');
	if (!root || !store || !form) return;

	var cfg = JSON.parse(store.value || '{}');
	cfg.fields = cfg.fields || [];
	var TYPES = JSON.parse(root.getAttribute('data-types'));
	var LABELS = { text: 'Text', email: 'Email', phone: 'Phone', number: 'Number', date: 'Date', textarea: 'Paragraph', select: 'Dropdown', radio: 'Radio buttons', checkbox: 'Checkboxes', file: 'File upload', page: 'Page break', hidden: 'Hidden', consent: 'Consent', html: 'HTML block' };
	var OPS = { is: 'is', isnot: 'is not', contains: 'contains', gt: 'greater than', lt: 'less than', empty: 'is empty', notempty: 'is not empty' };
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
		return el('input', { type: type || 'text', class: 'regular-text', value: val == null ? '' : String(val), oninput: function (e) { oninput(e.target.value); } });
	}

	function select(opts, val, onchange) {
		var s = el('select', { onchange: function (e) { onchange(e.target.value); } });
		Object.keys(opts).forEach(function (k) { s.appendChild(el('option', { value: k, text: opts[k] })); });
		s.value = val;
		return s;
	}

	function announce(msg) {
		var live = document.getElementById('ftb-live');
		live.textContent = '';
		setTimeout(function () { live.textContent = msg; }, 30);
	}

	function move(i, d) {
		var j = i + d;
		if (j < 0 || j >= cfg.fields.length) return;
		var t = cfg.fields[i]; cfg.fields[i] = cfg.fields[j]; cfg.fields[j] = t;
		render(cfg.fields[j].id, d < 0 ? 'up' : 'down');
		announce('Moved to position ' + (j + 1));
	}

	function condEditor(f) {
		f.cond = f.cond || { enabled: false, action: 'show', logic: 'all', rules: [] };
		var box = el('div', { class: 'ftb-cond' });
		var others = {};
		cfg.fields.forEach(function (o) { if (o.id !== f.id && o.type !== 'page' && o.type !== 'html') others[o.id] = (o.label || '(no label)') + ' [' + o.id + ']'; });

		function draw() {
			box.textContent = '';
			box.appendChild(check('Enable conditional logic', f.cond.enabled, function (v) {
				f.cond.enabled = v;
				if (v && !f.cond.rules.length && Object.keys(others).length) f.cond.rules.push({ field: Object.keys(others)[0], op: 'is', value: '' });
				draw();
			}));
			if (!f.cond.enabled) return;
			if (!Object.keys(others).length) { box.appendChild(el('p', { text: 'Add other fields first.' })); return; }
			box.appendChild(el('p', { class: 'ftb-inline' }, [
				select({ show: 'Show', hide: 'Hide' }, f.cond.action, function (v) { f.cond.action = v; }),
				' this field if ',
				select({ all: 'all', any: 'any' }, f.cond.logic, function (v) { f.cond.logic = v; }),
				' of these match:'
			]));
			f.cond.rules.forEach(function (r, i) {
				var val = textIn(r.value, function (v) { r.value = v; });
				val.className = '';
				val.setAttribute('aria-label', 'Value');
				val.hidden = r.op === 'empty' || r.op === 'notempty';
				var fs = select(others, r.field, function (v) { r.field = v; });
				fs.setAttribute('aria-label', 'Field');
				var os = select(OPS, r.op, function (v) { r.op = v; val.hidden = v === 'empty' || v === 'notempty'; });
				os.setAttribute('aria-label', 'Operator');
				box.appendChild(el('p', { class: 'ftb-inline' }, [fs, ' ', os, ' ', val, ' ',
					el('button', { type: 'button', class: 'button-link ftb-danger', onclick: function () { f.cond.rules.splice(i, 1); draw(); } }, ['Remove', el('span', { class: 'screen-reader-text', text: ' rule ' + (i + 1) })])
				]));
			});
			box.appendChild(el('button', { type: 'button', class: 'button', text: 'Add rule', onclick: function () {
				f.cond.rules.push({ field: Object.keys(others)[0], op: 'is', value: '' }); draw();
			} }));
		}
		draw();
		return box;
	}

	var TAG_HINT = 'Merge tags: {field:ID}, {all_fields}, {form_title}, {entry_id}, {date}, {site_name}, {admin_email}';

	function newCond() { return { enabled: false, action: 'show', logic: 'all', rules: [] }; }

	// Shared rules editor for notifications/webhooks: "only when all/any of these match".
	function ruleEditor(item, labelText) {
		item.cond = item.cond || newCond();
		var box = el('div', { class: 'ftb-cond' });
		var others = {};
		cfg.fields.forEach(function (o) { if (o.type !== 'page' && o.type !== 'html') others[o.id] = (o.label || '(no label)') + ' [' + o.id + ']'; });
		function draw() {
			box.textContent = '';
			box.appendChild(check(labelText, item.cond.enabled, function (v) {
				item.cond.enabled = v;
				if (v && !item.cond.rules.length && Object.keys(others).length) item.cond.rules.push({ field: Object.keys(others)[0], op: 'is', value: '' });
				draw();
			}));
			if (!item.cond.enabled || !Object.keys(others).length) return;
			box.appendChild(el('p', { class: 'ftb-inline' }, ['When ', select({ all: 'all', any: 'any' }, item.cond.logic, function (v) { item.cond.logic = v; }), ' of these match:']));
			item.cond.rules.forEach(function (r, i) {
				var val = textIn(r.value, function (v) { r.value = v; });
				val.className = ''; val.setAttribute('aria-label', 'Value');
				val.hidden = r.op === 'empty' || r.op === 'notempty';
				var fs = select(others, r.field, function (v) { r.field = v; }); fs.setAttribute('aria-label', 'Field');
				var os = select(OPS, r.op, function (v) { r.op = v; val.hidden = v === 'empty' || v === 'notempty'; }); os.setAttribute('aria-label', 'Operator');
				box.appendChild(el('p', { class: 'ftb-inline' }, [fs, ' ', os, ' ', val, ' ',
					el('button', { type: 'button', class: 'button-link ftb-danger', onclick: function () { item.cond.rules.splice(i, 1); draw(); } }, ['Remove', el('span', { class: 'screen-reader-text', text: ' rule ' + (i + 1) })])]));
			});
			box.appendChild(el('button', { type: 'button', class: 'button', text: 'Add rule', onclick: function () { item.cond.rules.push({ field: Object.keys(others)[0], op: 'is', value: '' }); draw(); } }));
		}
		draw();
		return box;
	}

	// Repeater panel: list of objects in cfg[key], each drawn by drawItem(item, body).
	function repeater(title, singular, key, make, drawItem) {
		cfg[key] = cfg[key] || [];
		var fs = el('fieldset', { class: 'ftb-settings' }, [el('legend', { text: title })]);
		function draw() {
			while (fs.childNodes.length > 1) fs.removeChild(fs.lastChild);
			cfg[key].forEach(function (item, i) {
				var body = el('div', { class: 'ftb-body' });
				drawItem(item, body);
				body.appendChild(el('button', { type: 'button', class: 'button ftb-danger', text: 'Remove', 'aria-label': 'Remove ' + singular + ' ' + (i + 1), onclick: function () { cfg[key].splice(i, 1); draw(); announce(singular + ' removed'); } }));
				var d = el('details', { class: 'ftb-card' }, [el('summary', { text: singular + ' ' + (i + 1) }), body]);
				d.open = true;
				fs.appendChild(d);
			});
			fs.appendChild(el('button', { type: 'button', class: 'button', text: 'Add ' + singular.toLowerCase(), onclick: function () { cfg[key].push(make()); draw(); announce(singular + ' added'); } }));
		}
		draw();
		return fs;
	}

	function notificationsEditor() {
		return repeater('Notifications', 'Notification', 'notifications', function () {
			return { id: 'n' + Date.now(), to: '{admin_email}', subject: 'New submission: {form_title}', body: '{all_fields}', reply_to: '', attach_files: false, cond: newCond() };
		}, function (n, body) {
			body.appendChild(el('p', { class: 'description', text: TAG_HINT }));
			body.appendChild(labelled('Send to (emails or {field:ID}, comma separated)', textIn(n.to, function (v) { n.to = v; })));
			body.appendChild(labelled('Subject', textIn(n.subject, function (v) { n.subject = v; })));
			var ta = el('textarea', { rows: 4, class: 'large-text', oninput: function (e) { n.body = e.target.value; } });
			ta.value = n.body || '';
			body.appendChild(labelled('Message', ta));
			body.appendChild(labelled('Reply-To (email or {field:ID})', textIn(n.reply_to, function (v) { n.reply_to = v; })));
			body.appendChild(check('Attach uploaded files', n.attach_files, function (v) { n.attach_files = v; }));
			body.appendChild(ruleEditor(n, 'Only send when conditions match'));
		});
	}

	function webhooksEditor() {
		return repeater('Webhooks', 'Webhook', 'webhooks', function () {
			return { url: '', format: 'json', secret: '', cond: newCond() };
		}, function (h, body) {
			body.appendChild(labelled('Endpoint URL (https only)', textIn(h.url, function (v) { h.url = v; }, 'url')));
			body.appendChild(labelled('Format', select({ json: 'JSON', form: 'Form-encoded' }, h.format || 'json', function (v) { h.format = v; })));
			body.appendChild(labelled('Signing secret (optional, sent as X-FormTheory-Signature)', textIn(h.secret, function (v) { h.secret = v; })));
			body.appendChild(ruleEditor(h, 'Only send when conditions match'));
		});
	}

	function card(f, i) {
		var n = cfg.fields.length;
		var title = (LABELS[f.type] || f.type) + (f.label ? ': ' + f.label : '');
		var body = el('div', { class: 'ftb-body' });
		var summary = el('span', { class: 'ftb-title', text: title });

		body.appendChild(labelled(f.type === 'page' ? 'Page title' : 'Label', textIn(f.label, function (v) {
			f.label = v; summary.textContent = (LABELS[f.type] || f.type) + (v ? ': ' + v : '');
		})));

		if (f.type === 'hidden') {
			body.appendChild(labelled('Default value (use {query:param} to fill from URL)', textIn(f.default, function (v) { f.default = v; })));
		}
		if (f.type === 'consent' || f.type === 'html') {
			var key = f.type === 'consent' ? 'text' : 'content';
			var rich = el('textarea', { rows: 3, class: 'large-text', oninput: function (e) { f[key] = e.target.value; } });
			rich.value = f[key] || '';
			body.appendChild(labelled(f.type === 'consent' ? 'Consent text (HTML links allowed)' : 'HTML content', rich));
		}
		if (['page', 'html', 'hidden'].indexOf(f.type) === -1) {
			body.appendChild(check('Required', f.required, function (v) { f.required = v; }));
			if (['text', 'email', 'phone', 'number', 'textarea', 'select'].indexOf(f.type) !== -1) {
				body.appendChild(labelled('Placeholder', textIn(f.placeholder, function (v) { f.placeholder = v; })));
			}
			body.appendChild(labelled('Help text', textIn(f.help, function (v) { f.help = v; })));
		}
		if (['select', 'radio', 'checkbox'].indexOf(f.type) !== -1) {
			var ta = el('textarea', { rows: 4, class: 'large-text', oninput: function (e) { f.options = e.target.value.split('\n').map(function (s) { return s.trim(); }).filter(Boolean); } });
			ta.value = (f.options || []).join('\n');
			body.appendChild(labelled('Choices (one per line)', ta));
		}
		if (f.type === 'file') {
			body.appendChild(labelled('Allowed extensions (comma separated)', textIn(f.allowed || 'jpg,jpeg,png,pdf', function (v) { f.allowed = v; })));
			var mb = textIn(f.max_mb || 5, function (v) { f.max_mb = parseInt(v, 10) || 5; }, 'number');
			mb.min = 1; mb.max = 100;
			body.appendChild(labelled('Max size (MB)', mb));
		}
		if (f.type !== 'page') body.appendChild(condEditor(f));

		var tools = el('div', { class: 'ftb-tools' }, [
			el('button', { type: 'button', class: 'button', 'data-dir': 'up', disabled: i === 0 ? 'disabled' : null, 'aria-label': 'Move ' + title + ' up', text: 'Up', onclick: function () { move(i, -1); } }),
			el('button', { type: 'button', class: 'button', 'data-dir': 'down', disabled: i === n - 1 ? 'disabled' : null, 'aria-label': 'Move ' + title + ' down', text: 'Down', onclick: function () { move(i, 1); } }),
			el('button', { type: 'button', class: 'button', 'aria-label': 'Duplicate ' + title, text: 'Duplicate', onclick: function () {
				var copy = JSON.parse(JSON.stringify(f)); copy.id = nextId();
				cfg.fields.splice(i + 1, 0, copy); render(copy.id); announce('Field duplicated');
			} }),
			el('button', { type: 'button', class: 'button ftb-danger', 'aria-label': 'Delete ' + title, text: 'Delete', onclick: function () {
				if (!window.confirm('Delete this field?')) return;
				cfg.fields.splice(i, 1);
				cfg.fields.forEach(function (o) { if (o.cond) o.cond.rules = (o.cond.rules || []).filter(function (r) { return r.field !== f.id; }); });
				render(); announce('Field deleted');
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

		var palette = el('div', { class: 'ftb-palette', role: 'group', 'aria-label': 'Add field' }, [el('strong', { text: 'Add field: ' })]);
		TYPES.forEach(function (t) {
			palette.appendChild(el('button', { type: 'button', class: 'button', text: LABELS[t] || t, onclick: function () {
				var f = { id: nextId(), type: t, label: t === 'page' ? '' : LABELS[t], required: false, options: ['select', 'radio', 'checkbox'].indexOf(t) !== -1 ? ['Option 1', 'Option 2'] : [], cond: { enabled: false, action: 'show', logic: 'all', rules: [] } };
				if (t === 'file') { f.allowed = 'jpg,jpeg,png,pdf'; f.max_mb = 5; }
				cfg.fields.push(f);
				render(f.id, null, true);
				announce(LABELS[t] + ' field added');
			} }));
		});

		var list = el('div', { class: 'ftb-list' });
		if (!cfg.fields.length) list.appendChild(el('p', { class: 'ftb-empty', text: 'No fields yet. Use the buttons above to add one.' }));
		cfg.fields.forEach(function (f, i) {
			var c = card(f, i);
			if (open[f.id] || (focusId === f.id && !focusDir)) c.open = true;
			list.appendChild(c);
		});

		var settings = el('fieldset', { class: 'ftb-settings' }, [el('legend', { text: 'Settings' })]);
		settings.appendChild(labelled('Submit button text', textIn(cfg.submit_label, function (v) { cfg.submit_label = v; })));
		var conf = el('textarea', { rows: 3, class: 'large-text', oninput: function (e) { cfg.confirmation = e.target.value; } });
		conf.value = cfg.confirmation || '';
		settings.appendChild(labelled('Confirmation message', conf));
		settings.appendChild(labelled('After submit', select({ message: 'Show message', page: 'Go to page (ID)', redirect: 'Redirect to URL' }, cfg.confirmation_type || 'message', function (v) { cfg.confirmation_type = v; render(); })));
		if (cfg.confirmation_type === 'page') {
			settings.appendChild(labelled('Page ID', textIn(cfg.confirmation_page || '', function (v) { cfg.confirmation_page = parseInt(v, 10) || 0; }, 'number')));
		}
		if (cfg.confirmation_type === 'redirect') {
			settings.appendChild(labelled('Redirect URL (merge tags allowed in path/query)', textIn(cfg.confirmation_url, function (v) { cfg.confirmation_url = v; }, 'url')));
		}

		root.appendChild(palette);
		root.appendChild(list);
		root.appendChild(settings);
		root.appendChild(notificationsEditor());
		root.appendChild(webhooksEditor());
		root.appendChild(el('div', { id: 'ftb-live', class: 'screen-reader-text', 'aria-live': 'polite' }));

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
