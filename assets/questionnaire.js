/**
 * BGINFO — Переход ПМЖ → ДВЖ: анкета (фронтенд).
 * Ванильный JS (ES2017), без сборки и внешних библиотек.
 * Ответы нигде не логируются и не уходят в аналитику; в браузере хранится
 * только одноразовый токен доступа (sessionStorage, ключ bgdvzh_k).
 */
(function () {
	'use strict';

	var root = document.getElementById('bgdvzh-app');
	if (!root) { return; }

	var cfg = window.BGDVZH_CONFIG || {};
	var GENERIC_ERROR = 'Не удалось выполнить запрос. Проверьте подключение и попробуйте ещё раз.';
	var TOKEN_KEY = 'bgdvzh_k';
	var CHECKOUT_KEY = 'bgdvzh_co';
	var STEPS = 7;
	var SUMMARY = 8;
	var DATE_MIN = '1950-01-01';

	/* ---------- Подписи (зеркало Questionnaire\Labels) ---------- */
	var L = {
		ynu: [['yes', 'Да'], ['no', 'Нет'], ['unknown', 'Не знаю']],
		eu_status: [
			['none', 'Нет, только гражданство(а) третьих стран'],
			['eu_citizen', 'Да, есть гражданство страны ЕС/ЕЭП/Швейцарии'],
			['bg_citizen', 'Да, есть гражданство Болгарии'],
			['unknown', 'Не знаю']
		],
		family: [['self', 'Подаю только за себя'], ['family', 'Есть вопросы и по членам семьи']],
		precision: [['exact', 'Точные даты'], ['approx', 'Примерно (известен месяц)'], ['unknown', 'Нет точных данных']],
		status_kind: [
			['vnzh', 'Продолжительное пребывание (ВНЖ)'],
			['study_24v', 'Разрешение для обучения (ст. 24в)'],
			['blue_card', 'Синяя карта ЕС'],
			['temp_protection', 'Временная защита'],
			['intl_protection', 'Международная защита'],
			['other', 'Иное основание']
		],
		status_end: [['ended', 'Закончился'], ['ongoing', 'Действует сейчас'], ['unknown', 'Не знаю']],
		old_documents: [['all', 'Да, сохранились все'], ['partial', 'Сохранились частично'], ['none', 'Не сохранились'], ['unknown', 'Не знаю']],
		trips_complete: [
			['complete', 'Да, указаны все поездки'],
			['partial', 'Указана только часть, остальные не помню'],
			['unknown', 'Нет точных данных']
		],
		protection: [
			['none', 'Нет'],
			['international', 'Международная защита (статус беженца или гуманитарный статус)'],
			['temporary', 'Временная защита'],
			['unknown', 'Не знаю']
		],
		other_eu_status: [
			['none', 'Нет'],
			['residence', 'Есть разрешение на проживание в другой стране ЕС'],
			['ltr', 'Есть статус долгосрочно пребывающего в другой стране ЕС'],
			['unknown', 'Не знаю']
		],
		tenure: [
			['own', 'Собственное жильё'],
			['rent', 'Аренда'],
			['family', 'Жильё родственников / предоставлено бесплатно'],
			['other', 'Иное'],
			['unknown', 'Не знаю']
		],
		housing_proofs: [
			['notarial_act', 'Нотариальный акт'],
			['lease', 'Договор аренды'],
			['owner_declaration', 'Декларация собственника'],
			['address_registration', 'Адресная регистрация'],
			['none', 'Нет документов'],
			['unknown', 'Не знаю']
		],
		contributions: [['ok', 'Взносы платятся без перерывов'], ['gaps', 'Есть перерывы или задолженность'], ['unknown', 'Не знаю']],
		other_cover: [['none', 'Нет'], ['policy', 'Медицинская страховка (полис)'], ['other', 'Другой режим покрытия'], ['unknown', 'Не знаю']],
		income_sources: [
			['salary', 'Зарплата'],
			['pension', 'Пенсия'],
			['business', 'Доход от бизнеса / самозанятости'],
			['rental', 'Доход от аренды'],
			['other', 'Другой регулярный доход'],
			['none', 'Регулярного дохода нет'],
			['unknown', 'Не знаю']
		],
		income_proofs: [
			['employment_contract', 'Трудовой договор'],
			['payslips', 'Справки о доходе / расчётные листы'],
			['pension_decision', 'Решение о пенсии / справка о пенсии'],
			['tax_return', 'Налоговая декларация'],
			['bank_statements', 'Банковские выписки с поступлениями'],
			['other', 'Другие документы'],
			['none', 'Подтверждений пока нет']
		],
		currency: [['EUR', 'EUR'], ['BGN', 'BGN'], ['other', 'Другая валюта']],
		bank_country: [['', 'Не указано'], ['bg', 'Болгария'], ['eu', 'Другая страна ЕС'], ['other', 'Страна вне ЕС']]
	};
	var STEP_TITLES = [
		'', 'О вас', 'ПМЖ и прежние статусы', 'Поездки', 'Особые основания',
		'Жильё и медицинское страхование', 'Средства', 'Документы, обстоятельства и дата подачи'
	];
	var STATUS_BADGE = {
		ok: 'Предварительно: препятствий не видно',
		check: 'Предварительно: нужна проверка',
		obstacle: 'Предварительно: есть препятствия'
	};
	var STATUS_ICON = { ok: '✓', check: '!', obstacle: '✕' };

	/* ---------- Состояние ---------- */
	var state = {};          // последний ответ /state
	var data = defaults();   // ответы анкеты
	var step = 1;            // 1..7, 8 = сводка
	var revision = 0;
	var hasDraft = false;
	var errors = {};         // path -> текст
	var ui = { ageSkip: false };
	var saveTimer = null;
	var saving = false;
	var dirty = false;
	var pollTimer = null;
	var lastResult = null;
	var savedTimer = null;

	var live = el('div', { class: 'bgdvzh-live', 'aria-live': 'polite' });
	var notice = el('div', { class: 'bgdvzh-notice-box' });
	var main = el('div', { class: 'bgdvzh-main' });

	root.textContent = '';
	root.appendChild(live);
	root.appendChild(notice);
	root.appendChild(main);

	/* ---------- Утилиты DOM ---------- */
	function el(tag, props) {
		var node = document.createElement(tag);
		var i;
		props = props || {};
		Object.keys(props).forEach(function (k) {
			var v = props[k];
			if (v === null || v === undefined || v === false) { return; }
			if (k === 'class') { node.className = v; }
			else if (k === 'text') { node.textContent = v; }
			else if (k.indexOf('on') === 0) { node.addEventListener(k.slice(2), v); }
			else if (k === 'value' || k === 'checked' || k === 'disabled' || k === 'selected') { node[k] = v; }
			else { node.setAttribute(k, v === true ? '' : String(v)); }
		});
		for (i = 2; i < arguments.length; i++) { append(node, arguments[i]); }
		return node;
	}
	function append(node, child) {
		if (child === null || child === undefined || child === false) { return; }
		if (Array.isArray(child)) { child.forEach(function (c) { append(node, c); }); return; }
		node.appendChild(typeof child === 'object' ? child : document.createTextNode(String(child)));
	}
	function byId(id) { return document.getElementById(id); }
	/** Текст из HTML (цена EDD приходит с сущностями вроде &#036;): разбор в инертном документе, скрипты не исполняются. */
	function htmlToText(html) {
		var s = String(html || '');
		try {
			var doc = new DOMParser().parseFromString(s, 'text/html');
			s = doc.body ? doc.body.textContent : s;
		} catch (e) { s = s.replace(/<[^>]*>/g, ''); }
		return s.replace(/ /g, ' ').replace(/\s+/g, ' ').trim();
	}
	function clear(node) { while (node.firstChild) { node.removeChild(node.firstChild); } }
	function idFor(path) { return 'bgdvzh-f-' + path.replace(/\./g, '-'); }
	function reduceMotion() {
		return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	}
	function scrollToApp() {
		var target = byId('anketa-dvzh') || root;
		try { target.scrollIntoView({ behavior: reduceMotion() ? 'auto' : 'smooth', block: 'start' }); }
		catch (e) { target.scrollIntoView(); }
	}
	function focusHeading() {
		var hd = main.querySelector('.bgdvzh-heading');
		if (hd) { hd.focus({ preventScroll: true }); }
	}
	function heading(text) {
		return el('h3', { class: 'bgdvzh-heading', tabindex: '-1', text: text });
	}
	function announce(text) {
		live.textContent = text || '';
	}

	/* ---------- Даты ---------- */
	function pad(n) { return (n < 10 ? '0' : '') + n; }
	function isYmd(s) { return /^\d{4}-\d{2}-\d{2}$/.test(s || ''); }
	function validDate(s) {
		if (!isYmd(s)) { return false; }
		var p = s.split('-').map(Number);
		var d = new Date(Date.UTC(p[0], p[1] - 1, p[2]));
		return d.getUTCFullYear() === p[0] && d.getUTCMonth() === p[1] - 1 && d.getUTCDate() === p[2];
	}
	function today() { return isYmd(cfg.today) ? cfg.today : new Date().toISOString().slice(0, 10); }
	function addYears(ymd, n) {
		var p = ymd.split('-').map(Number);
		var y = p[0] + n, m = p[1], d = p[2];
		if (m === 2 && d === 29) {
			var leap = (y % 4 === 0 && y % 100 !== 0) || y % 400 === 0;
			if (!leap) { d = 28; }
		}
		return y + '-' + pad(m) + '-' + pad(d);
	}
	function dmy(ymd) {
		if (!isYmd(ymd)) { return ''; }
		return ymd.slice(8, 10) + '.' + ymd.slice(5, 7) + '.' + ymd.slice(0, 4);
	}
	function windowStart() {
		var base = isYmd(data.filing.planned_date) ? data.filing.planned_date : today();
		return addYears(base, -5);
	}
	function dateText(prec, val) {
		if (prec === 'unknown' || !val) { return prec === 'unknown' ? 'нет точных данных' : '—'; }
		return prec === 'approx' ? val.slice(5, 7) + '.' + val.slice(0, 4) : dmy(val);
	}

	/* ---------- Данные по умолчанию и пути ---------- */
	function defaults() {
		return {
			applicant: { citizenship: '', other_citizenships: '', eu_status: 'unknown', age: null, family: 'self' },
			pmzh: { date_precision: 'exact', decision_date: '', basis: '', basis_article: '', decision_doc: 'unknown' },
			statuses: [],
			statuses_complete: 'unknown',
			history: { gap_without_permit: 'unknown', gaps: [], late_renewal: 'unknown', refusal: 'unknown', pending_decision: 'unknown', old_documents: 'unknown' },
			trips_any: 'unknown',
			trips: [],
			trips_complete: 'unknown',
			special: { study_24v: 'unknown', studied_other_basis: 'unknown', blue_card: 'unknown', protection: 'unknown', temporary_basis: 'unknown', other_eu_status: 'unknown' },
			housing: { region: '', registered_same: 'unknown', tenure: 'unknown', proofs: [] },
			health: { nzok: 'unknown', contributions: 'unknown', other_cover: 'unknown', cover_until: '' },
			funds: { income_sources: [], income_amount: null, income_currency: 'EUR', income_proofs: [], savings: 'unknown', bank_country: '', dependents: null },
			documents: { passport_until: '', pmzh_card_until: '', adverse: 'unknown', entry_ban: 'unknown', extra_check: 'unknown' },
			filing: { planned_date: '' }
		};
	}
	function newStatus() { return { kind: 'vnzh', basis: '', precision: 'exact', from: '', end: 'unknown', to: '' }; }
	function newTrip() { return { out: '', back: '', countries: '', precision: 'exact' }; }
	function newGap() { return { from: '', to: '' }; }

	function merge(base, src) {
		if (Array.isArray(base)) { return Array.isArray(src) ? src : base; }
		if (base && typeof base === 'object') {
			var out = {};
			Object.keys(base).forEach(function (k) {
				out[k] = (src && typeof src === 'object' && k in src) ? merge(base[k], src[k]) : base[k];
			});
			return out;
		}
		return (src === undefined || src === null) && base !== null ? base : src;
	}
	function getPath(path) {
		var cur = data;
		var parts = path.split('.');
		for (var i = 0; i < parts.length; i++) {
			if (cur === null || cur === undefined) { return undefined; }
			cur = cur[parts[i]];
		}
		return cur;
	}
	function setPath(path, value) {
		var parts = path.split('.');
		var cur = data;
		for (var i = 0; i < parts.length - 1; i++) { cur = cur[parts[i]]; }
		cur[parts[parts.length - 1]] = value;
	}

	/** Скрытые поля очищаются: в ответах остаётся только актуальное. */
	function applyConditions() {
		var d = data;
		if (d.pmzh.date_precision === 'unknown') { d.pmzh.decision_date = ''; }
		if (d.history.gap_without_permit !== 'yes') { d.history.gaps = []; }
		if (d.trips_any !== 'yes') { d.trips = []; d.trips_complete = 'unknown'; }
		if (d.health.nzok === 'yes') { d.health.other_cover = 'unknown'; d.health.cover_until = ''; }
		else { d.health.contributions = 'unknown'; }
		if (d.health.other_cover !== 'policy' && d.health.other_cover !== 'other') { d.health.cover_until = ''; }
		d.statuses.forEach(function (s) {
			if (s.precision === 'unknown') { s.from = ''; s.to = ''; }
			if (s.end !== 'ended') { s.to = ''; }
		});
		d.trips.forEach(function (t) { if (t.precision === 'unknown') { t.out = ''; t.back = ''; } });
		if (ui.ageSkip) { d.applicant.age = null; }
	}

	/* ---------- REST-клиент ---------- */
	function apiUrl(path) {
		var base = String(cfg.rest || '');
		if (base && base.slice(-1) !== '/' && base.indexOf('?') === -1) { base += '/'; }
		if (base.indexOf('?') !== -1 && path.indexOf('?') !== -1) { path = path.replace('?', '&'); }
		return base + path;
	}
	function csrfFor(action, id) {
		var c = state.csrf || {};
		var v = c[action];
		var snaps, i;
		if (v && typeof v === 'object') { v = v[id]; }
		if (!v && id) {
			snaps = state.snapshots || [];
			for (i = 0; i < snaps.length; i++) {
				if (snapId(snaps[i]) === id && snaps[i].csrf && snaps[i].csrf[action]) { v = snaps[i].csrf[action]; }
			}
		}
		if (!v && lastResult && lastResult.csrf && snapId(lastResult) === id) { v = lastResult.csrf[action]; }
		return typeof v === 'string' ? v : '';
	}
	function snapId(s) { return String((s && (s.id || s.public_id)) || ''); }

	function api(method, path, body, opts) {
		opts = opts || {};
		var headers = { 'Content-Type': 'application/json' };
		if (cfg.nonce) { headers['X-WP-Nonce'] = cfg.nonce; }
		if (opts.action) {
			var tok = csrfFor(opts.action, opts.id || '');
			if (tok) { headers['X-Bgdvzh-Csrf'] = tok; }
		}
		var init = { method: method, credentials: 'same-origin', headers: headers, cache: 'no-store' };
		if (body !== undefined && method !== 'GET') { init.body = JSON.stringify(body); }
		return fetch(apiUrl(path), init).then(function (r) {
			return r.text().then(function (t) {
				var j = null;
				try { j = t ? JSON.parse(t) : null; } catch (e) { j = null; }
				return { ok: r.ok, status: r.status, data: j || {} };
			});
		}).catch(function () {
			return { ok: false, status: 0, data: {} };
		}).then(function (res) {
			if (res.status === 403 && opts.action && !opts.retried) {
				return refreshState().then(function () {
					opts.retried = true;
					return api(method, path, body, opts);
				});
			}
			return res;
		});
	}
	function errMessage(res) {
		var m = res && res.data && res.data.message;
		return (typeof m === 'string' && m && res.status !== 0) ? m : GENERIC_ERROR;
	}
	function refreshState() {
		return api('GET', 'state').then(function (res) {
			state = res.ok ? res.data : {};
			return state;
		});
	}
	function ensureSession() {
		if (csrfFor('draft', '')) { return Promise.resolve(true); }
		return api('POST', 'session', {}).then(function (r) {
			if (!r.ok) { return false; }
			return refreshState().then(function () { return !!csrfFor('draft', ''); });
		});
	}

	/* ---------- Черновик ---------- */
	function draftPayload() {
		applyConditions();
		return { data: data, step: step, revision: revision };
	}
	function scheduleSave() {
		dirty = true;
		clearTimeout(saveTimer);
		saveTimer = setTimeout(saveDraft, 1500);
	}
	function showSaved() {
		var s = byId('bgdvzh-saved');
		if (!s) { return; }
		s.textContent = 'Черновик сохранён';
		clearTimeout(savedTimer);
		savedTimer = setTimeout(function () { s.textContent = ''; }, 3000);
	}
	function saveDraft() {
		clearTimeout(saveTimer);
		if (saving) { dirty = true; return Promise.resolve(false); }
		if (!dirty && hasDraft) { return Promise.resolve(true); }
		saving = true;
		dirty = false;
		return ensureSession().then(function (ok) {
			if (!ok) { saving = false; dirty = true; return false; }
			return api('POST', 'draft', draftPayload(), { action: 'draft' }).then(function (res) {
				saving = false;
				if (res.ok) {
					hasDraft = true;
					if (res.data && res.data.revision !== undefined) { revision = Number(res.data.revision) || 0; }
					showSaved();
					if (dirty) { scheduleSave(); }
					return true;
				}
				if (res.status === 409) {
					dirty = true;
					showConflict(res.data.draft || res.data);
					return false;
				}
				dirty = true;
				return false;
			});
		});
	}
	function showConflict(serverDraft) {
		clear(notice);
		var srvRev = Number(serverDraft && serverDraft.revision) || revision;
		notice.appendChild(el('div', { class: 'bgdvzh-notice', role: 'alert' },
			el('p', { text: 'Анкета изменена в другой вкладке.' }),
			el('div', { class: 'bgdvzh-actions' },
				el('button', {
					type: 'button', class: 'bgdvzh-btn', text: 'Загрузить актуальную версию',
					onclick: function () {
						clear(notice);
						applyDraft(serverDraft);
						dirty = false;
						renderForm();
					}
				}),
				el('button', {
					type: 'button', class: 'bgdvzh-btn bgdvzh-btn-secondary', text: 'Оставить мою',
					onclick: function () {
						clear(notice);
						revision = srvRev;
						dirty = true;
						saveDraft();
					}
				})
			)));
	}
	function applyDraft(d) {
		if (!d || typeof d !== 'object') { return; }
		if (d.data && typeof d.data === 'object') { data = merge(defaults(), d.data); }
		if (d.step) { step = Math.min(Math.max(Number(d.step) || 1, 1), STEPS); }
		revision = Number(d.revision) || 0;
		hasDraft = !!d.data;
		ui.ageSkip = hasDraft && data.applicant.age === null && Number(d.step) > 1;
	}

	/* ---------- Конструкторы полей ---------- */
	function describedBy(path, hintId) {
		var ids = [];
		if (hintId) { ids.push(hintId); }
		if (errors[path]) { ids.push(idFor(path) + '-err'); }
		return ids.join(' ') || null;
	}
	function errNode(path) {
		return el('p', { class: 'bgdvzh-error', id: idFor(path) + '-err', text: errors[path] || '', hidden: errors[path] ? null : true });
	}
	function clearError(path) {
		if (!errors[path]) { return; }
		delete errors[path];
		var n = byId(idFor(path) + '-err');
		if (n) { n.textContent = ''; n.hidden = true; }
		var c = byId(idFor(path));
		if (c) {
			c.removeAttribute('aria-invalid');
			var rest = String(c.getAttribute('aria-describedby') || '').split(' ').filter(function (x) { return x && x !== idFor(path) + '-err'; });
			if (rest.length) { c.setAttribute('aria-describedby', rest.join(' ')); } else { c.removeAttribute('aria-describedby'); }
		}
	}
	function hintNode(path, text) {
		return text ? el('p', { class: 'bgdvzh-hint', id: idFor(path) + '-hint', text: text }) : null;
	}
	function changed(path, rerenderFocusId) {
		clearError(path);
		scheduleSave();
		if (rerenderFocusId) { renderForm(rerenderFocusId); }
	}

	function textField(path, label, o) {
		o = o || {};
		var v = getPath(path);
		var input = el('input', {
			type: o.type || 'text', id: idFor(path), class: 'bgdvzh-input', value: v === null || v === undefined ? '' : String(v),
			maxlength: o.maxlength || 200, autocomplete: o.autocomplete || 'off',
			'aria-invalid': errors[path] ? 'true' : null, 'aria-describedby': describedBy(path, o.hint ? idFor(path) + '-hint' : null),
			oninput: function (e) { setPath(path, e.target.value); changed(path); }
		});
		return el('div', { class: 'bgdvzh-field' },
			el('label', { for: idFor(path), text: label }), hintNode(path, o.hint), input, errNode(path));
	}
	function numberField(path, label, o) {
		o = o || {};
		var v = getPath(path);
		var disabled = !!o.disabled;
		var input = el('input', {
			type: 'number', id: idFor(path), class: 'bgdvzh-input', inputmode: o.decimal ? 'decimal' : 'numeric',
			min: o.min, max: o.max, step: o.step || '1', disabled: disabled,
			value: v === null || v === undefined ? '' : String(v),
			'aria-invalid': errors[path] ? 'true' : null, 'aria-describedby': describedBy(path, o.hint ? idFor(path) + '-hint' : null),
			oninput: function (e) {
				var s = e.target.value;
				setPath(path, s === '' ? null : Number(s));
				changed(path);
			}
		});
		return el('div', { class: 'bgdvzh-field' },
			el('label', { for: idFor(path), text: label }), hintNode(path, o.hint), input, errNode(path));
	}
	function selectField(path, label, options, o) {
		o = o || {};
		var cur = getPath(path);
		var sel = el('select', {
			id: idFor(path), class: 'bgdvzh-input',
			'aria-invalid': errors[path] ? 'true' : null, 'aria-describedby': describedBy(path, o.hint ? idFor(path) + '-hint' : null),
			onchange: function (e) { setPath(path, e.target.value); if (o.onchange) { o.onchange(e.target.value); } changed(path, o.rerender ? idFor(path) : null); }
		}, options.map(function (opt) {
			return el('option', { value: opt[0], selected: opt[0] === cur, text: opt[1] });
		}));
		return el('div', { class: 'bgdvzh-field' },
			el('label', { for: idFor(path), text: label }), hintNode(path, o.hint), sel, errNode(path));
	}
	function radioField(path, label, options, o) {
		o = o || {};
		var cur = getPath(path);
		var fs = el('fieldset', {
			class: 'bgdvzh-fieldset', id: idFor(path),
			'aria-invalid': errors[path] ? 'true' : null, 'aria-describedby': describedBy(path, o.hint ? idFor(path) + '-hint' : null)
		}, el('legend', { text: label }), hintNode(path, o.hint));
		options.forEach(function (opt) {
			var rid = idFor(path) + '-' + opt[0];
			fs.appendChild(el('div', { class: 'bgdvzh-choice' },
				el('input', {
					type: 'radio', id: rid, name: idFor(path), value: opt[0], checked: opt[0] === cur,
					onchange: function () {
						setPath(path, opt[0]);
						if (o.onchange) { o.onchange(opt[0]); }
						changed(path, o.rerender ? rid : null);
					}
				}),
				el('label', { for: rid, text: opt[1] })));
		});
		fs.appendChild(errNode(path));
		return fs;
	}
	function ynuField(path, label, o) { return radioField(path, label, L.ynu, o); }
	function checksField(path, label, options, o) {
		o = o || {};
		var cur = getPath(path) || [];
		var fs = el('fieldset', {
			class: 'bgdvzh-fieldset', id: idFor(path),
			'aria-invalid': errors[path] ? 'true' : null, 'aria-describedby': describedBy(path, o.hint ? idFor(path) + '-hint' : null)
		}, el('legend', { text: label }), hintNode(path, o.hint));
		options.forEach(function (opt) {
			var cid = idFor(path) + '-' + opt[0];
			fs.appendChild(el('div', { class: 'bgdvzh-choice' },
				el('input', {
					type: 'checkbox', id: cid, value: opt[0], checked: cur.indexOf(opt[0]) !== -1,
					onchange: function (e) {
						var list = (getPath(path) || []).slice();
						var i = list.indexOf(opt[0]);
						if (e.target.checked && i === -1) { list.push(opt[0]); }
						if (!e.target.checked && i !== -1) { list.splice(i, 1); }
						setPath(path, list);
						changed(path);
					}
				}),
				el('label', { for: cid, text: opt[1] })));
		});
		fs.appendChild(errNode(path));
		return fs;
	}
	/** Поле даты. precision === 'approx' даёт выбор месяца, значение хранится как YYYY-MM-01. */
	function dateField(path, label, o) {
		o = o || {};
		var approx = o.precision === 'approx';
		var v = getPath(path) || '';
		var min = o.min || DATE_MIN;
		var max = o.max || addYears(today(), o.maxYears || 3);
		var input = el('input', {
			type: approx ? 'month' : 'date', id: idFor(path), class: 'bgdvzh-input',
			value: approx ? v.slice(0, 7) : v, min: approx ? min.slice(0, 7) : min, max: approx ? max.slice(0, 7) : max,
			placeholder: approx ? 'ГГГГ-ММ' : null,
			'aria-invalid': errors[path] ? 'true' : null, 'aria-describedby': describedBy(path, o.hint ? idFor(path) + '-hint' : null),
			oninput: function (e) {
				var s = e.target.value;
				if (approx) { setPath(path, /^\d{4}-\d{2}$/.test(s) ? s + '-01' : ''); }
				else { setPath(path, isYmd(s) ? s : ''); }
				changed(path);
			}
		});
		return el('div', { class: 'bgdvzh-field' },
			el('label', { for: idFor(path), text: label }), hintNode(path, o.hint), input, errNode(path));
	}
	function intro(text) { return el('p', { class: 'bgdvzh-intro', text: text }); }
	function sub(text) { return el('h4', { class: 'bgdvzh-subheading', text: text }); }

	/* ---------- Повторяющиеся блоки ---------- */
	function repeater(listPath, o) {
		var list = getPath(listPath);
		var box = el('div', { class: 'bgdvzh-repeater' });
		list.forEach(function (item, i) {
			var base = listPath + '.' + i;
			var row = el('fieldset', { class: 'bgdvzh-row', id: idFor(base) },
				el('legend', { text: o.rowTitle + ' ' + (i + 1) }),
				o.fields(base, item, i),
				el('button', {
					type: 'button', class: 'bgdvzh-btn bgdvzh-btn-secondary bgdvzh-btn-small',
					'aria-label': 'Удалить: ' + o.rowTitle.toLowerCase() + ' ' + (i + 1), text: 'Удалить',
					onclick: function () {
						list.splice(i, 1);
						errors = {};
						scheduleSave();
						renderForm(idFor(listPath + '-add'));
					}
				}));
			box.appendChild(row);
		});
		var max = o.max;
		var addBtn = el('button', {
			type: 'button', class: 'bgdvzh-btn bgdvzh-btn-secondary', id: idFor(listPath + '-add'), text: o.addLabel,
			disabled: list.length >= max,
			onclick: function () {
				list.push(o.make());
				scheduleSave();
				renderForm(null, idFor(listPath + '.' + (list.length - 1)));
			}
		});
		box.appendChild(addBtn);
		if (list.length >= max) { box.appendChild(el('p', { class: 'bgdvzh-hint', text: 'Достигнут предел: ' + max + '.' })); }
		return box;
	}
	function precisionChange(base, item) {
		return function (val) {
			if (val === 'unknown') {
				['from', 'to', 'out', 'back'].forEach(function (k) { if (k in item) { item[k] = ''; } });
			} else if (val === 'approx') {
				['from', 'to', 'out', 'back'].forEach(function (k) {
					if (k in item && isYmd(item[k])) { item[k] = item[k].slice(0, 8) + '01'; }
				});
			}
		};
	}

	/* ---------- Шаги ---------- */
	function stepBody(n) {
		var d = data;
		var t = today();
		var parts = [];
		if (n === 1) {
			parts.push(intro('Анкета заполняется на одного человека. Если вопрос касается семьи, каждого члена семьи нужно оценивать отдельно.'));
			parts.push(textField('applicant.citizenship', 'Ваше гражданство', { autocomplete: 'country-name' }));
			parts.push(textField('applicant.other_citizenships', 'Другие гражданства, если есть'));
			parts.push(radioField('applicant.eu_status', 'Есть ли у вас гражданство Болгарии или другой страны ЕС/ЕЭП/Швейцарии?', L.eu_status));
			parts.push(numberField('applicant.age', 'Сколько вам полных лет?', { min: 0, max: 120, disabled: ui.ageSkip }));
			parts.push(el('div', { class: 'bgdvzh-choice' },
				el('input', {
					type: 'checkbox', id: 'bgdvzh-age-skip', checked: ui.ageSkip,
					onchange: function (e) {
						ui.ageSkip = e.target.checked;
						if (ui.ageSkip) { d.applicant.age = null; }
						scheduleSave();
						renderForm('bgdvzh-age-skip');
					}
				}),
				el('label', { for: 'bgdvzh-age-skip', text: 'Не хочу указывать' })));
			parts.push(radioField('applicant.family', 'Вы оформляете только себя или есть вопросы и по членам семьи?', L.family));
		} else if (n === 2) {
			parts.push(intro('Эти вопросы нужны, чтобы понять, как складывался ваш срок проживания в Болгарии до получения ПМЖ.'));
			parts.push(radioField('pmzh.date_precision', 'Когда принято решение о предоставлении ПМЖ?', [
				['exact', 'Точная дата'], ['approx', 'Примерно, месяц и год'], ['unknown', 'Нет точных данных']
			], { rerender: true }));
			if (d.pmzh.date_precision !== 'unknown') {
				parts.push(dateField('pmzh.decision_date', d.pmzh.date_precision === 'approx' ? 'Месяц и год решения о ПМЖ' : 'Дата решения о ПМЖ', { precision: d.pmzh.date_precision, max: t }));
			}
			parts.push(textField('pmzh.basis', 'На каком основании получено ПМЖ (своими словами)?'));
			parts.push(textField('pmzh.basis_article', 'Статья и пункт из решения, если известны', { hint: 'Например: чл. 25, ал. 1, т. 6' }));
			parts.push(ynuField('pmzh.decision_doc', 'Сохранилось ли решение о ПМЖ?'));
			parts.push(sub('Статусы до ПМЖ'));
			parts.push(el('p', { class: 'bgdvzh-hint', text: 'Указывайте даты решений, а не сроки карт. Окончание срока карты само по себе не означает окончания статуса.' }));
			parts.push(repeater('statuses', {
				rowTitle: 'Статус', addLabel: 'Добавить статус', max: 20, make: newStatus,
				fields: function (base, s) {
					var pc = precisionChange(base, s);
					var r = [
						selectField(base + '.kind', 'Вид статуса', L.status_kind),
						textField(base + '.basis', 'Основание'),
						selectField(base + '.precision', 'Точность дат', L.precision, { rerender: true, onchange: pc })
					];
					if (s.precision !== 'unknown') {
						r.push(dateField(base + '.from', 'Дата начала (решения)', { precision: s.precision, max: t }));
					}
					r.push(selectField(base + '.end', 'Состояние', L.status_end, { rerender: true }));
					if (s.end === 'ended' && s.precision !== 'unknown') {
						r.push(dateField(base + '.to', 'Дата окончания', { precision: s.precision, max: t }));
					}
					return r;
				}
			}));
			parts.push(ynuField('statuses_complete', 'Это все разрешения на пребывание с момента первого приезда в Болгарию?'));
			parts.push(ynuField('history.gap_without_permit', 'Были ли периоды, когда у вас не было действующего разрешения?', { rerender: true }));
			if (d.history.gap_without_permit === 'yes') {
				parts.push(repeater('history.gaps', {
					rowTitle: 'Период без разрешения', addLabel: 'Добавить период', max: 10, make: newGap,
					fields: function (base) {
						return [dateField(base + '.from', 'С', { max: t }), dateField(base + '.to', 'По', { max: t })];
					}
				}));
			}
			parts.push(ynuField('history.late_renewal', 'Были ли просроченные продления?'));
			parts.push(ynuField('history.refusal', 'Были ли отказы миграции?'));
			parts.push(ynuField('history.pending_decision', 'Были ли периоды ожидания решения по продлению или смене статуса?'));
			parts.push(radioField('history.old_documents', 'Сохранились ли старые карты и решения?', L.old_documents));
		} else if (n === 3) {
			parts.push(intro('Учитываются все выезды из Болгарии, включая поездки в другие страны ЕС и Шенгена. Укажите поездки начиная с ' + dmy(windowStart()) + '.'));
			parts.push(el('p', { class: 'bgdvzh-window', text: 'Пятилетний период: с ' + dmy(windowStart()) }));
			parts.push(ynuField('trips_any', 'Были ли у вас отсутствия в Болгарии, которые хотя бы частично приходятся на период с ' + dmy(windowStart()) + '? Учитывайте и поездки, начавшиеся раньше этой даты, если вы вернулись после неё.', { rerender: true }));
			if (d.trips_any === 'yes') {
				parts.push(el('p', { class: 'bgdvzh-hint', text: 'Если поездка началась до ' + dmy(windowStart()) + ', а закончилась после — укажите её полностью, с настоящей датой выезда.' }));
				parts.push(repeater('trips', {
					rowTitle: 'Поездка', addLabel: 'Добавить поездку', max: 150, make: newTrip,
					fields: function (base, tr) {
						var pc = precisionChange(base, tr);
						var r = [selectField(base + '.precision', 'Точность', L.precision, { rerender: true, onchange: pc })];
						if (tr.precision !== 'unknown') {
							r.push(dateField(base + '.out', 'Дата выезда из Болгарии', { precision: tr.precision, max: t }));
							r.push(dateField(base + '.back', 'Дата возвращения', { precision: tr.precision, max: t }));
						}
						r.push(textField(base + '.countries', 'Страна(ы)'));
						return r;
					}
				}));
				parts.push(radioField('trips_complete', 'Это полная история поездок за этот период?', L.trips_complete));
			}
		} else if (n === 4) {
			parts.push(intro('Особые основания пребывания могут влиять на то, как засчитывается срок проживания.'));
			parts.push(ynuField('special.study_24v', 'Было ли у вас разрешение на пребывание для обучения (студент, ученик, стажёр — ст. 24в ЗЧРБ)?'));
			parts.push(ynuField('special.studied_other_basis', 'Учились ли вы, проживая на другом основании (например, семейном)?'));
			parts.push(ynuField('special.blue_card', 'Была ли у вас Синяя карта ЕС (в Болгарии или другой стране ЕС)?'));
			parts.push(radioField('special.protection', 'Была ли у вас международная или временная защита?', L.protection));
			parts.push(ynuField('special.temporary_basis', 'Проживали ли вы в Болгарии исключительно на временном основании: au pair, сезонная работа, командирование, трансграничные услуги?'));
			parts.push(radioField('special.other_eu_status', 'Есть ли у вас разрешение на проживание или статус долгосрочно пребывающего в другой стране ЕС?', L.other_eu_status));
		} else if (n === 5) {
			parts.push(intro('Жильё и медицинское страхование входят в число условий, которые проверяются при подаче.'));
			parts.push(textField('housing.region', 'Город или область, где вы живёте'));
			parts.push(ynuField('housing.registered_same', 'Там же ваша адресная регистрация?'));
			parts.push(radioField('housing.tenure', 'Жильё собственное или арендованное?', L.tenure));
			parts.push(checksField('housing.proofs', 'Какие документы на жильё у вас есть?', L.housing_proofs));
			parts.push(ynuField('health.nzok', 'Вы застрахованы в болгарской системе здравного осигуряване (НЗОК)?', { rerender: true }));
			if (d.health.nzok === 'yes') {
				parts.push(radioField('health.contributions', 'Взносы платятся без перерывов?', L.contributions));
			} else {
				parts.push(radioField('health.other_cover', 'Есть ли другое медицинское покрытие?', L.other_cover, { rerender: true }));
				if (d.health.other_cover === 'policy' || d.health.other_cover === 'other') {
					parts.push(dateField('health.cover_until', 'До какой даты оно действует?', { maxYears: 15 }));
				}
			}
		} else if (n === 6) {
			parts.push(intro('Данные о средствах помогают оценить, чем можно подтвердить достаточный доход.'));
			parts.push(checksField('funds.income_sources', 'Источники регулярного дохода', L.income_sources));
			parts.push(numberField('funds.income_amount', 'Примерный средний доход в месяц', { min: 0, max: 1000000, step: 'any', decimal: true, hint: 'Можно оставить пустым.' }));
			parts.push(selectField('funds.income_currency', 'Валюта дохода', L.currency));
			parts.push(checksField('funds.income_proofs', 'Чем вы можете подтвердить доход?', L.income_proofs));
			parts.push(ynuField('funds.savings', 'Есть ли сбережения на счетах?'));
			parts.push(selectField('funds.bank_country', 'В какой стране банк?', L.bank_country));
			parts.push(numberField('funds.dependents', 'Сколько человек на вашем содержании?', { min: 0, max: 20 }));
		} else if (n === 7) {
			parts.push(intro('Последние вопросы: сроки документов, неблагоприятные обстоятельства и дата подачи.'));
			parts.push(dateField('documents.passport_until', 'До какой даты действует паспорт?', { maxYears: 15 }));
			parts.push(dateField('documents.pmzh_card_until', 'До какой даты действует карта ПМЖ?', { maxYears: 15, hint: 'Окончание срока карты не равно окончанию права постоянного пребывания.' }));
			parts.push(ynuField('documents.adverse', 'Были ли решения миграции против вас: отказы, лишение статуса и т. п.?'));
			parts.push(ynuField('documents.entry_ban', 'Был ли запрет на въезд в Болгарию или ЕС?'));
			parts.push(ynuField('documents.extra_check', 'Есть ли другие обстоятельства, которые, по-вашему, требуют отдельной правовой проверки?', { hint: 'Подробности не нужны — анкета их не собирает.' }));
			parts.push(dateField('filing.planned_date', 'Предполагаемая дата подачи', {
				min: t, hint: 'Если не знаете, оставьте пустым — расчёт будет на сегодня, ' + dmy(t) + '.'
			}));
		}
		return parts;
	}

	/* ---------- Проверка на клиенте ---------- */
	function badRange(v, min, max) { return v < min || v > max; }
	/** maxYears — верхняя граница диапазона от сегодня (как в Validator::date: 3 года, для сроков документов 15). */
	function checkDate(errs, path, v, max, what, maxYears) {
		if (v && (!validDate(v) || badRange(v, DATE_MIN, addYears(today(), maxYears || 3)))) { errs[path] = 'Проверьте дату'; return false; }
		if (v && max && v > max) { errs[path] = what || 'Дата не может быть в будущем'; return false; }
		return true;
	}
	function validateStep(n) {
		var errs = {};
		var t = today();
		var d = data;
		if (n === 1) {
			if (!String(d.applicant.citizenship || '').trim()) { errs['applicant.citizenship'] = 'Укажите гражданство'; }
		} else if (n === 2) {
			if (d.pmzh.date_precision !== 'unknown') {
				if (!d.pmzh.decision_date) { errs['pmzh.decision_date'] = 'Укажите дату решения или выберите «Нет точных данных»'; }
				else { checkDate(errs, 'pmzh.decision_date', d.pmzh.decision_date, t, 'Решение не может быть принято в будущем'); }
			}
			d.statuses.forEach(function (s, i) {
				var b = 'statuses.' + i;
				if (s.precision === 'unknown') { return; }
				if (!s.from) { errs[b + '.from'] = 'Укажите дату начала'; }
				else { checkDate(errs, b + '.from', s.from, t); }
				if (s.end === 'ended') {
					if (!s.to) { errs[b + '.to'] = 'Укажите дату окончания'; }
					else if (checkDate(errs, b + '.to', s.to, t) && s.from && s.to < s.from) { errs[b + '.to'] = 'Окончание раньше начала'; }
				}
			});
			d.history.gaps.forEach(function (g, i) {
				var b = 'history.gaps.' + i;
				if (!g.from) { errs[b + '.from'] = 'Укажите дату начала периода'; } else { checkDate(errs, b + '.from', g.from, t); }
				if (!g.to) { errs[b + '.to'] = 'Укажите дату окончания периода'; }
				else if (checkDate(errs, b + '.to', g.to, t) && g.from && g.to < g.from) { errs[b + '.to'] = 'Окончание раньше начала'; }
			});
		} else if (n === 3) {
			var list = [];
			d.trips.forEach(function (tr, i) {
				var b = 'trips.' + i;
				if (tr.precision === 'unknown') { return; }
				var okOut = false, okBack = false;
				if (!tr.out) { errs[b + '.out'] = 'Укажите дату выезда'; }
				else { okOut = checkDate(errs, b + '.out', tr.out, t, 'Укажите только совершённые поездки'); }
				if (!tr.back) { errs[b + '.back'] = 'Укажите дату возвращения'; }
				else { okBack = checkDate(errs, b + '.back', tr.back, t, 'Укажите только совершённые поездки'); }
				if (okOut && okBack && tr.back < tr.out) { errs[b + '.back'] = 'Возвращение раньше выезда'; }
				else if (okOut && okBack) { list.push({ i: i, out: tr.out, back: tr.back }); }
			});
			list.sort(function (a, b) { return a.out < b.out ? -1 : (a.out > b.out ? 1 : 0); });
			for (var a = 0; a < list.length; a++) {
				for (var c = a + 1; c < list.length; c++) {
					if (list[c].out < list[a].back) {
						errs['trips.' + list[c].i + '.out'] = errs['trips.' + list[c].i + '.out'] || 'Поездка пересекается с другой поездкой';
					}
				}
			}
		} else if (n === 5) {
			if (d.health.cover_until) { checkDate(errs, 'health.cover_until', d.health.cover_until, null, null, 15); }
		} else if (n === 7) {
			checkDate(errs, 'documents.passport_until', d.documents.passport_until, null, null, 15);
			checkDate(errs, 'documents.pmzh_card_until', d.documents.pmzh_card_until, null, null, 15);
			if (d.filing.planned_date) {
				if (!validDate(d.filing.planned_date)) { errs['filing.planned_date'] = 'Проверьте дату'; }
				else if (d.filing.planned_date < t) { errs['filing.planned_date'] = 'Дата подачи не может быть в прошлом'; }
			}
		}
		return errs;
	}
	function stepOfPath(path) {
		var top = String(path).split('.')[0];
		if (top === 'applicant') { return 1; }
		if (['pmzh', 'statuses', 'statuses_complete', 'history'].indexOf(top) !== -1) { return 2; }
		if (['trips_any', 'trips', 'trips_complete'].indexOf(top) !== -1) { return 3; }
		if (top === 'special') { return 4; }
		if (top === 'housing' || top === 'health') { return 5; }
		if (top === 'funds') { return 6; }
		if (top === 'documents' || top === 'filing') { return 7; }
		return 0;
	}
	function firstErrorPath(errs, onlyStep) {
		var keys = Object.keys(errs).sort(function (a, b) {
			return (stepOfPath(a) || 99) - (stepOfPath(b) || 99);
		});
		for (var i = 0; i < keys.length; i++) {
			if (!onlyStep || stepOfPath(keys[i]) === onlyStep) { return keys[i]; }
		}
		return null;
	}
	function focusPath(path) {
		var node = byId(idFor(path));
		if (!node) { return; }
		if (node.tagName === 'FIELDSET') { node = node.querySelector('input, select') || node; }
		node.focus();
	}
	function reportErrors() {
		var keys = Object.keys(errors);
		clear(live);
		if (!keys.length) { return; }
		var ul = el('ul');
		keys.slice(0, 5).forEach(function (k) { ul.appendChild(el('li', { text: errors[k] })); });
		live.appendChild(el('div', { class: 'bgdvzh-error-summary' },
			el('p', { text: keys.length === 1 ? 'Нужно исправить одно поле:' : 'Нужно исправить полей: ' + keys.length + '.' }), ul));
	}

	/* ---------- Отрисовка формы ---------- */
	function renderForm(focusId, focusRow) {
		stopPoll();
		applyConditions();
		clear(main);
		var wrap = el('div', { class: 'bgdvzh-form' });
		if (step === SUMMARY) { renderSummary(wrap); main.appendChild(wrap); finishRender(focusId, focusRow); return; }
		wrap.appendChild(el('div', { class: 'bgdvzh-progress' },
			el('p', { class: 'bgdvzh-progress-text', text: 'Шаг ' + step + ' из ' + STEPS }),
			el('progress', { max: String(STEPS), value: String(step), 'aria-label': 'Шаг ' + step + ' из ' + STEPS, text: step + ' из ' + STEPS })));
		wrap.appendChild(heading(STEP_TITLES[step]));
		var form = el('form', { class: 'bgdvzh-step', novalidate: true, onsubmit: function (e) { e.preventDefault(); goNext(); } });
		stepBody(step).forEach(function (p) { form.appendChild(p); });
		form.appendChild(el('div', { class: 'bgdvzh-nav' },
			step > 1 ? el('button', { type: 'button', class: 'bgdvzh-btn bgdvzh-btn-secondary', text: 'Назад', onclick: goBack }) : null,
			el('button', { type: 'submit', class: 'bgdvzh-btn', text: step === STEPS ? 'К сводке' : 'Далее' }),
			el('span', { class: 'bgdvzh-saved', id: 'bgdvzh-saved' })));
		wrap.appendChild(form);
		main.appendChild(wrap);
		finishRender(focusId, focusRow);
	}
	function finishRender(focusId, focusRow) {
		var node;
		if (focusId) {
			node = byId(focusId);
			if (node) { node.focus(); }
		} else if (focusRow) {
			node = byId(focusRow);
			if (node) {
				var f = node.querySelector('input, select');
				if (f) { f.focus(); }
			}
		}
	}
	function changeStep(n, scroll) {
		step = n;
		errors = {};
		clear(live);
		dirty = true;
		saveDraft();
		renderForm();
		if (scroll !== false) { scrollToApp(); }
		focusHeading();
	}
	function goNext() {
		var errs = validateStep(step);
		if (Object.keys(errs).length) {
			errors = errs;
			renderForm();
			reportErrors();
			focusPath(firstErrorPath(errs));
			return;
		}
		errors = {};
		changeStep(step === STEPS ? SUMMARY : step + 1);
	}
	function goBack() { changeStep(step === SUMMARY ? STEPS : Math.max(1, step - 1)); }

	/* ---------- Сводка ---------- */
	function lab(group, v) {
		var list = L[group] || [];
		for (var i = 0; i < list.length; i++) { if (list[i][0] === v) { return list[i][1]; } }
		return v ? String(v) : '—';
	}
	function labs(group, arr) {
		return (arr && arr.length) ? arr.map(function (v) { return lab(group, v); }).join(', ') : '—';
	}
	function ynuLab(v) { return lab('ynu', v); }
	function orDash(v) { return (v === '' || v === null || v === undefined) ? '—' : String(v); }
	function summaryGroups() {
		var d = data;
		var g = [];
		g.push([1, [
			['Гражданство', orDash(d.applicant.citizenship)],
			['Другие гражданства', orDash(d.applicant.other_citizenships)],
			['Гражданство ЕС/ЕЭП/Швейцарии', lab('eu_status', d.applicant.eu_status)],
			['Возраст', d.applicant.age === null ? 'не указан' : String(d.applicant.age)],
			['Кого оформляете', lab('family', d.applicant.family)]
		]]);
		var s2 = [
			['Решение о ПМЖ', d.pmzh.date_precision === 'unknown' ? 'нет точных данных' : dateText(d.pmzh.date_precision, d.pmzh.decision_date)],
			['Основание ПМЖ', orDash(d.pmzh.basis)],
			['Статья и пункт', orDash(d.pmzh.basis_article)],
			['Решение сохранилось', ynuLab(d.pmzh.decision_doc)]
		];
		d.statuses.forEach(function (s, i) {
			s2.push(['Статус ' + (i + 1), lab('status_kind', s.kind) + (s.basis ? ' (' + s.basis + ')' : '') + '; ' +
				(s.precision === 'unknown' ? 'даты неизвестны' : 'с ' + dateText(s.precision, s.from) +
					(s.end === 'ended' ? ' по ' + dateText(s.precision, s.to) : '; ' + lab('status_end', s.end).toLowerCase()))]);
		});
		s2.push(['Перечислены все разрешения', ynuLab(d.statuses_complete)]);
		s2.push(['Периоды без разрешения', d.history.gap_without_permit === 'yes' ?
			(d.history.gaps.length ? d.history.gaps.map(function (x) { return 'с ' + dmy(x.from) + ' по ' + dmy(x.to); }).join('; ') : 'да, периоды не указаны') :
			ynuLab(d.history.gap_without_permit)]);
		s2.push(['Просроченные продления', ynuLab(d.history.late_renewal)]);
		s2.push(['Отказы миграции', ynuLab(d.history.refusal)]);
		s2.push(['Ожидание решений', ynuLab(d.history.pending_decision)]);
		s2.push(['Старые документы', lab('old_documents', d.history.old_documents)]);
		g.push([2, s2]);
		var s3 = [['Отсутствия с ' + dmy(windowStart()), ynuLab(d.trips_any)]];
		if (d.trips_any === 'yes') {
			d.trips.forEach(function (t, i) {
				s3.push(['Поездка ' + (i + 1), t.precision === 'unknown' ? 'даты неизвестны' :
					dateText(t.precision, t.out) + ' — ' + dateText(t.precision, t.back)]);
				if (t.countries) { s3[s3.length - 1][1] += ' (' + t.countries + ')'; }
			});
			s3.push(['Полнота истории поездок', lab('trips_complete', d.trips_complete)]);
		}
		g.push([3, s3]);
		g.push([4, [
			['Разрешение для обучения', ynuLab(d.special.study_24v)],
			['Учёба на другом основании', ynuLab(d.special.studied_other_basis)],
			['Синяя карта ЕС', ynuLab(d.special.blue_card)],
			['Защита', lab('protection', d.special.protection)],
			['Только временное основание', ynuLab(d.special.temporary_basis)],
			['Статус в другой стране ЕС', lab('other_eu_status', d.special.other_eu_status)]
		]]);
		var s5 = [
			['Город или область', orDash(d.housing.region)],
			['Регистрация по этому адресу', ynuLab(d.housing.registered_same)],
			['Жильё', lab('tenure', d.housing.tenure)],
			['Документы на жильё', labs('housing_proofs', d.housing.proofs)],
			['Страхование НЗОК', ynuLab(d.health.nzok)]
		];
		if (d.health.nzok === 'yes') { s5.push(['Взносы', lab('contributions', d.health.contributions)]); }
		else {
			s5.push(['Другое покрытие', lab('other_cover', d.health.other_cover)]);
			if (d.health.cover_until) { s5.push(['Покрытие действует до', dmy(d.health.cover_until)]); }
		}
		g.push([5, s5]);
		g.push([6, [
			['Источники дохода', labs('income_sources', d.funds.income_sources)],
			['Средний доход в месяц', d.funds.income_amount === null ? '—' : d.funds.income_amount + ' ' + lab('currency', d.funds.income_currency)],
			['Подтверждение дохода', labs('income_proofs', d.funds.income_proofs)],
			['Сбережения', ynuLab(d.funds.savings)],
			['Страна банка', lab('bank_country', d.funds.bank_country)],
			['На содержании', d.funds.dependents === null ? '—' : String(d.funds.dependents)]
		]]);
		g.push([7, [
			['Паспорт действует до', d.documents.passport_until ? dmy(d.documents.passport_until) : '—'],
			['Карта ПМЖ действует до', d.documents.pmzh_card_until ? dmy(d.documents.pmzh_card_until) : '—'],
			['Решения миграции против вас', ynuLab(d.documents.adverse)],
			['Запрет на въезд', ynuLab(d.documents.entry_ban)],
			['Нужна отдельная проверка', ynuLab(d.documents.extra_check)],
			['Предполагаемая дата подачи', d.filing.planned_date ? dmy(d.filing.planned_date) : 'не указана (расчёт на ' + dmy(today()) + ')']
		]]);
		return g;
	}
	function summaryWarnings() {
		var w = [];
		var ws = windowStart();
		if (data.trips_any === 'yes' && data.trips.length === 0) {
			w.push('Вы отметили, что были отсутствия, но не добавили ни одной поездки.');
		}
		var outside = data.trips.some(function (t) { return t.precision !== 'unknown' && t.back && t.back < ws; });
		if (outside) { w.push('Часть поездок относится к периоду до ' + dmy(ws) + ' и не будет учтена.'); }
		return w;
	}
	function renderSummary(wrap) {
		wrap.appendChild(heading('Проверьте ответы'));
		var keys = Object.keys(errors);
		if (keys.length) {
			var other = keys.filter(function (k) { return !stepOfPath(k); });
			if (other.length) {
				wrap.appendChild(el('div', { class: 'bgdvzh-error-summary' }, other.map(function (k) { return el('p', { text: errors[k] }); })));
			}
		}
		summaryGroups().forEach(function (grp) {
			var n = grp[0];
			var dl = el('dl', { class: 'bgdvzh-dl' });
			grp[1].forEach(function (row) { dl.appendChild(el('dt', { text: row[0] })); dl.appendChild(el('dd', { text: row[1] })); });
			wrap.appendChild(el('section', { class: 'bgdvzh-group', 'aria-label': 'Шаг ' + n + '. ' + STEP_TITLES[n] },
				el('div', { class: 'bgdvzh-group-head' },
					el('h4', { class: 'bgdvzh-subheading', text: 'Шаг ' + n + '. ' + STEP_TITLES[n] }),
					el('button', {
						type: 'button', class: 'bgdvzh-btn bgdvzh-btn-secondary bgdvzh-btn-small', text: 'Изменить',
						'aria-label': 'Изменить: ' + STEP_TITLES[n],
						onclick: function () { changeStep(n); }
					})),
				dl));
		});
		var warns = summaryWarnings();
		if (warns.length) {
			wrap.appendChild(el('div', { class: 'bgdvzh-warnings' }, el('p', { text: 'Обратите внимание:' }),
				el('ul', null, warns.map(function (x) { return el('li', { text: x }); }))));
		}
		var privacy = el('p', { class: 'bgdvzh-privacy' },
			'Ответы нужны только для расчёта предварительного результата и инструкции. Они хранятся на сайте ограниченное время и не передаются в системы аналитики. Номера документов и сканы не запрашиваются.');
		if (cfg.privacy_url) {
			privacy.appendChild(document.createTextNode(' '));
			privacy.appendChild(el('a', { href: cfg.privacy_url, target: '_blank', rel: 'noopener', text: 'Политика конфиденциальности' }));
		}
		wrap.appendChild(privacy);
		wrap.appendChild(el('div', { class: 'bgdvzh-nav' },
			el('button', { type: 'button', class: 'bgdvzh-btn bgdvzh-btn-secondary', text: 'Назад', onclick: goBack }),
			el('button', { type: 'button', class: 'bgdvzh-btn', id: 'bgdvzh-submit', text: 'Получить предварительный результат', onclick: submit }),
			el('span', { class: 'bgdvzh-saved', id: 'bgdvzh-saved' })));
	}

	function submit() {
		var btn = byId('bgdvzh-submit');
		for (var n = 1; n <= STEPS; n++) {
			var errs = validateStep(n);
			if (Object.keys(errs).length) {
				errors = errs;
				step = n;
				renderForm();
				reportErrors();
				focusPath(firstErrorPath(errs));
				return;
			}
		}
		if (btn) { btn.disabled = true; btn.textContent = 'Считаем…'; }
		saveDraft().then(function () {
			return ensureSession();
		}).then(function (ok) {
			if (!ok) { return { ok: false, status: 0, data: {} }; }
			return api('POST', 'submit', draftPayload(), { action: 'submit' });
		}).then(function (res) {
			if (res.ok) {
				errors = {};
				clear(live);
				return refreshState().then(function () {
					showResult({
						id: snapId(res.data), preview: res.data.preview || {}, payment: 'none',
						csrf: res.data.csrf, fresh: true
					});
				});
			}
			if (btn) { btn.disabled = false; btn.textContent = 'Получить предварительный результат'; }
			if (res.status === 422 && res.data && res.data.errors) {
				errors = res.data.errors;
				var first = firstErrorPath(errors);
				var s = first ? stepOfPath(first) : 0;
				if (s) {
					step = s;
					renderForm();
					reportErrors();
					focusPath(first);
				} else {
					renderForm();
					reportErrors();
				}
				return;
			}
			announce(errMessage(res));
			showInline(errMessage(res));
		});
	}
	function showInline(text) {
		clear(notice);
		notice.appendChild(el('div', { class: 'bgdvzh-notice', role: 'alert' }, el('p', { text: text })));
	}

	/* ---------- Результат ---------- */
	function stopPoll() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }
	function openResult(id, quiet) {
		stopPoll();
		return api('GET', 'result?id=' + encodeURIComponent(id)).then(function (res) {
			if (!res.ok) {
				showInline(errMessage(res));
				return false;
			}
			if (!res.data.id) { res.data.id = id; }
			showResult(res.data);
			return true;
		});
	}
	function badge(status) {
		var s = STATUS_BADGE[status] ? status : 'check';
		return el('p', { class: 'bgdvzh-badge bgdvzh-badge-' + s },
			el('span', { class: 'bgdvzh-badge-icon', 'aria-hidden': 'true', text: STATUS_ICON[s] }),
			el('span', { text: STATUS_BADGE[s] }));
	}
	function showResult(res) {
		stopPoll();
		lastResult = res;
		clear(notice);
		clear(live);
		clear(main);
		var p = res.preview || {};
		var pay = res.payment || 'none';
		var id = snapId(res);
		var wrap = el('div', { class: 'bgdvzh-result' });
		wrap.appendChild(heading('Предварительный результат'));
		wrap.appendChild(badge(p.status));
		if (p.title) { wrap.appendChild(el('h4', { class: 'bgdvzh-subheading', text: p.title })); }
		if (p.intro) { wrap.appendChild(el('p', { text: p.intro })); }
		if (p.reasons && p.reasons.length) {
			wrap.appendChild(el('ul', { class: 'bgdvzh-reasons' }, p.reasons.map(function (r) { return el('li', { text: r }); })));
		}
		if (p.filing_is_default && p.calc_date) {
			wrap.appendChild(el('p', { class: 'bgdvzh-meta', text: 'Расчёт выполнен на дату ' + p.calc_date + ', потому что дата подачи не указана.' }));
		} else if (p.calc_date) {
			wrap.appendChild(el('p', { class: 'bgdvzh-meta', text: 'Дата расчёта: ' + p.calc_date }));
		}
		if (p.disclaimer) { wrap.appendChild(el('p', { class: 'bgdvzh-disclaimer', text: p.disclaimer })); }

		if (pay === 'paid') { wrap.appendChild(paidBlock(res, id)); }
		else if (pay === 'pending') { wrap.appendChild(pendingBlock(id)); }
		else if (pay === 'unavailable') {
			wrap.appendChild(el('div', { class: 'bgdvzh-notice', role: 'status' }, el('p', { text: 'Инструкция временно недоступна. Попробуйте позже.' })));
		}
		else if (pay === 'refunded' || pay === 'revoked' || pay === 'disputed') {
			wrap.appendChild(el('div', { class: 'bgdvzh-notice' }, el('p', { text: 'Доступ к инструкции закрыт: оплата возвращена или оспорена.' })));
		} else {
			wrap.appendChild(offerBlock(p.offer || {}, id));
		}

		wrap.appendChild(el('div', { class: 'bgdvzh-nav' },
			el('button', {
				type: 'button', class: 'bgdvzh-btn bgdvzh-btn-secondary', text: 'Изменить ответы',
				onclick: function () { stopPoll(); clear(notice); step = hasDraft ? SUMMARY : 1; renderForm(); scrollToApp(); focusHeading(); }
			})));
		main.appendChild(wrap);
		scrollToApp();
		focusHeading();
	}

	function offerBlock(o, id) {
		var box = el('section', { class: 'bgdvzh-offer' });
		if (o.title) { box.appendChild(el('h4', { class: 'bgdvzh-subheading', text: o.title })); }
		if (o.lead) { box.appendChild(el('p', { text: o.lead })); }
		if (o.items && o.items.length) { box.appendChild(el('ul', null, o.items.map(function (x) { return el('li', { text: x }); }))); }
		if (state.price_html) {
			var price = el('p', { class: 'bgdvzh-price' }, 'Стоимость: ');
			price.appendChild(el('strong', { text: htmlToText(state.price_html) }));
			box.appendChild(price);
		}
		if (o.caveat) { box.appendChild(el('p', { class: 'bgdvzh-caveat', text: o.caveat })); }
		if (cfg.purchase_enabled === false || state.purchase_enabled === false) {
			box.appendChild(el('p', { class: 'bgdvzh-hint', text: 'Покупка временно недоступна.' }));
			return box;
		}
		var msg = el('p', { class: 'bgdvzh-error', hidden: true, role: 'alert' });
		var btn = el('button', {
			type: 'button', class: 'bgdvzh-btn', text: 'Перейти к оплате',
			onclick: function () {
				btn.disabled = true;
				msg.hidden = true;
				api('POST', 'checkout', { id: id }, { action: 'checkout', id: id }).then(function (res) {
					if (res.ok && typeof res.data.redirect === 'string' && /^https?:\/\//i.test(res.data.redirect)) {
						// После возврата с оплаты сразу открыть этот результат (оплачен или ожидает подтверждения).
						try { window.sessionStorage.setItem(CHECKOUT_KEY, id); } catch (e) { /* без хранилища */ }
						window.location.assign(res.data.redirect);
						return;
					}
					btn.disabled = false;
					var code = res.data && (res.data.code || res.data.error || res.data.reason);
					if (res.status === 409 && code === 'already_paid') { openResult(id); return; }
					if (res.status === 409 && code === 'in_progress') {
						showResult({ id: id, preview: (lastResult && lastResult.preview) || {}, payment: 'pending' });
						return;
					}
					msg.textContent = errMessage(res);
					msg.hidden = false;
				});
			}
		});
		box.appendChild(btn);
		box.appendChild(msg);
		return box;
	}

	function pendingBlock(id) {
		var box = el('section', { class: 'bgdvzh-pending', role: 'status' },
			el('p', null, el('span', { class: 'bgdvzh-spinner', 'aria-hidden': 'true' }), 'Ожидаем подтверждения оплаты'));
		var started = Date.now();
		var recheck = function () {
			api('GET', 'result?id=' + encodeURIComponent(id)).then(function (res) {
				if (res.ok && res.data.payment !== 'pending') {
					if (!res.data.id) { res.data.id = id; }
					showResult(res.data);
				}
			});
		};
		pollTimer = setInterval(function () {
			if (Date.now() - started > 180000) {
				stopPoll();
				clear(box);
				box.appendChild(el('p', { text: 'Подтверждение оплаты пока не получено.' }));
				box.appendChild(el('button', {
					type: 'button', class: 'bgdvzh-btn', text: 'Проверить ещё раз',
					onclick: function () { openResult(id); }
				}));
				return;
			}
			recheck();
		}, 5000);
		return box;
	}

	function paidBlock(res, id) {
		var box = el('section', { class: 'bgdvzh-paid' });
		var docBox = el('div', { class: 'bgdvzh-docstate', 'aria-live': 'polite' });
		var instr = el('div', { class: 'bgdvzh-doc bgdvzh-doc-wrap' });
		// instruction_html экранируется на сервере (HtmlRenderer), вставляется как разметка.
		instr.innerHTML = String(res.instruction_html || '');
		// Широкие таблицы прокручиваются внутри обёртки, а не растягивают страницу (в том числе на телефоне).
		Array.prototype.forEach.call(instr.querySelectorAll('table'), function (tbl) {
			if (tbl.parentNode && tbl.parentNode.classList && tbl.parentNode.classList.contains('bgdvzh-table-wrap')) { return; }
			var cap = tbl.querySelector('caption');
			var wrapT = el('div', { class: 'bgdvzh-table-wrap', role: 'region', tabindex: '0', 'aria-label': cap ? cap.textContent : 'Таблица' });
			tbl.parentNode.insertBefore(wrapT, tbl);
			wrapT.appendChild(tbl);
		});
		box.appendChild(instr);
		box.appendChild(docBox);
		var linkMsg = el('p', { class: 'bgdvzh-hint', role: 'status' });
		var linkBtn = el('button', {
			type: 'button', class: 'bgdvzh-btn bgdvzh-btn-secondary', text: 'Отправить ссылку на email заказа',
			onclick: function () {
				linkBtn.disabled = true;
				api('POST', 'send-link', { id: id }, { action: 'send_link', id: id }).then(function (r) {
					linkMsg.textContent = r.ok ? 'Ссылка отправлена на email, указанный при оплате.' : errMessage(r);
					linkBtn.disabled = false;
				});
			}
		});
		// Отправка ссылки доступна только владельцу по сеансу/аккаунту (снимок есть в /state);
		// кто открыл инструкцию по ссылке из письма, эту ссылку уже получил.
		var owns = (state.snapshots || []).some(function (s) { return snapId(s) === id; });
		if (owns) {
			box.appendChild(el('div', { class: 'bgdvzh-actions' }, linkBtn));
			box.appendChild(linkMsg);
		}

		var started = Date.now();
		function paintDoc(doc) {
			doc = doc || {};
			clear(docBox);
			var st = doc.state || 'pending';
			var dl = doc.download_url || res.download_url || '';
			// Ссылка на PDF есть всегда при оплате: контроллер скачивания генерирует документ синхронно (cron не обязателен).
			if (dl) {
				docBox.appendChild(el('a', { class: 'bgdvzh-btn', href: dl, text: 'Скачать инструкцию PDF' }));
			}
			if (st === 'done') {
				return;
			}
			if (st === 'failed') {
				docBox.appendChild(el('p', { text: 'Не удалось подготовить PDF. Инструкция на этой странице доступна.' }));
				docBox.appendChild(el('button', {
					type: 'button', class: 'bgdvzh-btn bgdvzh-btn-secondary', text: 'Повторить',
					onclick: function (e) {
						e.target.disabled = true;
						api('POST', 'retry-document', { id: id }, { action: 'retry', id: id }).then(function (r) {
							if (!r.ok) { e.target.disabled = false; docBox.appendChild(el('p', { class: 'bgdvzh-error', text: errMessage(r) })); return; }
							paintDoc({ state: 'running' });
							pollDoc();
						});
					}
				}));
			} else {
				docBox.appendChild(el('p', null, el('span', { class: 'bgdvzh-spinner', 'aria-hidden': 'true' }), 'Документ готовится — если загрузка не началась, попробуйте через минуту.'));
				if (Date.now() - started > 120000) {
					clear(docBox);
					if (dl) { docBox.appendChild(el('a', { class: 'bgdvzh-btn', href: dl, text: 'Скачать инструкцию PDF' })); }
					docBox.appendChild(el('p', { text: 'Документ ещё готовится.' }));
					docBox.appendChild(el('button', {
						type: 'button', class: 'bgdvzh-btn bgdvzh-btn-secondary', text: 'Проверить ещё раз',
						onclick: function () { started = Date.now(); paintDoc({ state: 'running' }); pollDoc(); }
					}));
				}
			}
		}
		function pollDoc() {
			stopPoll();
			pollTimer = setInterval(function () {
				if (Date.now() - started > 120000) { stopPoll(); paintDoc({ state: 'running' }); return; }
				api('GET', 'result?id=' + encodeURIComponent(id)).then(function (r) {
					if (!r.ok || !r.data.document) { return; }
					var st = r.data.document.state;
					if (st === 'done' || st === 'failed') { stopPoll(); paintDoc(r.data.document); }
				});
			}, 5000);
		}
		paintDoc(res.document);
		var s0 = res.document && res.document.state;
		if (s0 === 'pending') {
			// Один раз просим сервер сгенерировать PDF синхронно (на случай, если cron не работает).
			api('GET', 'result?id=' + encodeURIComponent(id) + '&generate=1').then(function (r) {
				if (!r.ok || !r.data.document) { return; }
				var st = r.data.document.state;
				if (st === 'done' || st === 'failed') { stopPoll(); paintDoc(r.data.document); }
			});
		}
		if (s0 !== 'done' && s0 !== 'failed') { pollDoc(); }
		return box;
	}

	/* ---------- Стартовый экран ---------- */
	function renderStart() {
		stopPoll();
		clear(main);
		var snaps = state.snapshots || [];
		if (!snaps.length) { snaps = state.granted || []; } /* только grant-cookie */
		if (!snaps.length) { renderForm(); return; }
		var wrap = el('div', { class: 'bgdvzh-start' });
		wrap.appendChild(heading('Ваши предыдущие результаты'));
		var ul = el('ul', { class: 'bgdvzh-snaps' });
		snaps.forEach(function (s) {
			var st = (s.preview && s.preview.status) || s.status;
			var sid = snapId(s);
			ul.appendChild(el('li', { class: 'bgdvzh-snap' },
				el('span', { class: 'bgdvzh-snap-info' },
					el('span', { class: 'bgdvzh-snap-status bgdvzh-snap-' + (STATUS_BADGE[st] ? st : 'check'), text: STATUS_BADGE[st] || 'Результат' }),
					el('span', { class: 'bgdvzh-snap-date', text: orDash(s.date || s.created_at) }),
					s.payment === 'paid' ? el('span', { class: 'bgdvzh-snap-paid', text: 'Инструкция оплачена' }) : null),
				el('button', {
					type: 'button', class: 'bgdvzh-btn bgdvzh-btn-secondary bgdvzh-btn-small', text: 'Открыть',
					'aria-label': 'Открыть результат от ' + orDash(s.date || s.created_at),
					onclick: function () { openResult(sid); }
				})));
		});
		wrap.appendChild(ul);
		wrap.appendChild(el('div', { class: 'bgdvzh-actions' },
			hasDraft ? el('button', {
				type: 'button', class: 'bgdvzh-btn', text: 'Продолжить анкету',
				onclick: function () { renderForm(); scrollToApp(); focusHeading(); }
			}) : null,
			el('button', {
				type: 'button', class: hasDraft ? 'bgdvzh-btn bgdvzh-btn-secondary' : 'bgdvzh-btn',
				text: hasDraft ? 'Начать заново' : 'Заполнить анкету',
				onclick: function () {
					if (hasDraft && !window.confirm('Текущий черновик будет заменён пустой анкетой. Продолжить?')) { return; }
					data = defaults();
					ui.ageSkip = false;
					step = 1;
					errors = {};
					dirty = true;
					renderForm();
					scrollToApp();
					focusHeading();
				}
			})));
		main.appendChild(wrap);
	}

	/* ---------- Запуск ---------- */
	function init() {
		var token = null;
		try { token = window.sessionStorage.getItem(TOKEN_KEY); } catch (e) { token = null; }
		var coId = null;
		try {
			coId = window.sessionStorage.getItem(CHECKOUT_KEY);
			window.sessionStorage.removeItem(CHECKOUT_KEY);
		} catch (e) { coId = null; }
		var start = Promise.resolve(null);
		if (token) {
			start = api('POST', 'grant', { token: token }).then(function (res) {
				try { window.sessionStorage.removeItem(TOKEN_KEY); } catch (e) { /* без хранилища */ }
				return res.ok ? snapId(res.data) : null;
			});
		}
		start.then(function (grantId) {
			return refreshState().then(function () {
				if (state.draft && state.draft.data) { applyDraft(state.draft); }
				if (!grantId && coId) {
					// Возврат с оплаты: результат открывается, только если покупка уже начата (оплачена или ждёт подтверждения).
					(state.snapshots || []).forEach(function (s) {
						if (snapId(s) === coId && (s.payment === 'paid' || s.payment === 'pending')) { grantId = coId; }
					});
				}
				if (!grantId && !(state.snapshots || []).length && !(state.draft && state.draft.data) && (state.granted || []).length === 1) { grantId = snapId(state.granted[0]); }
				if (grantId) { return openResult(grantId).then(function (ok) { if (!ok) { renderStart(); } }); }
				renderStart();
				return null;
			});
		}).catch(function () {
			clear(main);
			showInline(GENERIC_ERROR);
			renderForm();
		});
	}

	init();
})();
