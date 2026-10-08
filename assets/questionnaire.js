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
	var BOOKING_CO_KEY = 'bgdvzh_cb';   // id записи, ушедшей на оплату
	var BOOKING_TOKEN_KEY = 'bgdvzh_b'; // токен ссылки управления записью (см. ранний скрипт в Frontend)
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
	var SECTION_STATUS = {
		ok: ['Подтверждается', '✓'],
		check: ['Нужно проверить', '!'],
		obstacle: ['Препятствие', '✕'],
		info: ['Сведения', 'i']
	};

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
	var holdTimer = null;    // информационный отсчёт резерва времени
	var lastResult = null;
	var trackPaid = '';      // id снимка, для которого после возврата с оплаты ждём «покупка подтверждена»
	var trackBooking = '';   // то же для записи на консультацию
	var shownTracked = {};   // result_shown уже отправлен
	var bookingCsrf = {};    // id записи -> {checkout}
	var uid = 0;
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
		if (opts.csrf) {
			headers['X-Bgdvzh-Csrf'] = opts.csrf;
		} else if (opts.action) {
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
	function stopPoll() {
		if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
		if (holdTimer) { clearInterval(holdTimer); holdTimer = null; }
	}
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
	/** Бесплатный разбор по разделам; в старых снимках его нет (null). */
	function analysisBlock(a) {
		if (!a || !Array.isArray(a.sections) || !a.sections.length) { return null; }
		var box = el('section', { class: 'bgdvzh-analysis' }, el('h4', { class: 'bgdvzh-subheading', text: 'Разбор по разделам' }));
		if (Array.isArray(a.facts) && a.facts.length) {
			var dl = el('dl', { class: 'bgdvzh-facts' });
			a.facts.forEach(function (f) {
				dl.appendChild(el('dt', { text: String(f.label || '') }));
				dl.appendChild(el('dd', { text: String(f.value || '') }));
			});
			box.appendChild(dl);
		}
		var ul = el('ul', { class: 'bgdvzh-cards' });
		a.sections.forEach(function (s) {
			var st = SECTION_STATUS[s.status] ? s.status : 'info';
			var lines = Array.isArray(s.lines) ? s.lines : [];
			ul.appendChild(el('li', { class: 'bgdvzh-card bgdvzh-card-' + st },
				el('div', { class: 'bgdvzh-card-head' },
					el('h5', { class: 'bgdvzh-card-title', text: String(s.title || '') }),
					el('span', { class: 'bgdvzh-card-status' },
						el('span', { class: 'bgdvzh-card-icon', 'aria-hidden': 'true', text: SECTION_STATUS[st][1] }),
						el('span', { text: SECTION_STATUS[st][0] }))),
				lines.length ? el('ul', { class: 'bgdvzh-card-lines' }, lines.map(function (x) { return el('li', { text: String(x) }); })) : null));
		});
		box.appendChild(ul);
		return box;
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
		var analysis = analysisBlock(p.analysis);
		if (analysis) { wrap.appendChild(analysis); }
		else if (p.reasons && p.reasons.length) {
			wrap.appendChild(el('ul', { class: 'bgdvzh-reasons' }, p.reasons.map(function (r) { return el('li', { text: r }); })));
		}
		if (p.filing_is_default && p.calc_date) {
			wrap.appendChild(el('p', { class: 'bgdvzh-meta', text: 'Расчёт выполнен на дату ' + p.calc_date + ', потому что дата подачи не указана.' }));
		} else if (p.calc_date) {
			wrap.appendChild(el('p', { class: 'bgdvzh-meta', text: 'Дата расчёта: ' + p.calc_date }));
		}
		if (p.disclaimer) { wrap.appendChild(el('p', { class: 'bgdvzh-disclaimer', text: p.disclaimer })); }

		var consults = consultationsBlock(res.consultations || snapshotConsultations(id));
		if (consults) { wrap.appendChild(consults); }

		if (pay === 'paid' || pay === 'admin') {
			if (pay === 'paid' && trackPaid === id) { trackPaid = ''; track('bgdvzh_purchase_confirmed', 'instruction'); }
			wrap.appendChild(paidBlock(res, id, pay === 'admin'));
			if (pay === 'paid') { wrap.appendChild(offersBlock(id, p, null, true)); }
		}
		else if (pay === 'pending') { wrap.appendChild(pendingBlock(id)); }
		else if (pay === 'unavailable') {
			wrap.appendChild(el('div', { class: 'bgdvzh-notice', role: 'status' }, el('p', { text: 'Инструкция временно недоступна. Попробуйте позже.' })));
		}
		else if (pay === 'refunded' || pay === 'revoked' || pay === 'disputed') {
			wrap.appendChild(el('div', { class: 'bgdvzh-notice' }, el('p', { text: 'Доступ к инструкции закрыт: оплата возвращена или оспорена.' })));
		} else {
			if (!shownTracked[id]) { shownTracked[id] = true; track('bgdvzh_result_shown'); }
			wrap.appendChild(offersBlock(id, p, p.offer || {}, false));
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
		var box = el('section', { class: 'bgdvzh-offer bgdvzh-offer-card' });
		var ttl = offerText('instruction_title', o.title);
		var lead = offerText('instruction_text', o.lead);
		box.appendChild(el('h4', { class: 'bgdvzh-subheading', text: ttl }));
		if (lead) { box.appendChild(el('p', { text: lead })); }
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
			type: 'button', class: 'bgdvzh-btn', text: offerText('instruction_button'),
			onclick: function () {
				btn.disabled = true;
				msg.hidden = true;
				track('bgdvzh_option_selected', 'instruction');
				api('POST', 'checkout', { id: id }, { action: 'checkout', id: id }).then(function (res) {
					if (res.ok && typeof res.data.redirect === 'string' && /^https?:\/\//i.test(res.data.redirect)) {
						// После возврата с оплаты сразу открыть этот результат (оплачен или ожидает подтверждения).
						try { window.sessionStorage.setItem(CHECKOUT_KEY, id); } catch (e) { /* без хранилища */ }
						track('bgdvzh_checkout_opened', 'instruction');
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

	function paidBlock(res, id, isAdmin) {
		var box = el('section', { class: 'bgdvzh-paid' });
		if (isAdmin) {
			box.appendChild(el('p', { class: 'bgdvzh-admin-note', role: 'status', text: 'Доступ администратора: инструкция открыта без оплаты' }));
		}
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
		if (owns && !isAdmin) {
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

	/* ====================================================================
	 * Консультации: карточки предложений, выбор способа и времени, экран записи.
	 * Все тексты с сервера и из полей ввода попадают в DOM только через textContent.
	 * ==================================================================== */
	var BG_TZ = 'Europe/Sofia';
	var FORMAT_LABELS = { telegram: 'Telegram', whatsapp: 'WhatsApp', email: 'Email', phone: 'Звонок по телефону', meet: 'Google Meet' };
	var MODE_LABELS = { text: 'Переписка', call: 'Звонок', written: 'Письменный ответ' };
	var OFFER_DEFAULTS = {
		instruction_title: 'Инструкция',
		instruction_text: 'Персональная пошаговая инструкция по вашей анкете: что проверить, какие документы подготовить и в каком порядке действовать.',
		instruction_button: 'Получить мою инструкцию',
		consult_title: 'Консультация',
		consult_text: 'Разберём вашу ситуацию лично: ответим на вопросы по анкете и предложим план действий.',
		consult_button: 'Выбрать способ и время',
		upsell_after_instruction: 'Остались вопросы по вашей ситуации? Закажите консультацию: стоимость инструкции будет зачтена.',
		consult_obstacle_note: 'Обсудим причины и план подготовки. Консультация не гарантирует готовность к подаче или положительное решение',
		recommended_label: 'Рекомендуем при вашей ситуации'
	};
	var BOOKING_STATUS = {
		held: 'Ожидаем подтверждения оплаты', pending: 'Ожидаем подтверждения оплаты', pending_payment: 'Ожидаем подтверждения оплаты',
		confirmed: 'Запись подтверждена', needs_reschedule: 'Оплачено — консультант предложит новое время',
		cancelled: 'Отменена', refunded: 'Возврат', disputed: 'Платёж оспорен', expired: 'Резерв времени истёк', completed: 'Консультация проведена'
	};
	var PENDING_STATUSES = ['held', 'pending', 'pending_payment'];
	var TZ_SHORT = [
		'UTC', 'Europe/Sofia', 'Europe/Moscow', 'Europe/Kyiv', 'Europe/Minsk', 'Europe/London', 'Europe/Berlin', 'Europe/Istanbul',
		'Asia/Tbilisi', 'Asia/Yerevan', 'Asia/Dubai', 'Asia/Almaty', 'Asia/Tashkent', 'America/New_York', 'America/Los_Angeles'
	];

	/** Аналитика: только при включённой настройке и существующем dataLayer; без id, контактов и ответов. */
	function track(name, option) {
		if (!cfg.analytics_events) { return; }
		var dl = window.dataLayer;
		if (!dl || typeof dl.push !== 'function') { return; }
		var e = { event: name };
		if (option) { e.option = option; }
		try { dl.push(e); } catch (err) { /* аналитика не должна ломать страницу */ }
	}
	function offerText(key, legacy) {
		var o = state && state.offers;
		if (o && typeof o[key] === 'string' && o[key]) { return o[key]; }
		return legacy || OFFER_DEFAULTS[key];
	}

	/* ---------- Время и часовые пояса (Intl, с учётом перехода на летнее время) ---------- */
	function tzOk(tz) {
		if (!tz || typeof tz !== 'string') { return false; }
		try { new Intl.DateTimeFormat('en-GB', { timeZone: tz }); return true; } catch (e) { return false; }
	}
	function defaultTz() {
		var tz = '';
		try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone; } catch (e) { tz = ''; }
		return tzOk(tz) ? tz : BG_TZ;
	}
	function tzList(cur) {
		var list = [];
		try {
			if (typeof Intl.supportedValuesOf === 'function') { list = Intl.supportedValuesOf('timeZone').slice(); }
		} catch (e) { list = []; }
		if (!list.length) { list = TZ_SHORT.slice(); }
		[cur, BG_TZ, 'UTC'].forEach(function (z) { if (z && list.indexOf(z) === -1) { list.push(z); } });
		return list;
	}
	function fmtTs(ts, tz, o, loc) {
		var d = new Date(Number(ts) * 1000);
		o.timeZone = tzOk(tz) ? tz : 'UTC';
		return new Intl.DateTimeFormat(loc, o).format(d);
	}
	function timeLabel(ts, tz) { return fmtTs(ts, tz, { hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZoneName: 'short' }, 'en-GB'); }
	function dateLabel(ts, tz) { return fmtTs(ts, tz, { weekday: 'short', day: 'numeric', month: 'long' }, 'ru-RU'); }
	function dayKey(ts, tz) { return fmtTs(ts, tz, { year: 'numeric', month: '2-digit', day: '2-digit' }, 'en-CA'); }
	function fullLabel(ts, tz) { return dateLabel(ts, tz) + ', ' + timeLabel(ts, tz); }
	function ymdLabel(ymd) {
		if (!isYmd(ymd)) { return String(ymd || ''); }
		var p = ymd.split('-').map(Number);
		return new Intl.DateTimeFormat('ru-RU', { timeZone: 'UTC', weekday: 'short', day: 'numeric', month: 'long' }).format(new Date(Date.UTC(p[0], p[1] - 1, p[2], 12)));
	}
	function addDaysYmd(ymd, n) {
		var p = ymd.split('-').map(Number);
		var d = new Date(Date.UTC(p[0], p[1] - 1, p[2] + n, 12));
		return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate());
	}
	function plural(n, one, few, many) {
		var m10 = n % 10, m100 = n % 100;
		if (m10 === 1 && m100 !== 11) { return one; }
		if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) { return few; }
		return many;
	}
	function formatLabel(key, f) { return (f && f.label) || FORMAT_LABELS[key] || String(key || ''); }
	function money(pr, kind) {
		var d = pr && pr['display_' + kind];
		if (d) { return htmlToText(d); }
		var v = pr && pr[kind];
		return v ? String(v) + (pr.currency ? ' ' + pr.currency : '') : '';
	}

	/* ---------- Список записей снимка ---------- */
	function snapshotConsultations(id) {
		var list = [];
		(state.snapshots || []).forEach(function (s) { if (snapId(s) === id && Array.isArray(s.consultations)) { list = s.consultations; } });
		return list;
	}
	function consultWhen(c) {
		var tz = defaultTz();
		if (c.start_utc) { return fullLabel(c.start_utc, tz); }
		if (c.deadline_utc) { return 'Ответ не позднее ' + fullLabel(c.deadline_utc, tz); }
		return c.date ? ymdLabel(c.date) : '';
	}
	function consultationItems(list) {
		var now = Date.now();
		return (Array.isArray(list) ? list : []).filter(function (c) {
			if (!c || !c.id || c.status === 'expired') { return false; }
			return !(c.status === 'held' && c.hold_until && Number(c.hold_until) * 1000 < now);
		}).map(function (c) {
			var when = consultWhen(c);
			return el('li', { class: 'bgdvzh-snap bgdvzh-snap-consult' },
				el('span', { class: 'bgdvzh-snap-info' },
					el('span', { class: 'bgdvzh-snap-status', text: 'Консультация: ' + formatLabel(c.format) + (c.mode && MODE_LABELS[c.mode] && c.format !== 'email' ? ' (' + MODE_LABELS[c.mode].toLowerCase() + ')' : '') }),
					when ? el('span', { class: 'bgdvzh-snap-date', text: when }) : null,
					el('span', { class: 'bgdvzh-snap-paid', text: BOOKING_STATUS[c.status] || 'Статус обновляется' })),
				el('button', {
					type: 'button', class: 'bgdvzh-btn bgdvzh-btn-secondary bgdvzh-btn-small', text: 'Открыть запись',
					'aria-label': 'Открыть запись на консультацию: ' + formatLabel(c.format) + (when ? ', ' + when : ''),
					onclick: function () { openBooking(String(c.id)); }
				}));
		});
	}
	function consultationsBlock(list) {
		var items = consultationItems(list);
		if (!items.length) { return null; }
		return el('section', { class: 'bgdvzh-consults' },
			el('h4', { class: 'bgdvzh-subheading', text: 'Ваши записи на консультацию' }),
			el('ul', { class: 'bgdvzh-snaps' }, items));
	}

	/* ---------- Карточки предложений ---------- */
	/** upsell === true: после оплаты инструкции — только карточка консультации с зачётом. */
	function offersBlock(id, p, o, upsell) {
		var wrap = el('div', { class: 'bgdvzh-offers' });
		var grid = el('div', { class: 'bgdvzh-offer-grid' + (upsell ? ' bgdvzh-offer-grid-single' : '') });
		var host = el('div', { class: 'bgdvzh-consult-host' });
		if (!upsell) { grid.appendChild(offerBlock(o || {}, id)); }
		wrap.appendChild(grid);
		wrap.appendChild(host);
		if (cfg.consult_enabled === false) { return wrap; }
		api('GET', 'consult/options?id=' + encodeURIComponent(id)).then(function (r) {
			var d = r.data || {};
			// Выключено или недоступно: карточку не показываем, причину посетителю не сообщаем.
			if (!r.ok || !d.enabled || !Array.isArray(d.formats) || !d.formats.length) { return; }
			if (!wrap.isConnected) { return; }
			grid.appendChild(consultCard({ id: id, opts: d, preview: p || {}, host: host, upsell: !!upsell }));
		});
		return wrap;
	}
	function consultCard(ctx) {
		var o = ctx.opts;
		var hid = 'bgdvzh-cc-' + (++uid);
		var card = el('article', { class: 'bgdvzh-offer bgdvzh-offer-card bgdvzh-consult-card', 'aria-labelledby': hid });
		card.appendChild(el('h4', { class: 'bgdvzh-subheading', id: hid, text: offerText('consult_title') }));
		if (o.recommended && !ctx.upsell) { card.appendChild(el('p', { class: 'bgdvzh-tag', text: offerText('recommended_label') })); }
		card.appendChild(el('p', { text: ctx.upsell ? offerText('upsell_after_instruction') : offerText('consult_text') }));
		if (ctx.preview && ctx.preview.status === 'obstacle') {
			card.appendChild(el('p', { class: 'bgdvzh-note', text: offerText('consult_obstacle_note') }));
		}
		var c = o.consultant || {};
		if (c.name || c.experience || c.photo_url) {
			var info = el('div', { class: 'bgdvzh-consultant' });
			if (typeof c.photo_url === 'string' && /^https?:\/\//i.test(c.photo_url)) {
				info.appendChild(el('img', { class: 'bgdvzh-consultant-photo', src: c.photo_url, alt: c.name ? 'Фото: ' + c.name : '', width: '64', height: '64', loading: 'lazy' }));
			}
			info.appendChild(el('div', { class: 'bgdvzh-consultant-text' },
				c.name ? el('p', { class: 'bgdvzh-consultant-name', text: String(c.name) }) : null,
				c.experience ? el('p', { class: 'bgdvzh-hint', text: String(c.experience) }) : null));
			card.appendChild(info);
		}
		if (o.price) { card.appendChild(priceBlock(o.price)); }
		var btn = el('button', {
			type: 'button', class: 'bgdvzh-btn', text: offerText('consult_button'), 'aria-expanded': 'false',
			onclick: function () {
				track('bgdvzh_option_selected', 'consultation');
				if (ctx.panel && ctx.panel.isConnected) {
					var hd = ctx.panel.querySelector('.bgdvzh-heading');
					if (hd) { hd.focus(); }
					return;
				}
				btn.setAttribute('aria-expanded', 'true');
				ctx.btn = btn;
				ctx.panel = openConsultFlow(ctx);
				ctx.host.appendChild(ctx.panel);
				try { ctx.panel.scrollIntoView({ behavior: reduceMotion() ? 'auto' : 'smooth', block: 'start' }); } catch (e) { /* старые браузеры */ }
				var h = ctx.panel.querySelector('.bgdvzh-heading');
				if (h) { h.focus({ preventScroll: true }); }
			}
		});
		card.appendChild(el('div', { class: 'bgdvzh-actions' }, btn));
		return card;
	}
	function priceBlock(pr) {
		var box = el('div', { class: 'bgdvzh-priceblock' });
		if (!pr || typeof pr !== 'object') { return box; }
		var dl = el('dl', { class: 'bgdvzh-pricelist' });
		function row(k, v, cls) {
			dl.appendChild(el('dt', { class: cls, text: k }));
			dl.appendChild(el('dd', { class: cls, text: v }));
		}
		var credit = parseFloat(pr.credit) > 0;
		if (credit) {
			row('Стоимость', money(pr, 'base'));
			row('Зачёт инструкции', '−' + money(pr, 'credit'));
		}
		row(credit ? 'Итого' : 'Стоимость', pr.display ? htmlToText(pr.display) : money(pr, 'item'), 'bgdvzh-price-total');
		box.appendChild(dl);
		if (pr.tax_note) { box.appendChild(el('p', { class: 'bgdvzh-hint', text: htmlToText(pr.tax_note) })); }
		return box;
	}

	/* ---------- Выбор даты и времени (используется при записи и при переносе) ---------- */
	/** o: {format, written, tz?, sel?, onChange(sel, tz)}. Возвращает {node, start, reload, tz()}. */
	function createPicker(o) {
		var pid = 'bgdvzh-pk' + (++uid);
		var p = { tz: tzOk(o.tz) ? o.tz : defaultTz(), from: '', hist: [], av: null, loading: true, error: '', day: '', sel: o.sel || null };
		var node = el('div', { class: 'bgdvzh-picker' });
		var seq = 0;

		function load(from) {
			var my = ++seq;
			p.loading = true;
			p.error = '';
			paint();
			var q = 'consult/availability?format=' + encodeURIComponent(o.format) + '&days=14' + (from ? '&from=' + encodeURIComponent(from) : '');
			return api('GET', q).then(function (res) {
				if (my !== seq) { return; }
				p.loading = false;
				if (res.ok && res.data) { p.av = res.data; p.from = res.data.from || from || ''; }
				else { p.av = null; p.error = errMessage(res); }
				paint();
			});
		}
		function pick(sel) {
			p.sel = sel;
			if (o.onChange) { o.onChange(sel, p.tz); }
		}
		function shift(dir) {
			if (dir > 0) { p.hist.push(p.from); load(addDaysYmd(p.from || today(), 14)); }
			else if (p.hist.length) { load(p.hist.pop() || ''); }
		}
		function optBtn(id, active, label, onclick, cls, aria) {
			return el('button', { type: 'button', id: id, class: cls, 'aria-pressed': active ? 'true' : 'false', 'aria-label': aria || null, onclick: onclick }, label);
		}
		function selectedText() {
			var s = p.sel;
			if (!s) { return ''; }
			if (s.start_utc) {
				return 'Выбрано: ' + fullLabel(s.start_utc, p.tz) + (p.tz !== BG_TZ ? ' (в Болгарии: ' + timeLabel(s.start_utc, BG_TZ) + ')' : '');
			}
			return 'Выбрано: ' + ymdLabel(s.date) + (s.deadline_utc ? '. Ответ не позднее ' + fullLabel(s.deadline_utc, p.tz) : '');
		}

		function paint() {
			var ae = document.activeElement;
			var fid = ae && node.contains(ae) ? ae.id : '';
			clear(node);
			var tzId = pid + '-tz';
			node.appendChild(el('div', { class: 'bgdvzh-field' },
				el('label', { for: tzId, text: 'Часовой пояс' }),
				el('select', {
					id: tzId, class: 'bgdvzh-input',
					onchange: function (e) {
						p.tz = e.target.value;
						p.day = '';
						if (p.sel && o.onChange) { o.onChange(p.sel, p.tz); }
						paint();
					}
				}, tzList(p.tz).map(function (z) { return el('option', { value: z, selected: z === p.tz, text: z.replace(/_/g, ' ') }); }))));

			if (p.loading) {
				node.appendChild(el('p', { class: 'bgdvzh-hint', role: 'status' }, el('span', { class: 'bgdvzh-spinner', 'aria-hidden': 'true' }), 'Загружаем свободное время…'));
				return;
			}
			if (p.error || !p.av) {
				node.appendChild(el('p', { class: 'bgdvzh-error', role: 'alert', text: p.error || GENERIC_ERROR }));
				node.appendChild(el('button', { type: 'button', class: 'bgdvzh-btn bgdvzh-btn-secondary', text: 'Повторить', onclick: function () { load(p.from); } }));
				return;
			}
			var av = p.av;
			var written = !!o.written;
			var any = false;
			var next = Array.isArray(av.next_dates) ? av.next_dates : [];

			if (written) {
				var dates = Array.isArray(av.dates) ? av.dates : [];
				any = dates.length > 0;
				var list = any ? dates : next.filter(function (d) { return d && typeof d === 'object'; });
				if (!any) { node.appendChild(el('p', { class: 'bgdvzh-empty', role: 'status', text: 'Свободного времени нет' })); }
				if (list.length) {
					if (!any) { node.appendChild(el('p', { class: 'bgdvzh-hint', text: 'Ближайшие даты:' })); }
					var wg = el('div', { class: 'bgdvzh-slots bgdvzh-slots-wide', role: 'group', 'aria-label': 'Дата письменного ответа' });
					list.forEach(function (d) {
						var active = !!(p.sel && p.sel.date === d.date);
						var txt = ymdLabel(d.date) + ' — ответ не позднее ' + fullLabel(d.deadline_utc, p.tz);
						wg.appendChild(optBtn(pid + '-w-' + d.date, active, txt, function () {
							pick({ date: d.date, deadline_utc: d.deadline_utc });
							paint();
						}, 'bgdvzh-slot' + (active ? ' is-active' : '')));
					});
					node.appendChild(wg);
				}
			} else {
				var slots = (Array.isArray(av.slots) ? av.slots : []).slice().sort(function (a, b) { return Number(a.start_utc) - Number(b.start_utc); });
				any = slots.length > 0;
				if (!any) {
					node.appendChild(el('p', { class: 'bgdvzh-empty', role: 'status', text: 'Свободного времени нет' }));
					if (next.length) {
						node.appendChild(el('p', { class: 'bgdvzh-hint', text: 'Ближайшие даты со свободным временем:' }));
						var ng = el('div', { class: 'bgdvzh-slots', role: 'group', 'aria-label': 'Ближайшие даты' });
						next.forEach(function (d) {
							if (!isYmd(d)) { return; }
							ng.appendChild(el('button', {
								type: 'button', class: 'bgdvzh-slot', text: ymdLabel(d),
								onclick: function () { p.hist.push(p.from); load(d); }
							}));
						});
						node.appendChild(ng);
					}
				} else {
					var groups = {};
					var order = [];
					slots.forEach(function (s) {
						var k = dayKey(s.start_utc, p.tz);
						if (!groups[k]) { groups[k] = []; order.push(k); }
						groups[k].push(s);
					});
					if (!p.day || !groups[p.day]) {
						p.day = (p.sel && p.sel.start_utc && groups[dayKey(p.sel.start_utc, p.tz)]) ? dayKey(p.sel.start_utc, p.tz) : order[0];
					}
					var dg = el('div', { class: 'bgdvzh-days', role: 'group', 'aria-label': 'Дата' });
					order.forEach(function (k) {
						var act = k === p.day;
						dg.appendChild(optBtn(pid + '-d-' + k, act, dateLabel(groups[k][0].start_utc, p.tz), function () { p.day = k; paint(); }, 'bgdvzh-day' + (act ? ' is-active' : '')));
					});
					node.appendChild(dg);
					var sg = el('div', { class: 'bgdvzh-slots', role: 'group', 'aria-label': 'Время на ' + dateLabel(groups[p.day][0].start_utc, p.tz) });
					groups[p.day].forEach(function (s) {
						var ts = Number(s.start_utc);
						var act = !!(p.sel && Number(p.sel.start_utc) === ts);
						var bg = p.tz !== BG_TZ ? timeLabel(ts, BG_TZ) + ' по Болгарии' : '';
						sg.appendChild(optBtn(pid + '-s-' + ts, act,
							[el('span', { class: 'bgdvzh-slot-main', text: timeLabel(ts, p.tz) }), bg ? el('span', { class: 'bgdvzh-slot-bg', text: bg }) : null],
							function () { pick({ start_utc: ts }); paint(); },
							'bgdvzh-slot' + (act ? ' is-active' : ''), fullLabel(ts, p.tz) + (bg ? ', ' + bg : '')));
					});
					node.appendChild(sg);
				}
				var nav = el('div', { class: 'bgdvzh-actions' });
				if (p.hist.length) { nav.appendChild(el('button', { type: 'button', id: pid + '-prev', class: 'bgdvzh-btn bgdvzh-btn-secondary bgdvzh-btn-small', text: '← Раньше', onclick: function () { shift(-1); } })); }
				nav.appendChild(el('button', { type: 'button', id: pid + '-next', class: 'bgdvzh-btn bgdvzh-btn-secondary bgdvzh-btn-small', text: 'Позже →', onclick: function () { shift(1); } }));
				node.appendChild(nav);
			}
			node.appendChild(el('p', { class: 'bgdvzh-picked', role: 'status', text: selectedText() }));
			if (fid) {
				var back = byId(fid);
				if (back) { back.focus({ preventScroll: true }); }
			}
		}
		paint();
		return {
			node: node,
			start: function () { return load(''); },
			reload: function () { return load(p.from); },
			tz: function () { return p.tz; },
			sel: function () { return p.sel; },
			clearSel: function () { p.sel = null; }
		};
	}

	/* ---------- Запись: формат → время → контакты → резерв → оплата ---------- */
	function priceOfFormat(ctx, f) {
		return (f && f.price && typeof f.price === 'object') ? f.price : ctx.opts.price;
	}
	function formatInfo(f) {
		var box = el('div', { class: 'bgdvzh-fmtinfo' });
		if (f.written) {
			var w = f.written;
			var txt = 'Письменный ответ' + (w.scope ? ': ' + String(w.scope) : '');
			box.appendChild(el('p', { text: txt }));
			var n = Number(w.followups) || 0;
			if (n > 0) {
				var d = Number(w.followup_days) || 0;
				box.appendChild(el('p', { text: (n === 1 ? 'Одно уточнение' : n + ' ' + plural(n, 'уточнение', 'уточнения', 'уточнений')) + (d ? ' в течение ' + d + ' ' + plural(d, 'дня', 'дней', 'дней') : '') }));
			}
		} else if (f.duration_min) {
			var m = Number(f.duration_min);
			box.appendChild(el('p', { text: 'Длительность: ' + m + ' ' + plural(m, 'минута', 'минуты', 'минут') }));
		}
		if (f.initiator_text) { box.appendChild(el('p', { class: 'bgdvzh-hint', text: String(f.initiator_text) })); }
		return box;
	}
	function contactSpec(format) {
		if (format === 'telegram') { return { label: 'Telegram: @username или телефон', hint: 'Например @username или +359…', type: 'text', ac: 'off' }; }
		if (format === 'whatsapp') { return { label: 'Телефон WhatsApp', hint: 'Телефон с кодом страны, например +359…', type: 'tel', ac: 'tel' }; }
		if (format === 'phone') { return { label: 'Телефон для звонка', hint: 'Телефон с кодом страны, например +359…', type: 'tel', ac: 'tel' }; }
		return null;
	}
	function validEmail(v) { return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v); }
	function normalizePhone(v) { return String(v || '').replace(/[\s\-().]/g, ''); }

	function openConsultFlow(ctx) {
		var s = {
			step: 1, format: ctx.opts.formats[0].key, mode: '', picker: null, tz: '', sel: null,
			contact: '', email: '', question: '', err: {}, msg: '', busy: false, hold: null, heldKey: ''
		};
		var panel = el('section', { class: 'bgdvzh-flow', 'aria-label': 'Запись на консультацию' });

		function fmt() {
			var list = ctx.opts.formats;
			for (var i = 0; i < list.length; i++) { if (list[i].key === s.format) { return list[i]; } }
			return list[0];
		}
		function modes(f) { return Array.isArray(f.modes) && f.modes.length ? f.modes : [f.written ? 'written' : 'call']; }
		function ensureMode() {
			var ms = modes(fmt());
			if (ms.indexOf(s.mode) === -1) { s.mode = ms[0]; }
		}
		function isWritten() { return !!fmt().written; }
		function ensurePicker() {
			if (s.picker) { return; }
			s.picker = createPicker({
				format: s.format, written: isWritten(), tz: s.tz, sel: s.sel,
				onChange: function (sel, tz) { s.sel = sel; s.tz = tz; s.msg = ''; }
			});
			s.picker.start();
		}
		function close() {
			stopHold();
			if (panel.parentNode) { panel.parentNode.removeChild(panel); }
			if (ctx.btn) { ctx.btn.setAttribute('aria-expanded', 'false'); ctx.btn.focus(); }
			ctx.panel = null;
		}
		function stopHold() { if (holdTimer) { clearInterval(holdTimer); holdTimer = null; } }
		function reloadOptions() {
			return api('GET', 'consult/options?id=' + encodeURIComponent(ctx.id)).then(function (r) {
				if (r.ok && r.data && r.data.enabled && Array.isArray(r.data.formats) && r.data.formats.length) { ctx.opts = r.data; }
			});
		}
		function title() {
			if (s.step === 1) { return 'Способ консультации'; }
			if (s.step === 2) { return isWritten() ? 'Дата письменного ответа' : 'Дата и время'; }
			return 'Контакты и вопрос';
		}

		function paint(focus) {
			stopHold();
			ensureMode();
			clear(panel);
			panel.appendChild(el('p', { class: 'bgdvzh-progress-text', text: 'Шаг ' + s.step + ' из 3' }));
			panel.appendChild(el('h4', { class: 'bgdvzh-heading', tabindex: '-1', text: title() }));
			if (s.msg) { panel.appendChild(el('div', { class: 'bgdvzh-notice', role: 'alert' }, el('p', { text: s.msg }))); }
			if (s.step === 1) { stepFormat(); } else if (s.step === 2) { stepTime(); } else { stepContact(); }
			if (focus !== false) {
				var h = panel.querySelector('.bgdvzh-heading');
				if (h) { h.focus({ preventScroll: true }); }
			}
		}
		function go(n) {
			s.step = n;
			s.msg = '';
			s.err = {};
			paint();
			try { panel.scrollIntoView({ behavior: reduceMotion() ? 'auto' : 'smooth', block: 'start' }); } catch (e) { /* старые браузеры */ }
		}

		function stepFormat() {
			var f = fmt();
			var fs = el('fieldset', { class: 'bgdvzh-fieldset' }, el('legend', { text: 'Как удобнее провести консультацию?' }));
			ctx.opts.formats.forEach(function (x) {
				var rid = 'bgdvzh-cf-fmt-' + x.key;
				fs.appendChild(el('div', { class: 'bgdvzh-choice' },
					el('input', {
						type: 'radio', id: rid, name: 'bgdvzh-cf-format', value: x.key, checked: x.key === s.format,
						onchange: function () {
							s.format = x.key;
							s.mode = '';
							s.picker = null;
							s.sel = null;
							s.hold = null;
							paint(false);
							byId(rid).focus();
						}
					}),
					el('label', { for: rid, text: formatLabel(x.key, x) })));
			});
			panel.appendChild(fs);
			var ms = modes(f);
			if (ms.length > 1) {
				var mf = el('fieldset', { class: 'bgdvzh-fieldset' }, el('legend', { text: 'Режим' }));
				ms.forEach(function (m) {
					var mid = 'bgdvzh-cf-mode-' + m;
					mf.appendChild(el('div', { class: 'bgdvzh-choice' },
						el('input', {
							type: 'radio', id: mid, name: 'bgdvzh-cf-mode', value: m, checked: m === s.mode,
							onchange: function () { s.mode = m; s.hold = null; }
						}),
						el('label', { for: mid, text: MODE_LABELS[m] || m })));
				});
				panel.appendChild(mf);
			}
			panel.appendChild(formatInfo(f));
			panel.appendChild(priceBlock(priceOfFormat(ctx, f)));
			panel.appendChild(el('div', { class: 'bgdvzh-nav' },
				el('button', { type: 'button', class: 'bgdvzh-btn bgdvzh-btn-secondary', text: 'Закрыть', onclick: close }),
				el('button', { type: 'button', class: 'bgdvzh-btn', text: 'Далее', onclick: function () { go(2); } })));
		}

		function stepTime() {
			ensurePicker();
			panel.appendChild(el('p', { class: 'bgdvzh-hint', text: isWritten()
				? 'Выберите дату: письменный ответ придёт не позднее указанного срока.'
				: 'Время показано в выбранном часовом поясе, рядом указано время в Болгарии.' }));
			panel.appendChild(s.picker.node);
			panel.appendChild(el('div', { class: 'bgdvzh-nav' },
				el('button', { type: 'button', class: 'bgdvzh-btn bgdvzh-btn-secondary', text: 'Назад', onclick: function () { go(1); } }),
				el('button', {
					type: 'button', class: 'bgdvzh-btn', text: 'Далее',
					onclick: function () {
						if (!s.sel) { s.msg = isWritten() ? 'Выберите дату.' : 'Выберите время.'; paint(); return; }
						go(3);
					}
				})));
		}

		function field(key, label, o) {
			var id = 'bgdvzh-cf-' + key;
			var hid = id + '-hint';
			var eid = id + '-err';
			var ctrl;
			var common = {
				id: id, class: 'bgdvzh-input', value: s[key], maxlength: o.maxlength || 200,
				'aria-invalid': s.err[key] ? 'true' : null,
				'aria-describedby': (o.hint ? hid + ' ' : '') + (s.err[key] ? eid : '') || null
			};
			if (o.textarea) {
				common.rows = '5';
				common.oninput = function (e) {
					s[key] = e.target.value;
					var c = byId(id + '-count');
					if (c) { c.textContent = e.target.value.length + ' / ' + o.maxlength; }
				};
				ctrl = el('textarea', common);
			} else {
				common.type = o.type || 'text';
				common.autocomplete = o.ac || 'off';
				common.oninput = function (e) { s[key] = e.target.value; s.hold = null; };
				ctrl = el('input', common);
			}
			return el('div', { class: 'bgdvzh-field' },
				el('label', { for: id, text: label }),
				o.hint ? el('p', { class: 'bgdvzh-hint', id: hid, text: o.hint }) : null,
				ctrl,
				o.textarea ? el('p', { class: 'bgdvzh-hint bgdvzh-counter', id: id + '-count', text: s[key].length + ' / ' + o.maxlength }) : null,
				el('p', { class: 'bgdvzh-error', id: eid, text: s.err[key] || '', hidden: s.err[key] ? null : true }));
		}

		function summaryDl() {
			var f = fmt();
			var dl = el('dl', { class: 'bgdvzh-dl' });
			dl.appendChild(el('dt', { text: 'Способ' }));
			dl.appendChild(el('dd', { text: formatLabel(f.key, f) + (modes(f).length > 1 ? ', ' + (MODE_LABELS[s.mode] || '').toLowerCase() : '') }));
			if (s.sel) {
				if (s.sel.start_utc) {
					dl.appendChild(el('dt', { text: 'Время' }));
					dl.appendChild(el('dd', { text: fullLabel(s.sel.start_utc, s.tz) }));
					if (s.tz !== BG_TZ) {
						dl.appendChild(el('dt', { text: 'Время в Болгарии' }));
						dl.appendChild(el('dd', { text: fullLabel(s.sel.start_utc, BG_TZ) }));
					}
				} else {
					dl.appendChild(el('dt', { text: 'Ответ не позднее' }));
					dl.appendChild(el('dd', { text: s.sel.deadline_utc ? fullLabel(s.sel.deadline_utc, s.tz) : ymdLabel(s.sel.date) }));
				}
			}
			return dl;
		}

		function stepContact() {
			var f = fmt();
			var spec = contactSpec(f.key);
			panel.appendChild(summaryDl());
			panel.appendChild(priceBlock(priceOfFormat(ctx, f)));
			var form = el('form', { class: 'bgdvzh-step', novalidate: true, onsubmit: function (e) { e.preventDefault(); pay(); } });
			if (spec) { form.appendChild(field('contact', spec.label, { hint: spec.hint, type: spec.type, ac: spec.ac })); }
			form.appendChild(field('email', spec ? 'Email для подтверждения и чека' : 'Email', {
				type: 'email', ac: 'email',
				hint: f.key === 'meet' ? 'Приглашение в Google Meet придёт на этот адрес.' : (f.key === 'email' ? 'Письменный ответ придёт на этот адрес.' : 'На этот адрес придёт подтверждение записи.')
			}));
			form.appendChild(field('question', 'Главный вопрос / что хотите уточнить', { textarea: true, maxlength: 2000 }));
			var privacy = el('p', { class: 'bgdvzh-privacy' },
				'Контакт, email и вопрос нужны только для проведения консультации и не передаются в системы аналитики.');
			if (cfg.privacy_url) {
				privacy.appendChild(document.createTextNode(' '));
				privacy.appendChild(el('a', { href: cfg.privacy_url, target: '_blank', rel: 'noopener', text: 'Политика конфиденциальности' }));
			}
			form.appendChild(privacy);
			var holdInfo = el('p', { class: 'bgdvzh-hint bgdvzh-holdinfo', id: 'bgdvzh-cf-hold', hidden: true });
			form.appendChild(holdInfo);
			form.appendChild(el('div', { class: 'bgdvzh-nav' },
				el('button', { type: 'button', class: 'bgdvzh-btn bgdvzh-btn-secondary', text: 'Назад', disabled: s.busy, onclick: function () { go(2); } }),
				el('button', { type: 'submit', class: 'bgdvzh-btn', id: 'bgdvzh-cf-pay', disabled: s.busy, text: s.busy ? 'Переходим к оплате…' : 'Перейти к оплате' })));
			panel.appendChild(form);
			startHoldInfo(holdInfo);
		}

		/** Информационный отсчёт резерва: ничего не продлевает и не блокирует. */
		function startHoldInfo(node) {
			if (!s.hold || !s.hold.until) { return; }
			function tick() {
				var left = Math.floor((s.hold.until - Date.now()) / 1000);
				if (left <= 0) {
					node.textContent = 'Резерв времени истёк: при оплате время будет зарезервировано заново, если оно ещё свободно.';
					stopHold();
					s.hold = null;
					return;
				}
				node.textContent = 'Время зарезервировано ещё ' + pad(Math.floor(left / 60)) + ':' + pad(left % 60) + '.';
			}
			node.hidden = false;
			tick();
			if (s.hold) { holdTimer = setInterval(tick, 1000); }
		}

		function buildBody() {
			var f = fmt();
			var spec = contactSpec(f.key);
			var err = {};
			var email = s.email.trim();
			var contact = s.contact.trim();
			if (!validEmail(email)) { err.email = 'Укажите корректный адрес email.'; }
			if (f.key === 'telegram') {
				if (/^@?[A-Za-z0-9_]{5,32}$/.test(contact)) { contact = '@' + contact.replace(/^@/, ''); }
				else if (/^\+\d{8,15}$/.test(normalizePhone(contact))) { contact = normalizePhone(contact); }
				else { err.contact = 'Укажите Telegram в виде @username (5–32 символа: латиница, цифры, подчёркивание) или телефон в формате +359…'; }
			} else if (spec) {
				contact = normalizePhone(contact);
				if (!/^\+\d{8,15}$/.test(contact)) { err.contact = 'Укажите телефон в международном формате: + и от 8 до 15 цифр.'; }
			} else {
				contact = '';
			}
			if (s.question.length > 2000) { err.question = 'Вопрос слишком длинный: не более 2000 символов.'; }
			s.err = err;
			if (Object.keys(err).length) { return null; }
			var body = {
				id: ctx.id, format: f.key, mode: s.mode, contact: contact, email: email,
				question: s.question, visitor_tz: s.tz || defaultTz()
			};
			if (f.written) { body.date = s.sel.date; } else { body.start_utc = s.sel.start_utc; }
			return body;
		}

		function focusFirstError() {
			var keys = ['contact', 'email', 'question'];
			for (var i = 0; i < keys.length; i++) {
				if (s.err[keys[i]]) { var n = byId('bgdvzh-cf-' + keys[i]); if (n) { n.focus(); } return; }
			}
		}

		function requestHold(body, key, retried) {
			var tok = ctx.opts.csrf && ctx.opts.csrf.hold;
			return api('POST', 'consult/hold', body, { csrf: tok }).then(function (res) {
				var b = res.data && res.data.booking;
				if (res.ok && b && b.id) {
					s.hold = { id: String(b.id), csrf: res.data.csrf || {}, until: (Number(b.hold_until) || 0) * 1000 };
					s.heldKey = key;
					return true;
				}
				if (res.status === 403 && !retried) {
					return reloadOptions().then(function () { return requestHold(body, key, true); });
				}
				failHold(res);
				return false;
			});
		}
		function failHold(res) {
			var d = res.data || {};
			var code = d.code || '';
			s.hold = null;
			s.msg = errMessage(res);
			if (res.status === 422 && d.errors && typeof d.errors === 'object') {
				s.err = {};
				['contact', 'email', 'question'].forEach(function (k) { if (typeof d.errors[k] === 'string') { s.err[k] = d.errors[k]; } });
				var timeErr = ['start_utc', 'date', 'mode', 'visitor_tz'].filter(function (k) { return d.errors[k]; });
				if (timeErr.length && !Object.keys(s.err).length) {
					s.msg = String(d.errors[timeErr[0]]);
					s.step = timeErr[0] === 'mode' ? 1 : 2;
				}
				return;
			}
			if (['time_taken', 'time_unavailable', 'date_full', 'date_unavailable'].indexOf(code) !== -1) {
				s.step = 2;
				s.sel = null;
				if (s.picker) { s.picker.clearSel(); s.picker.reload(); }
				return;
			}
			if (code === 'credit_busy') { reloadOptions(); }
		}
		function requestCheckout() {
			var h = s.hold;
			return api('POST', 'consult/checkout', { b: h.id }, { csrf: h.csrf && h.csrf.checkout }).then(function (res) {
				var d = res.data || {};
				if (res.ok && typeof d.redirect === 'string' && /^https?:\/\//i.test(d.redirect)) {
					try { window.sessionStorage.setItem(BOOKING_CO_KEY, h.id); } catch (e) { /* без хранилища */ }
					track('bgdvzh_checkout_opened', 'consultation');
					window.location.assign(d.redirect);
					return true;
				}
				s.busy = false;
				if (res.status === 409 && d.code === 'in_progress') {
					openBooking(h.id);
					return false;
				}
				if (res.status === 409 && d.code === 'price_changed') {
					s.hold = null;
					s.msg = 'Цена изменилась. Проверьте новую стоимость и нажмите «Перейти к оплате» ещё раз.';
					return reloadOptions().then(function () { return false; });
				}
				if (res.status === 409 && d.code === 'hold_expired') {
					s.hold = null;
					s.msg = 'Время резерва истекло. Нажмите «Перейти к оплате», чтобы зарезервировать время заново.';
					return false;
				}
				s.msg = res.status === 0 ? 'Не удалось перейти к оплате. Время зарезервировано, попробуйте ещё раз.' : errMessage(res);
				return false;
			});
		}
		/** Оплата без успешного резерва не запускается: сначала hold, затем checkout. */
		function pay() {
			if (s.busy) { return; }
			s.msg = '';
			var body = buildBody();
			if (!body) {
				paint(false);
				focusFirstError();
				return;
			}
			s.busy = true;
			var btn = byId('bgdvzh-cf-pay');
			if (btn) { btn.disabled = true; btn.textContent = 'Переходим к оплате…'; }
			var key = JSON.stringify(body);
			var reuse = s.hold && s.heldKey === key && s.hold.until > Date.now() + 5000;
			(reuse ? Promise.resolve(true) : requestHold(body, key, false)).then(function (ok) {
				if (!ok) { s.busy = false; return null; }
				return requestCheckout();
			}).then(function (redirected) {
				if (redirected === true) { return; }
				s.busy = false;
				paint();
				if (s.step === 3) { focusFirstError(); }
			});
		}

		paint(false);
		return panel;
	}

	/* ---------- Экран записи ---------- */
	function openBooking(bid) {
		stopPoll();
		return api('GET', 'consult/booking?b=' + encodeURIComponent(bid)).then(function (res) {
			var b = res.data && res.data.booking;
			if (!res.ok || !b) {
				showInline(res.status === 404 ? 'Запись не найдена или нет доступа.' : errMessage(res));
				return false;
			}
			if (res.data.csrf) { bookingCsrf[b.id || bid] = res.data.csrf; }
			showBooking(b);
			return true;
		});
	}
	function bookingRow(dl, k, v) {
		if (v === '' || v === null || v === undefined) { return; }
		dl.appendChild(el('dt', { text: k }));
		dl.appendChild(el('dd', { text: String(v) }));
	}
	function showBooking(b, flash) {
		stopPoll();
		lastResult = null;
		clear(notice);
		clear(live);
		clear(main);
		var st = String(b.status || '');
		var vtz = tzOk(b.visitor_tz) ? b.visitor_tz : defaultTz();
		var written = b.kind === 'written' || !!b.written;
		var times = b.times || {};
		var wrap = el('div', { class: 'bgdvzh-booking' });
		wrap.appendChild(heading('Запись на консультацию'));
		if (flash) { wrap.appendChild(el('div', { class: 'bgdvzh-notice', role: 'status' }, el('p', { text: flash }))); }
		var ownsSnapshot = (state.snapshots || []).some(function (x) { return snapId(x) === String(b.snapshot || ''); });

		var tone = { confirmed: 'ok', needs_reschedule: 'check', held: 'check', pending: 'check', pending_payment: 'check', completed: 'ok' }[st] || 'info';
		var statusText = BOOKING_STATUS[st] || 'Статус записи обновляется';
		wrap.appendChild(el('p', { class: 'bgdvzh-status bgdvzh-status-' + tone, role: 'status' },
			PENDING_STATUSES.indexOf(st) !== -1 ? el('span', { class: 'bgdvzh-spinner', 'aria-hidden': 'true' }) : null,
			el('span', { text: statusText })));

		var showDetails = st === 'confirmed' || st === 'needs_reschedule' || st === 'completed';
		if (PENDING_STATUSES.indexOf(st) !== -1) {
			wrap.appendChild(el('p', { class: 'bgdvzh-hint', text: 'Обычно это занимает меньше минуты. Страница обновится сама.' }));
			startBookingPoll(wrap, b);
		} else if (st === 'cancelled') {
			wrap.appendChild(el('p', { text: 'Эта запись отменена.' }));
		} else if (st === 'refunded') {
			wrap.appendChild(el('p', { text: 'Оплата по этой записи возвращена, запись закрыта.' }));
		} else if (st === 'disputed') {
			wrap.appendChild(el('p', { text: 'Платёж по этой записи оспорен, запись приостановлена.' }));
		} else if (st === 'expired') {
			wrap.appendChild(el('p', { text: 'Время резерва истекло, запись не оформлена. Выберите время заново.' }));
		}

		if (showDetails) {
			if (st === 'confirmed' && trackBooking === (b.id || '')) { trackBooking = ''; track('bgdvzh_purchase_confirmed', 'consultation'); }
			var dl = el('dl', { class: 'bgdvzh-dl bgdvzh-booking-dl' });
			bookingRow(dl, 'Способ', formatLabel(b.format) + (b.mode && b.mode !== 'written' && MODE_LABELS[b.mode] && (b.format === 'telegram' || b.format === 'whatsapp') ? ', ' + MODE_LABELS[b.mode].toLowerCase() : ''));
			if (!written && times.start_utc) {
				bookingRow(dl, 'Время', fullLabel(times.start_utc, vtz));
				if (vtz !== BG_TZ) { bookingRow(dl, 'Время в Болгарии', fullLabel(times.start_utc, BG_TZ)); }
				if (b.duration_min) { bookingRow(dl, 'Длительность', b.duration_min + ' ' + plural(Number(b.duration_min), 'минута', 'минуты', 'минут')); }
			} else if (written && times.deadline_utc) {
				bookingRow(dl, 'Ответ не позднее', fullLabel(times.deadline_utc, vtz));
				if (b.written && b.written.scope) { bookingRow(dl, 'Состав ответа', b.written.scope); }
			}
			bookingRow(dl, 'Контакт', b.contact);
			bookingRow(dl, 'Email', b.email);
			bookingRow(dl, 'Ваш вопрос', b.question);
			wrap.appendChild(dl);
			if (b.initiator_text) { wrap.appendChild(el('p', { class: 'bgdvzh-hint', text: String(b.initiator_text) })); }
			if (b.format === 'meet') {
				if (typeof b.meet_url === 'string' && /^https:\/\/meet\.google\.com\/[a-z]{3}-[a-z]{4}-[a-z]{3}$/.test(b.meet_url)) {
					wrap.appendChild(el('p', null, el('a', { class: 'bgdvzh-btn', href: b.meet_url, target: '_blank', rel: 'noopener', text: 'Открыть Google Meet' })));
				} else {
					wrap.appendChild(el('p', { class: 'bgdvzh-note', text: 'Ссылка на Google Meet будет отправлена отдельно.' }));
				}
			}
		}

		var actions = el('div', { class: 'bgdvzh-actions' });
		if (st === 'confirmed' && !written && typeof b.ics_url === 'string' && /^https?:\/\//i.test(b.ics_url)) {
			var icsHref = b.ics_url + (cfg.nonce ? (b.ics_url.indexOf('?') === -1 ? '?' : '&') + '_wpnonce=' + encodeURIComponent(cfg.nonce) : '');
			actions.appendChild(el('a', { class: 'bgdvzh-btn bgdvzh-btn-secondary', href: icsHref, text: 'Скачать .ics' }));
		}
		if (b.instruction && b.instruction.available && b.instruction.result_id) {
			actions.appendChild(el('button', {
				type: 'button', class: 'bgdvzh-btn', text: 'Открыть инструкцию',
				onclick: function () { openResult(String(b.instruction.result_id)); }
			}));
		} else if (ownsSnapshot) {
			actions.appendChild(el('button', {
				type: 'button', class: 'bgdvzh-btn bgdvzh-btn-secondary', text: 'К результату',
				onclick: function () { openResult(String(b.snapshot)); }
			}));
		}
		if (actions.firstChild) { wrap.appendChild(actions); }

		if (st === 'confirmed') {
			wrap.appendChild(el('p', { class: 'bgdvzh-hint', text: 'Чтобы перенести или отменить запись, напишите на ' + (b.contact_email || 'почту организатора') + '.' }));
		} else if (st === 'needs_reschedule') {
			wrap.appendChild(el('p', { class: 'bgdvzh-hint', text: 'Оплата получена, но выбранное время уже занято. Консультант свяжется с вами и предложит новое время. Персональная инструкция уже доступна.' }));
		}
		main.appendChild(wrap);
		scrollToApp();
		focusHeading();
	}
	function startBookingPoll(wrap, b) {
		var started = Date.now();
		var box = el('div', { class: 'bgdvzh-actions' });
		wrap.appendChild(box);
		pollTimer = setInterval(function () {
			if (Date.now() - started > 180000) {
				stopPoll();
				clear(box);
				box.appendChild(el('p', { text: 'Подтверждение оплаты пока не получено.' }));
				box.appendChild(el('button', { type: 'button', class: 'bgdvzh-btn', text: 'Проверить ещё раз', onclick: function () { openBooking(String(b.id)); } }));
				return;
			}
			api('GET', 'consult/booking?b=' + encodeURIComponent(b.id)).then(function (res) {
				var nb = res.data && res.data.booking;
				if (!res.ok || !nb || !pollTimer) { return; }
				if (res.data.csrf) { bookingCsrf[nb.id || b.id] = res.data.csrf; }
				if (PENDING_STATUSES.indexOf(String(nb.status)) === -1) { showBooking(nb); }
			});
		}, 5000);
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
					s.payment === 'paid' ? el('span', { class: 'bgdvzh-snap-paid', text: 'Инструкция оплачена' }) : null,
					s.payment === 'admin' ? el('span', { class: 'bgdvzh-snap-paid', text: 'Доступ администратора' }) : null),
				el('button', {
					type: 'button', class: 'bgdvzh-btn bgdvzh-btn-secondary bgdvzh-btn-small', text: 'Открыть',
					'aria-label': 'Открыть результат от ' + orDash(s.date || s.created_at),
					onclick: function () { openResult(sid); }
				})));
			consultationItems(s.consultations).forEach(function (li) { ul.appendChild(li); });
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
		var bToken = null;
		var coBooking = null;
		try {
			bToken = window.sessionStorage.getItem(BOOKING_TOKEN_KEY);
			coBooking = window.sessionStorage.getItem(BOOKING_CO_KEY);
			window.sessionStorage.removeItem(BOOKING_CO_KEY);
		} catch (e) { bToken = null; coBooking = null; }
		var start = Promise.resolve(null);
		var openBookingId = '';
		if (token) {
			start = api('POST', 'grant', { token: token }).then(function (res) {
				try { window.sessionStorage.removeItem(TOKEN_KEY); } catch (e) { /* без хранилища */ }
				return res.ok ? snapId(res.data) : null;
			});
		}
		if (bToken) {
			// Ссылка управления записью из письма: обмен токена на grant-cookie, затем экран записи.
			start = start.then(function (prev) {
				return api('POST', 'grant', { token: bToken, kind: 'booking' }).then(function (res) {
					try { window.sessionStorage.removeItem(BOOKING_TOKEN_KEY); } catch (e) { /* без хранилища */ }
					if (res.ok && res.data) {
						var bk = res.data.booking;
						openBookingId = typeof bk === 'string' ? bk : String((bk && bk.id) || '');
					}
					return prev;
				});
			});
		} else if (coBooking && /^[A-Za-z0-9_-]{6,80}$/.test(coBooking)) {
			openBookingId = coBooking;
			trackBooking = coBooking;
		}
		start.then(function (grantId) {
			return refreshState().then(function () {
				if (state.draft && state.draft.data) { applyDraft(state.draft); }
				if (openBookingId) {
					return openBooking(openBookingId).then(function (ok) { if (!ok) { renderStart(); } });
				}
				if (!grantId && coId) {
					// Возврат с оплаты: результат открывается, только если покупка уже начата (оплачена или ждёт подтверждения).
					(state.snapshots || []).forEach(function (s) {
						if (snapId(s) === coId && (s.payment === 'paid' || s.payment === 'pending')) { grantId = coId; trackPaid = coId; }
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
