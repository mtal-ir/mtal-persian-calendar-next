/**
 * MTAL Persian Calendar Coordinator, Guest Counter Modal (+/-),
 * AJAX Rules Sync, Hidden Form Fields & Admin Rules Manager
 */
(function () {
	'use strict';

	if (typeof window.MTALPersianCalendar !== 'function') {
		return;
	}

	const cfg = window.mtalCalendarConfig || {};
	const globalMaxMonths = cfg.maxMonths ? parseInt(cfg.maxMonths, 10) : 3;

	const VALID_MODES = ['inline', 'inline_2col', 'modal_3months'];
	const instancesByKey = new Map();
	const calendarEntries = [];
	let isSyncing = false;

	let guestModalBackdrop = null;
	let activeGuestContext = null;

	function toPersianDigits(input) {
		const map = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
		return String(input).replace(/\d/g, (d) => map[Number(d)]);
	}

	function toEnglishDigits(input) {
		const persianMap = {
			'۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4',
			'۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
			'٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4',
			'٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9',
		};
		return String(input).replace(/[۰-۹٠-٩]/g, (ch) => persianMap[ch] || ch);
	}

	function parsePositiveInt(val, fallback = 0) {
		if (val === undefined || val === null || val === '') return fallback;
		const cleaned = toEnglishDigits(String(val)).replace(/[^\d-]/g, '');
		const num = parseInt(cleaned, 10);
		return Number.isNaN(num) ? fallback : num;
	}

	function getStorageKey(scope, targetSelector) {
		const cleanScope = scope || 'default';
		const cleanTarget = targetSelector || 'default_target';
		return `mtal_cal_range__${cleanScope}__${cleanTarget}`;
	}

	function getGuestsStorageKey(scope, targetSelector) {
		const cleanScope = scope || 'default';
		const cleanTarget = targetSelector || 'default_target';
		return `mtal_cal_guests__${cleanScope}__${cleanTarget}`;
	}

	function parseJsonAttr(raw, fallback) {
		if (!raw) return fallback;
		try {
			const parsed = JSON.parse(raw);
			return parsed !== null && parsed !== undefined ? parsed : fallback;
		} catch (e) {
			return fallback;
		}
	}

	function parseRangeString(raw) {
		if (!raw || typeof raw !== 'string') return null;
		const matches = raw.match(/\d{4}\/\d{1,2}\/\d{1,2}/g);
		if (!matches || !matches.length) return null;

		const parsePart = (s) => {
			const p = s.split('/').map((n) => parseInt(n, 10));
			if (p.length !== 3 || !p[0] || !p[1] || !p[2]) return null;
			return { jy: p[0], jm: p[1], jd: p[2] };
		};

		const start = parsePart(matches[0]);
		const end = matches.length > 1 ? parsePart(matches[1]) : null;
		return start ? { start, end } : null;
	}

	function savePersistedValue(storageKey, valueStr) {
		try {
			if (valueStr !== '' && valueStr !== null && valueStr !== undefined) {
				window.localStorage.setItem(storageKey, String(valueStr));
			} else {
				window.localStorage.removeItem(storageKey);
			}
		} catch (e) {}
	}

	function readPersistedValue(storageKey) {
		try {
			return window.localStorage.getItem(storageKey) || '';
		} catch (e) {
			return '';
		}
	}

	function resolveSuiteMaxCapacity(host, fallbackMax) {
		const attrMax = parsePositiveInt(host.getAttribute('data-mtal-max-guests') || cfg.maxGuests, 0);
		if (attrMax > 0) return attrMax;

		const card =
			host.closest('.jet-listing-grid__item, .elementor-grid-item, .elementor-element, .post-item') ||
			host.closest('form');
		const searchContext = card || document.body;
		const suiteLink = searchContext.querySelector('a.mtal-suite-link, a[href*="suite"], .elementor-post__title a, a');
		const cardText = searchContext.innerText || '';
		const urlString = suiteLink ? suiteLink.href : window.location.href;

		if (
			urlString.includes('suite-one') ||
			urlString.includes('suite-two') ||
			cardText.includes('سوئیت ۱') ||
			cardText.includes('سوئیت ۲')
		) {
			return 3;
		}
		if (
			urlString.includes('suite-three') ||
			urlString.includes('suite-four') ||
			cardText.includes('سوئیت ۳') ||
			cardText.includes('سوئیت ۴')
		) {
			return 4;
		}
		if (urlString.includes('suite-five') || cardText.includes('سوئیت ۵')) {
			return 5;
		}

		return Math.max(1, parsePositiveInt(fallbackMax, 5));
	}

	function setElementValue(el, outVal) {
		if (!el) return;
		const strVal = outVal !== undefined && outVal !== null ? String(outVal) : '';
		if (
			'value' in el &&
			(el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT')
		) {
			if (el.value !== strVal) {
				el.value = strVal;
				el.dispatchEvent(new Event('input', { bubbles: true }));
				el.dispatchEvent(new Event('change', { bubbles: true }));
			}
		} else {
			el.textContent = strVal;
		}
	}

	function writeValueToSelector(selector, outVal, scopeContainer = document) {
		if (!selector) return;
		let targets = scopeContainer.querySelectorAll(selector);
		if (!targets.length && scopeContainer !== document) {
			targets = document.querySelectorAll(selector);
		}
		targets.forEach((el) => setElementValue(el, outVal));
	}

	// پر کردن تمام فیلدهای مخفی فرم المنتور (تاریخ، مبلغ کل، نفر اضافه و تعداد نفرات)
	function syncAllHiddenFormFields(host, cal, payload) {
		const container = host.closest('form, .elementor-widget, .jet-listing-grid__item') || document;

		// ۱. فیلد تاریخ ورود و خروج
		const targetSelector = host.getAttribute('data-mtal-target') || '';
		const dateVal = payload && payload.value ? payload.value : '';
		writeValueToSelector(targetSelector, dateVal, container);

		// ۲. فیلد مبلغ کل
		const totalPriceSelector = host.getAttribute('data-mtal-target-total-price') || '';
		const totalPriceVal = payload && payload.totalPrice > 0 ? String(payload.totalPrice) : '';
		if (totalPriceSelector) {
			writeValueToSelector(totalPriceSelector, totalPriceVal, container);
		} else {
			container
				.querySelectorAll('#form-field-total_price, #form-field-mtal_total_price, input[name*="total_price"]')
				.forEach((el) => setElementValue(el, totalPriceVal));
		}

		// ۳. فیلد نفر اضافه (تعداد و مبلغ کل نفر اضافه)
		const extraGuestSelector = host.getAttribute('data-mtal-target-extra-guest') || '';
		let extraGuestSummary = '';
		if (payload && payload.checkIn && payload.checkOut) {
			if (payload.extraGuests > 0 && payload.extraGuestsTotal > 0) {
				extraGuestSummary = `${payload.extraGuests} نفر اضافه (${payload.extraGuestsTotal.toLocaleString('en-US')} تومان)`;
			} else if (payload.extraGuests > 0) {
				extraGuestSummary = `${payload.extraGuests} نفر اضافه`;
			} else {
				extraGuestSummary = '0';
			}
		}

		if (extraGuestSelector) {
			writeValueToSelector(extraGuestSelector, extraGuestSummary, container);
		} else {
			container
				.querySelectorAll('#form-field-extra_guests, #form-field-mtal_extra_guests, input[name*="extra_guest"]')
				.forEach((el) => setElementValue(el, extraGuestSummary));
		}

		// ۴. فیلد تعداد نفرات کل
		const qtyInputs = container.querySelectorAll('#form-field-mtal_qty, input[name*="mtal_qty"]');
		qtyInputs.forEach((input) => {
			setElementValue(input, String(cal.guestsCount));
		});
	}

	function syncHostCounterDisplay(host, count) {
		const countEl = host.querySelector('.mtal-qty-val');
		const lblEl = host.querySelector('.mtal-guests-count-label');
		if (countEl) countEl.textContent = toPersianDigits(count);
		if (lblEl) lblEl.textContent = `${toPersianDigits(count)} نفر`;
	}

	function applyRulesToSuiteCalendars(postId, rules = {}) {
		const normalized = {};

		if (Array.isArray(rules.blockedDates)) {
			normalized.blockedDates = rules.blockedDates;
		} else if (Array.isArray(rules.blocked_dates)) {
			normalized.blockedDates = rules.blocked_dates;
		}

		if (Array.isArray(rules.holidayDates)) {
			normalized.holidayDates = rules.holidayDates;
		} else if (Array.isArray(rules.holiday_dates)) {
			normalized.holidayDates = rules.holiday_dates;
		}

		if (rules.manualPrices && typeof rules.manualPrices === 'object') {
			normalized.manualPrices = rules.manualPrices;
		} else if (rules.manual_prices && typeof rules.manual_prices === 'object') {
			normalized.manualPrices = rules.manual_prices;
		}

		const regRaw = rules.regularPrice !== undefined ? rules.regularPrice : rules.regular_price;
		if (regRaw !== undefined) {
			normalized.regularPrice = Math.max(0, parsePositiveInt(regRaw, 0));
			cfg.regularPrice = normalized.regularPrice;
		}

		const wkRaw = rules.weekendPrice !== undefined ? rules.weekendPrice : rules.weekend_price;
		if (wkRaw !== undefined) {
			normalized.weekendPrice = Math.max(0, parsePositiveInt(wkRaw, 0));
			cfg.weekendPrice = normalized.weekendPrice;
		}

		const capRaw = rules.baseCapacity !== undefined ? rules.baseCapacity : rules.base_capacity;
		if (capRaw !== undefined) {
			normalized.baseCapacity = Math.max(1, parsePositiveInt(capRaw, 2));
			cfg.baseCapacity = normalized.baseCapacity;
		}

		const extraRaw =
			rules.extraGuestPrice !== undefined
				? rules.extraGuestPrice
				: rules.extra_guest_price !== undefined
					? rules.extra_guest_price
					: rules.extraPersonPrice !== undefined
						? rules.extraPersonPrice
						: rules.extra_person_price;

		if (extraRaw !== undefined) {
			normalized.extraGuestPrice = Math.max(0, parsePositiveInt(extraRaw, 0));
			cfg.extraGuestPrice = normalized.extraGuestPrice;
		}

		calendarEntries.forEach((entry) => {
			const matchesPost = !postId || !entry.postId || Number(entry.postId) === Number(postId);
			if (matchesPost) {
				if (entry.host) {
					if (normalized.regularPrice !== undefined) {
						entry.host.setAttribute('data-mtal-regular-price', String(normalized.regularPrice));
					}
					if (normalized.weekendPrice !== undefined) {
						entry.host.setAttribute('data-mtal-weekend-price', String(normalized.weekendPrice));
					}
					if (normalized.baseCapacity !== undefined) {
						entry.host.setAttribute('data-mtal-base-capacity', String(normalized.baseCapacity));
					}
					if (normalized.extraGuestPrice !== undefined) {
						entry.host.setAttribute('data-mtal-extra-guest-price', String(normalized.extraGuestPrice));
					}
				}
				entry.cal.updateRules(normalized);
				entry.cal.emitChange(true);
				if (entry.host) {
					syncHostCounterDisplay(entry.host, entry.cal.guestsCount);
				}
			}
		});
	}

	window.MTALCalendarRefreshRules = function (rules, postId = 0) {
		if (!rules || typeof rules !== 'object') return;
		applyRulesToSuiteCalendars(postId || rules.postId || rules.post_id || 0, rules);
	};

	function ensureGuestModal() {
		if (guestModalBackdrop) return guestModalBackdrop;

		const backdrop = document.createElement('div');
		backdrop.className = 'mtal-cal-modal-backdrop mtal-guest-modal-backdrop';
		backdrop.setAttribute('role', 'dialog');
		backdrop.setAttribute('aria-modal', 'true');
		backdrop.setAttribute('dir', 'rtl');

		backdrop.addEventListener('click', (e) => {
			if (e.target === backdrop) {
				closeGuestModal();
			}
		});

		const sheet = document.createElement('div');
		sheet.className = 'mtal-cal-modal-sheet mtal-guest-modal-sheet';

		const header = document.createElement('div');
		header.className = 'mtal-cal-modal-header';
		header.innerHTML = `<div class="mtal-cal-modal-title">تعیین تعداد مسافران</div>`;

		const closeBtn = document.createElement('button');
		closeBtn.type = 'button';
		closeBtn.className = 'mtal-cal-modal-close';
		closeBtn.setAttribute('aria-label', 'بستن');
		closeBtn.textContent = '×';
		closeBtn.addEventListener('click', () => closeGuestModal());
		header.appendChild(closeBtn);

		const body = document.createElement('div');
		body.className = 'mtal-cal-modal-body';
		body.innerHTML = `
			<div class="mtal-guests-box is-inside-modal">
				<div class="mtal-guests-info">
					<span class="mtal-guests-icon" aria-hidden="true">👥</span>
					<div class="mtal-guests-texts">
						<span class="mtal-guests-title">تعداد مسافران</span>
						<span class="mtal-guests-sub mtal-modal-guest-sub"></span>
					</div>
				</div>
				<div class="mtal-guests-controls">
					<button type="button" class="mtal-qty-btn mtal-modal-qty-plus" aria-label="افزایش تعداد مسافران">+</button>
					<span class="mtal-qty-val mtal-modal-qty-val"></span>
					<button type="button" class="mtal-qty-btn mtal-modal-qty-minus" aria-label="کاهش تعداد مسافران">−</button>
				</div>
			</div>
		`;

		const footer = document.createElement('div');
		footer.className = 'mtal-cal-modal-footer';

		const confirmBtn = document.createElement('button');
		confirmBtn.type = 'button';
		confirmBtn.className = 'mtal-cal-confirm-btn mtal-modal-guest-confirm';
		confirmBtn.textContent = 'تایید تعداد مسافران';
		footer.appendChild(confirmBtn);

		sheet.appendChild(header);
		sheet.appendChild(body);
		sheet.appendChild(footer);
		backdrop.appendChild(sheet);
		document.body.appendChild(backdrop);

		const plusBtn = backdrop.querySelector('.mtal-modal-qty-plus');
		const minusBtn = backdrop.querySelector('.mtal-modal-qty-minus');
		const valSpan = backdrop.querySelector('.mtal-modal-qty-val');
		const subSpan = backdrop.querySelector('.mtal-modal-guest-sub');

		let tempCount = 1;

		function refreshModalCounter() {
			if (!activeGuestContext) return;
			const maxCap = activeGuestContext.maxGuests;
			const baseCap = activeGuestContext.cal.baseCapacity;
			const extraPrice = activeGuestContext.cal.extraGuestPrice;

			valSpan.textContent = toPersianDigits(tempCount);
			let hint = `${toPersianDigits(tempCount)} نفر (ظرفیت پایه: ${toPersianDigits(baseCap)} نفر)`;
			if (tempCount > baseCap && extraPrice > 0) {
				hint += ` · ${toPersianDigits(tempCount - baseCap)} نفر اضافه (${toPersianDigits(extraPrice.toLocaleString('en-US'))} تومان/شب)`;
			}
			subSpan.textContent = hint;

			minusBtn.disabled = tempCount <= 1;
			plusBtn.disabled = tempCount >= maxCap;
		}

		plusBtn.addEventListener('click', () => {
			if (!activeGuestContext) return;
			if (tempCount < activeGuestContext.maxGuests) {
				tempCount += 1;
				refreshModalCounter();
			}
		});

		minusBtn.addEventListener('click', () => {
			if (tempCount > 1) {
				tempCount -= 1;
				refreshModalCounter();
			}
		});

		confirmBtn.addEventListener('click', () => {
			if (activeGuestContext) {
				activeGuestContext.onConfirm(tempCount);
			}
			closeGuestModal();
		});

		backdrop._openWith = (ctx) => {
			activeGuestContext = ctx;
			tempCount = Math.min(ctx.maxGuests, Math.max(1, ctx.cal.guestsCount));
			refreshModalCounter();
			backdrop.classList.add('is-open');
			document.documentElement.classList.add('mtal-modal-open');
		};

		guestModalBackdrop = backdrop;
		return backdrop;
	}

	function openGuestModalForHost(host, cal, maxGuests, onConfirm) {
		const modal = ensureGuestModal();
		modal._openWith({ host, cal, maxGuests, onConfirm });
	}

	function closeGuestModal() {
		if (!guestModalBackdrop) return;
		guestModalBackdrop.classList.remove('is-open');
		activeGuestContext = null;
		const anyOpen = document.querySelector('.mtal-cal-modal-backdrop.is-open');
		if (!anyOpen) {
			document.documentElement.classList.remove('mtal-modal-open');
		}
	}

	function syncSiblings(key, sourceInstance, payload, storageKey, guestsStorageKey) {
		if (isSyncing) return;
		isSyncing = true;

		try {
			const valStr = payload && payload.value ? payload.value : '';
			savePersistedValue(storageKey, valStr);
			if (payload && payload.guestsCount) {
				savePersistedValue(guestsStorageKey, String(payload.guestsCount));
			}

			const group = instancesByKey.get(key) || [];
			group.forEach((item) => {
				if (item.cal !== sourceInstance) {
					if (payload && payload.checkIn) {
						item.cal.setRange(payload.checkIn, payload.checkOut, {
							notify: false,
							syncDraft: true,
						});
					} else {
						item.cal.clear({ notify: false });
					}
					if (payload && payload.guestsCount) {
						item.cal.setGuestsCount(payload.guestsCount, { notify: false });
					}
					if (item.host) {
						syncAllHiddenFormFields(item.host, item.cal, payload);
						syncHostCounterDisplay(item.host, item.cal.guestsCount);
					}
				}
			});
		} finally {
			isSyncing = false;
		}
	}

	function buildGuestCounterBox(host, cal, maxGuests) {
		const showCounter = host.getAttribute('data-mtal-show-guests') !== '0';
		if (!showCounter) return;

		const existing = host.querySelector('.mtal-guests-box:not(.is-inside-modal)');
		if (existing) existing.remove();

		const box = document.createElement('div');
		box.className = 'mtal-guests-box';
		box.setAttribute('dir', 'rtl');

		const infoWrap = document.createElement('div');
		infoWrap.className = 'mtal-guests-info';
		infoWrap.innerHTML = `
			<span class="mtal-guests-icon" aria-hidden="true">👥</span>
			<div class="mtal-guests-texts">
				<span class="mtal-guests-title">تعداد مسافران</span>
				<span class="mtal-guests-sub mtal-guests-count-label">${toPersianDigits(cal.guestsCount)} نفر</span>
			</div>
		`;

		const controlsWrap = document.createElement('div');
		controlsWrap.className = 'mtal-guests-controls';

		const plusBtn = document.createElement('button');
		plusBtn.type = 'button';
		plusBtn.className = 'mtal-qty-btn mtal-qty-plus';
		plusBtn.setAttribute('aria-label', 'افزایش تعداد مسافران');
		plusBtn.textContent = '+';

		const valSpan = document.createElement('span');
		valSpan.className = 'mtal-qty-val';
		valSpan.textContent = toPersianDigits(cal.guestsCount);

		const minusBtn = document.createElement('button');
		minusBtn.type = 'button';
		minusBtn.className = 'mtal-qty-btn mtal-qty-minus';
		minusBtn.setAttribute('aria-label', 'کاهش تعداد مسافران');
		minusBtn.textContent = '−';

		function refreshCounterUI() {
			valSpan.textContent = toPersianDigits(cal.guestsCount);
			const lbl = box.querySelector('.mtal-guests-count-label');
			if (lbl) {
				lbl.textContent = `${toPersianDigits(cal.guestsCount)} نفر`;
			}
			minusBtn.disabled = cal.guestsCount <= 1;
			plusBtn.disabled = cal.guestsCount >= maxGuests;
		}

		plusBtn.addEventListener('click', () => {
			if (cal.guestsCount < maxGuests) {
				cal.setGuestsCount(cal.guestsCount + 1, { notify: true });
				refreshCounterUI();
			}
		});

		minusBtn.addEventListener('click', () => {
			if (cal.guestsCount > 1) {
				cal.setGuestsCount(cal.guestsCount - 1, { notify: true });
				refreshCounterUI();
			}
		});

		controlsWrap.appendChild(plusBtn);
		controlsWrap.appendChild(valSpan);
		controlsWrap.appendChild(minusBtn);

		box.appendChild(infoWrap);
		box.appendChild(controlsWrap);

		host.appendChild(box);
		refreshCounterUI();

		const container = host.closest('form, .elementor-widget, .jet-listing-grid__item') || document;
		const qtyInputs = container.querySelectorAll('#form-field-mtal_qty, input[name*="mtal_qty"]');
		qtyInputs.forEach((input) => {
			input.setAttribute('min', '1');
			input.setAttribute('max', String(maxGuests));
			const onExternalQty = () => {
				let v = parsePositiveInt(input.value, NaN);
				if (Number.isNaN(v)) return;
				if (v > maxGuests) v = maxGuests;
				if (v < 1) v = 1;
				input.value = String(v);
				cal.setGuestsCount(v, { notify: true });
				refreshCounterUI();
			};
			input.addEventListener('input', onExternalQty);
			input.addEventListener('change', onExternalQty);
		});
	}

	function initCalendarHosts(root = document) {
		const hosts = root.querySelectorAll(
			'.mtal-cal-inline-host:not([data-mtal-initialized="1"]), .mtal-cal-modal-host:not([data-mtal-initialized="1"])'
		);

		hosts.forEach((host) => {
			host.setAttribute('data-mtal-initialized', '1');

			const modeAttr = host.getAttribute('data-mtal-mode');
			const mode = VALID_MODES.includes(modeAttr) ? modeAttr : 'inline';
			const mountEl = host.querySelector('.mtal-cal-inline-mount') || host;
			const targetSelector = host.getAttribute('data-mtal-target') || '';
			const scope = host.getAttribute('data-mtal-storage-scope') || 'global';
			const triggerLabel = host.getAttribute('data-mtal-trigger-label') || 'انتخاب تاریخ سفر';
			const attrMax = parsePositiveInt(host.getAttribute('data-mtal-max-months'), NaN);
			const maxMonths = !Number.isNaN(attrMax) ? Math.max(1, Math.min(3, attrMax)) : globalMaxMonths;
			const postId = parsePositiveInt(host.getAttribute('data-mtal-post-id') || cfg.postId, 0);

			const blockedDates = parseJsonAttr(host.getAttribute('data-mtal-blocked-dates'), cfg.blockedDates || []);
			const holidayDates = parseJsonAttr(host.getAttribute('data-mtal-holiday-dates'), cfg.holidayDates || []);
			const manualPrices = parseJsonAttr(host.getAttribute('data-mtal-manual-prices'), cfg.manualPrices || {});
			const regularPrice = parsePositiveInt(
				host.getAttribute('data-mtal-regular-price') || cfg.regularPrice,
				0
			);
			const weekendPrice = parsePositiveInt(
				host.getAttribute('data-mtal-weekend-price') || cfg.weekendPrice,
				0
			);

			const baseCapacity = Math.max(
				1,
				parsePositiveInt(host.getAttribute('data-mtal-base-capacity') || cfg.baseCapacity, 2)
			);
			const extraGuestPrice = Math.max(
				0,
				parsePositiveInt(
					host.getAttribute('data-mtal-extra-guest-price') ||
						cfg.extraGuestPrice ||
						cfg.extra_guest_price,
					0
				)
			);
			const maxGuests = resolveSuiteMaxCapacity(host, cfg.maxGuests || 5);

			const groupKey = `${scope}::${targetSelector}`;
			const storageKey = getStorageKey(scope, targetSelector);
			const guestsStorageKey = getGuestsStorageKey(scope, targetSelector);

			const savedGuests = parsePositiveInt(readPersistedValue(guestsStorageKey), 0);
			const initialGuests = Math.min(
				maxGuests,
				Math.max(
					1,
					savedGuests > 0
						? savedGuests
						: parsePositiveInt(host.getAttribute('data-mtal-guests') || cfg.guestsCount, baseCapacity)
				)
			);

			const openGuestModalAfterCheckout =
				host.getAttribute('data-mtal-guest-modal-on-checkout') !== '0';

			const cal = new window.MTALPersianCalendar({
				mode,
				mountEl,
				triggerLabel,
				maxMonths,
				blockedDates,
				holidayDates,
				manualPrices,
				regularPrice,
				weekendPrice,
				baseCapacity,
				extraGuestPrice,
				guestsCount: initialGuests,
				onChange(payload) {
					if (payload && payload.checkIn && payload.checkOut && cfg.ajaxUrl && postId) {
						const requestId = (host._mtalPriceRequestId || 0) + 1;
						host._mtalPriceRequestId = requestId;
						payload.totalPrice = 0;
						syncAllHiddenFormFields(host, cal, payload);
						const fd = new FormData();
						fd.append('action', 'mtal_calculate_suite_price');
						fd.append('nonce', cfg.nonce || '');
						fd.append('post_id', String(postId));
						fd.append('check_in', payload.checkInSlash || '');
						fd.append('check_out', payload.checkOutSlash || '');
						fd.append('guests', String(cal.guestsCount || 1));
						fd.append('max_months', String(maxMonths));
						fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
							.then((response) => response.json())
							.then((result) => {
								if (host._mtalPriceRequestId !== requestId) return;
								if (!result || !result.success || !result.data) {
									payload.totalPrice = 0;
									host.dispatchEvent(new CustomEvent('mtal:serverPriceError', { detail: result && result.data ? result.data : {}, bubbles: true }));
									return;
								}
								payload.totalPrice = Number(result.data.totalPrice) || 0;
								payload.serverPricing = result.data;
								syncAllHiddenFormFields(host, cal, payload);
								host.dispatchEvent(new CustomEvent('mtal:serverPriceCalculated', { detail: result.data, bubbles: true }));
							})
							.catch(() => {
								if (host._mtalPriceRequestId !== requestId) return;
								payload.totalPrice = 0;
								host.dispatchEvent(new CustomEvent('mtal:serverPriceError', { detail: { message: 'محاسبه مبلغ در سرور انجام نشد.' }, bubbles: true }));
							});
					} else {
						host._mtalPriceRequestId = (host._mtalPriceRequestId || 0) + 1;
						syncAllHiddenFormFields(host, cal, payload);
					}
					syncSiblings(groupKey, cal, payload, storageKey, guestsStorageKey);
					syncHostCounterDisplay(host, cal.guestsCount);

					host.dispatchEvent(
						new CustomEvent('mtal:calendarChange', {
							detail: payload,
							bubbles: true,
						})
					);
				},
				onRangeCompleted(payload) {
					if (!openGuestModalAfterCheckout || !payload || !payload.checkOut) return;
					window.setTimeout(() => {
						openGuestModalForHost(host, cal, maxGuests, (selectedCount) => {
							cal.setGuestsCount(selectedCount, { notify: true });
							syncHostCounterDisplay(host, selectedCount);
						});
					}, 180);
				},
			});

			buildGuestCounterBox(host, cal, maxGuests);

			calendarEntries.push({ cal, postId, host });

			if (!instancesByKey.has(groupKey)) {
				instancesByKey.set(groupKey, []);
			}
			instancesByKey.get(groupKey).push({ cal, targetSelector, storageKey, guestsStorageKey, host });

			let savedRaw = readPersistedValue(storageKey);
			if (!savedRaw && targetSelector) {
				const targetEl = document.querySelector(targetSelector);
				if (targetEl && 'value' in targetEl && targetEl.value) {
					savedRaw = targetEl.value;
				}
			}

			if (savedRaw) {
				const parsed = parseRangeString(savedRaw);
				if (parsed && parsed.start) {
					cal.setRange(parsed.start, parsed.end, {
						notify: true,
						syncDraft: true,
					});
				}
			} else {
				syncAllHiddenFormFields(host, cal, cal.buildPayload(null, null));
			}
		});
	}

	function initAdminHosts(root = document) {
		const adminHosts = root.querySelectorAll('.mtal-admin-cal-host:not([data-mtal-admin-init="1"])');
		if (!adminHosts.length) return;

		const helperCal = new window.MTALPersianCalendar({
			mode: 'inline',
			mountEl: document.createElement('div'),
			maxMonths: 3,
		});

		adminHosts.forEach((host) => {
			host.setAttribute('data-mtal-admin-init', '1');
			const mount = host.querySelector('.mtal-admin-cal-mount') || host;
			const postId = parsePositiveInt(host.getAttribute('data-mtal-post-id') || cfg.postId, 0);

			let blockedList = Array.isArray(parseJsonAttr(host.getAttribute('data-mtal-blocked-dates'), []))
				? [...parseJsonAttr(host.getAttribute('data-mtal-blocked-dates'), [])]
				: [];
			let holidayList = Array.isArray(parseJsonAttr(host.getAttribute('data-mtal-holiday-dates'), []))
				? [...parseJsonAttr(host.getAttribute('data-mtal-holiday-dates'), [])]
				: [];
			let manualPricesObj = Object.assign({}, parseJsonAttr(host.getAttribute('data-mtal-manual-prices'), {}));
			let regularPrice = parsePositiveInt(host.getAttribute('data-mtal-regular-price'), 0);
			let weekendPrice = parsePositiveInt(host.getAttribute('data-mtal-weekend-price'), 0);
			let baseCapacity = Math.max(1, parsePositiveInt(host.getAttribute('data-mtal-base-capacity'), 2));
			let extraGuestPrice = Math.max(0, parsePositiveInt(host.getAttribute('data-mtal-extra-guest-price'), 0));

			let actionMode = 'toggle_block';
			let viewYear = helperCal.today.jy;
			let viewMonth = helperCal.today.jm;

			function pad2(n) {
				return n < 10 ? '0' + n : String(n);
			}

			function toSlash(d) {
				return `${d.jy}/${pad2(d.jm)}/${pad2(d.jd)}`;
			}

			function isBlocked(s) {
				return blockedList.includes(s);
			}

			function isHoliday(s) {
				return holidayList.includes(s);
			}

			function toggleItem(arr, s) {
				const idx = arr.indexOf(s);
				if (idx >= 0) {
					arr.splice(idx, 1);
				} else {
					arr.push(s);
					arr.sort();
				}
			}

			function getCurrentRulesPayload() {
				return {
					postId,
					blockedDates: [...blockedList],
					holidayDates: [...holidayList],
					manualPrices: Object.assign({}, manualPricesObj),
					regularPrice,
					weekendPrice,
					baseCapacity,
					extraGuestPrice,
				};
			}

			function renderAdminUI() {
				mount.innerHTML = '';
				const box = document.createElement('div');
				box.className = 'mtal-admin-panel';
				box.setAttribute('dir', 'rtl');

				const modeBar = document.createElement('div');
				modeBar.className = 'mtal-admin-modes';
				modeBar.innerHTML = `
					<button type="button" class="mtal-admin-mode-btn ${actionMode === 'toggle_block' ? 'is-active' : ''}" data-mode="toggle_block">بستن / باز کردن روز</button>
					<button type="button" class="mtal-admin-mode-btn ${actionMode === 'toggle_holiday' ? 'is-active' : ''}" data-mode="toggle_holiday">تعطیلات رسمی (قرمز)</button>
					<button type="button" class="mtal-admin-mode-btn ${actionMode === 'set_manual_price' ? 'is-active' : ''}" data-mode="set_manual_price">قیمت دستی روز</button>
				`;

				modeBar.querySelectorAll('.mtal-admin-mode-btn').forEach((btn) => {
					btn.addEventListener('click', () => {
						actionMode = btn.getAttribute('data-mode');
						renderAdminUI();
					});
				});
				box.appendChild(modeBar);

				const nav = document.createElement('div');
				nav.className = 'mtal-cal-header';
				const nextBtn = document.createElement('button');
				nextBtn.type = 'button';
				nextBtn.className = 'mtal-cal-nav';
				nextBtn.setAttribute('aria-label', 'ماه بعد');
				nextBtn.textContent = '‹';
				nextBtn.addEventListener('click', () => {
					const total = viewYear * 12 + (viewMonth - 1) + 1;
					const maxIdx = helperCal.today.jy * 12 + (helperCal.today.jm - 1) + 2;
					if (total <= maxIdx) {
						viewYear = Math.floor(total / 12);
						viewMonth = (total % 12) + 1;
						renderAdminUI();
					}
				});

				const title = document.createElement('div');
				title.className = 'mtal-cal-month-title';
				const monthNames = [
					'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
					'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند',
				];
				title.textContent = `${monthNames[viewMonth - 1]} ${viewYear}`;

				const prevBtn = document.createElement('button');
				prevBtn.type = 'button';
				prevBtn.className = 'mtal-cal-nav';
				prevBtn.setAttribute('aria-label', 'ماه قبل');
				prevBtn.textContent = '›';
				prevBtn.addEventListener('click', () => {
					const total = viewYear * 12 + (viewMonth - 1) - 1;
					const minIdx = helperCal.today.jy * 12 + (helperCal.today.jm - 1);
					if (total >= minIdx) {
						viewYear = Math.floor(total / 12);
						viewMonth = (total % 12) + 1;
						renderAdminUI();
					}
				});

				nav.appendChild(nextBtn);
				nav.appendChild(title);
				nav.appendChild(prevBtn);
				box.appendChild(nav);

				const grid = document.createElement('div');
				grid.className = 'mtal-cal-days';
				const parts = toSlash({ jy: viewYear, jm: viewMonth, jd: 1 }).split('/').map(Number);
				const gFirst = (function () {
					const jdn = (function (jy, jm, jd) {
						const epbase = jy - (jy >= 0 ? 474 : 473);
						const epyear = 474 + (epbase - 2820 * Math.floor(epbase / 2820));
						return (
							jd +
							(jm <= 7 ? (jm - 1) * 31 : (jm - 1) * 30 + 6) +
							Math.floor((epyear * 682 - 110) / 2816) +
							(epyear - 1) * 365 +
							Math.floor(epbase / 2820) * 1029983 +
							1948320
						);
					})(parts[0], parts[1], 1);
					return (jdn + 2) % 7;
				})();

				const totalDays = (function (jy, jm) {
					if (jm >= 1 && jm <= 6) return 31;
					if (jm >= 7 && jm <= 11) return 30;
					const d1 = (function (y) {
						const epbase = y - (y >= 0 ? 474 : 473);
						const epyear = 474 + (epbase - 2820 * Math.floor(epbase / 2820));
						return 1 + Math.floor((epyear * 682 - 110) / 2816) + (epyear - 1) * 365 + Math.floor(epbase / 2820) * 1029983 + 1948320;
					})(jy);
					const d2 = (function (y) {
						const epbase = y + 1 - (y + 1 >= 0 ? 474 : 473);
						const epyear = 474 + (epbase - 2820 * Math.floor(epbase / 2820));
						return 1 + Math.floor((epyear * 682 - 110) / 2816) + (epyear - 1) * 365 + Math.floor(epbase / 2820) * 1029983 + 1948320;
					})(jy);
					return d2 - d1 === 366 ? 30 : 29;
				})(viewYear, viewMonth);

				for (let i = 0; i < gFirst; i++) {
					const emp = document.createElement('div');
					emp.className = 'mtal-cal-day is-empty';
					grid.appendChild(emp);
				}

				for (let d = 1; d <= totalDays; d++) {
					const dObj = { jy: viewYear, jm: viewMonth, jd: d };
					const s = toSlash(dObj);
					const btn = document.createElement('button');
					btn.type = 'button';
					btn.className = 'mtal-cal-day';
					btn.textContent = String(d);

					if (isBlocked(s)) btn.classList.add('is-blocked', 'is-disabled');
					if (isHoliday(s)) btn.classList.add('is-holiday');
					if (manualPricesObj[s]) btn.classList.add('has-manual-price');

					btn.addEventListener('click', () => {
						if (actionMode === 'toggle_block') {
							toggleItem(blockedList, s);
						} else if (actionMode === 'toggle_holiday') {
							toggleItem(holidayList, s);
						} else if (actionMode === 'set_manual_price') {
							const currentVal = manualPricesObj[s] || '';
							const input = window.prompt(
								`قیمت دستی برای تاریخ ${s} (تومان؛ خالی یا ۰ برای حذف):`,
								currentVal
							);
							if (input !== null) {
								const num = parsePositiveInt(input, 0);
								if (num > 0) {
									manualPricesObj[s] = num;
								} else {
									delete manualPricesObj[s];
								}
							}
						}
						renderAdminUI();
					});

					grid.appendChild(btn);
				}

				box.appendChild(grid);

				const priceWrap = document.createElement('div');
				priceWrap.className = 'mtal-admin-prices';
				priceWrap.innerHTML = `
					<label>قیمت معمولی (تومان): <input type="number" class="mtal-reg-price-input" value="${regularPrice}" min="0" /></label>
					<label>قیمت آخر هفته چهارشنبه/پنجشنبه (تومان): <input type="number" class="mtal-wk-price-input" value="${weekendPrice}" min="0" /></label>
					<label>ظرفیت پایه (نفر): <input type="number" class="mtal-base-cap-input" value="${baseCapacity}" min="1" /></label>
					<label>قیمت نفر اضافه هر شب (تومان): <input type="number" class="mtal-extra-guest-input" value="${extraGuestPrice}" min="0" /></label>
				`;

				const regInput = priceWrap.querySelector('.mtal-reg-price-input');
				const wkInput = priceWrap.querySelector('.mtal-wk-price-input');
				const capInput = priceWrap.querySelector('.mtal-base-cap-input');
				const extraInput = priceWrap.querySelector('.mtal-extra-guest-input');

				const syncInputsToState = () => {
					regularPrice = Math.max(0, parsePositiveInt(regInput.value, 0));
					weekendPrice = Math.max(0, parsePositiveInt(wkInput.value, 0));
					baseCapacity = Math.max(1, parsePositiveInt(capInput.value, 2));
					extraGuestPrice = Math.max(0, parsePositiveInt(extraInput.value, 0));
				};

				[regInput, wkInput, capInput, extraInput].forEach((inp) => {
					inp.addEventListener('input', syncInputsToState);
					inp.addEventListener('change', syncInputsToState);
				});
				box.appendChild(priceWrap);

				const saveRow = document.createElement('div');
				saveRow.className = 'mtal-admin-save-row';
				const saveBtn = document.createElement('button');
				saveBtn.type = 'button';
				saveBtn.className = 'mtal-cal-confirm-btn';
				saveBtn.textContent = 'ذخیره تنظیمات تقویم و قیمت';

				const statusMsg = document.createElement('div');
				statusMsg.className = 'mtal-admin-status';

				saveBtn.addEventListener('click', () => {
					syncInputsToState();
					const payload = getCurrentRulesPayload();
					applyRulesToSuiteCalendars(postId, payload);
					host.dispatchEvent(new CustomEvent('mtal:rulesUpdated', { detail: payload, bubbles: true }));

					if (!cfg.ajaxUrl || !postId) {
						statusMsg.textContent = 'تنظیمات روی تقویم‌های صفحه اعمال شد.';
						return;
					}

					const fd = new FormData();
					fd.append('action', 'mtal_save_suite_calendar_rules');
					fd.append('nonce', cfg.nonce || '');
					fd.append('post_id', String(postId));
					fd.append('blocked_dates', JSON.stringify(blockedList));
					fd.append('holiday_dates', JSON.stringify(holidayList));
					fd.append('manual_prices', JSON.stringify(manualPricesObj));
					fd.append('regular_price', String(regularPrice));
					fd.append('weekend_price', String(weekendPrice));
					fd.append('base_capacity', String(baseCapacity));
					fd.append('extra_guest_price', String(extraGuestPrice));

					saveBtn.disabled = true;
					fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
						.then((r) => r.json())
						.then((res) => {
							if (res && res.success) {
								const d = res.data || {};
								const savedObj = d.data && typeof d.data === 'object' ? d.data : d;
								applyRulesToSuiteCalendars(postId, {
									...payload,
									...savedObj,
								});
								statusMsg.textContent = d.message ? d.message : 'ذخیره شد.';
							} else {
								statusMsg.textContent =
									res && res.data && res.data.message ? res.data.message : 'خطا در ذخیره.';
							}
						})
						.catch(() => {
							statusMsg.textContent = 'خطای ارتباط با سرور.';
						})
						.finally(() => {
							saveBtn.disabled = false;
						});
				});

				saveRow.appendChild(saveBtn);
				box.appendChild(saveRow);
				box.appendChild(statusMsg);

				mount.appendChild(box);
			}

			renderAdminUI();
		});
	}

	document.addEventListener('mtal:rulesUpdated', (e) => {
		const detail = e && e.detail ? e.detail : null;
		if (detail && typeof detail === 'object') {
			applyRulesToSuiteCalendars(detail.postId || detail.post_id || 0, detail);
		}
	});

	document.addEventListener('click', (e) => {
		const btn = e.target.closest('.mtal-open-calendar-modal');
		if (!btn) return;
		e.preventDefault();

		for (const group of instancesByKey.values()) {
			const modalEntry = group.find((item) => item.cal.mode === 'modal_3months');
			if (modalEntry) {
				modalEntry.cal.openModal();
				break;
			}
		}
	});

	function bootAll(root = document) {
		initCalendarHosts(root);
		initAdminHosts(root);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', () => bootAll());
	} else {
		bootAll();
	}

	window.addEventListener('elementor/popup/show', (event) => {
		const popupEl =
			event && event.detail && event.detail.instance && event.detail.instance.$element
				? event.detail.instance.$element[0]
				: document;
		bootAll(popupEl);
	});

	if (window.jQuery) {
		window.jQuery(document).on(
			'submit_success elementor/popup/show jet-engine/ajax-filters/applied ajaxComplete',
			function (event, xhr) {
				if (xhr && xhr.responseText) {
					try {
						const parsed = JSON.parse(xhr.responseText);
						const outer = parsed && parsed.data && typeof parsed.data === 'object' ? parsed.data : parsed;
						const payload = outer && outer.data && typeof outer.data === 'object' ? outer.data : outer;
						if (
							payload &&
							(payload.extra_guest_price !== undefined ||
								payload.extraGuestPrice !== undefined ||
								payload.base_capacity !== undefined ||
								payload.baseCapacity !== undefined)
						) {
							applyRulesToSuiteCalendars(payload.post_id || payload.postId || 0, payload);
						}
					} catch (err) {}
				}
				setTimeout(() => bootAll(), 300);
			}
		);
	}
})();