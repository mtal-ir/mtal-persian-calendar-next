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

	function getActiveBookingForSuite(postId) {
		try {
			const active = JSON.parse(window.localStorage.getItem('mtal_booking_active_request') || 'null');
			if (!active || !active.orderId || !active.token || Number(active.deadline) <= Date.now()) return null;
			if (active.postId && String(active.postId) !== String(postId)) return null;
			return active;
		} catch (e) {
			return null;
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
		let strVal = outVal !== undefined && outVal !== null ? String(outVal) : '';
		if (
			'value' in el &&
			(el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT')
		) {
			// Numeric Elementor fields must never receive the human-readable guest summary.
			if (el.tagName === 'INPUT' && el.type === 'number' && strVal !== '') {
				const numericMatch = strVal.trim().match(/^-?\d+(?:\.\d+)?/);
				if (!numericMatch) return;
				strVal = numericMatch[0];
				const numericValue = Number(strVal);
				if ((el.min !== '' && numericValue < Number(el.min)) || (el.max !== '' && numericValue > Number(el.max))) {
					return;
				}
			}
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
		const bookingForm = host._mtalBookingForm || findBookingForm(host);
		const container = bookingForm || host.closest('.elementor-widget, .jet-listing-grid__item') || document;
		const ensureHiddenField = (id, name) => {
			if (!bookingForm) return null;
			let field = bookingForm.querySelector('#' + CSS.escape(id) + ', input[name="' + name + '"]');
			if (!field) {
				field = document.createElement('input');
				field.type = 'hidden';
				field.id = id;
				field.name = name;
				bookingForm.appendChild(field);
			}
			return field;
		};

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

		// شناسه سوئیت برای اتصال امن درخواست Elementor به قیمت‌گذاری سمت سرور.
		const suiteId = host.getAttribute('data-mtal-post-id') || '';
		if (suiteId) {
			const suiteField = ensureHiddenField('form-field-mtal_post_id', 'form_fields[mtal_post_id]');
			if (suiteField) setElementValue(suiteField, suiteId);
			else writeValueToSelector('#form-field-mtal_post_id, input[name="form_fields[mtal_post_id]"]', suiteId, container);
		}

		// ۴. فیلد تعداد نفرات کل
		let qtyInputs = container.querySelectorAll('#form-field-mtal_qty, input[name="form_fields[mtal_qty]"], input[name*="mtal_qty"]');
		if (!qtyInputs.length) {
			const qtyField = ensureHiddenField('form-field-mtal_qty', 'form_fields[mtal_qty]');
			if (qtyField) qtyInputs = [qtyField];
		}
		qtyInputs.forEach((input) => setElementValue(input, String(cal.guestsCount)));
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

	function buildExternalDateFields(host, cal) {
		const wrap = document.createElement('div');
		wrap.className = 'mtal-date-fields';
		wrap.setAttribute('dir', 'rtl');
		const makeField = (label, className) => {
			const field = document.createElement('button');
			field.type = 'button';
			field.className = 'mtal-date-field ' + className;
			field.setAttribute('aria-label', label + '؛ باز کردن تقویم');
			field.innerHTML = '<span class="mtal-date-field-label">' + label + '</span><span class="mtal-date-field-value">انتخاب تاریخ</span>';
			field.addEventListener('click', () => cal.openModal());
			return field;
		};
		const checkInField = makeField('تاریخ ورود', 'mtal-checkin-field');
		const checkOutField = makeField('تاریخ خروج', 'mtal-checkout-field');
		wrap.appendChild(checkInField);
		wrap.appendChild(checkOutField);
		host.appendChild(wrap);
		const update = (payload) => {
			const inValue = checkInField.querySelector('.mtal-date-field-value');
			const outValue = checkOutField.querySelector('.mtal-date-field-value');
			const checkIn = payload ? payload.checkIn : cal.startDate;
			const checkOut = payload ? payload.checkOut : cal.endDate;
			inValue.textContent = checkIn ? formatDateForDisplay(checkIn) : 'انتخاب تاریخ ورود';
			outValue.textContent = checkOut ? formatDateForDisplay(checkOut) : 'انتخاب تاریخ خروج';
			checkInField.classList.toggle('has-value', Boolean(checkIn));
			checkOutField.classList.toggle('has-value', Boolean(checkOut));
		};
		update();
		return update;
	}

	function buildExternalStaySummary(host, cal) {
		const summary = document.createElement('div');
		summary.className = 'mtal-external-stay-summary';
		summary.setAttribute('dir', 'rtl');
		summary.setAttribute('aria-live', 'polite');
		host.appendChild(summary);
		const update = (payload) => {
			const checkIn = payload ? payload.checkIn : cal.startDate;
			const checkOut = payload ? payload.checkOut : cal.endDate;
			summary.innerHTML = '';
			if (!checkIn || !checkOut || !cal.isRangeAllowed(checkIn, checkOut)) {
				const hint = document.createElement('div');
				hint.className = 'mtal-external-stay-hint';
				hint.textContent = checkIn ? 'تاریخ ورود ثبت شد؛ تاریخ خروج را انتخاب کنید.' : 'پس از انتخاب تاریخ ورود و خروج، جزئیات اقامت و قیمت نمایش داده می‌شود.';
				summary.appendChild(hint);
				return;
			}
			const breakdown = cal.createPricingBreakdownBox(checkIn, checkOut);
			if (breakdown) {
				const total = payload && Number(payload.totalPrice) > 0 ? Number(payload.totalPrice) : 0;
				const totalValue = breakdown.querySelector('.mtal-breakdown-row.is-total strong');
				if (total && totalValue) {
					totalValue.textContent = toPersianDigits(total.toLocaleString('en-US')) + ' تومان';
				}
				summary.appendChild(breakdown);
			}
		};
		update();
		return update;
	}

	function formatDateForDisplay(date) {
		if (!date) return '';
		const value = date.jy + '/' + String(date.jm).padStart(2, '0') + '/' + String(date.jd).padStart(2, '0');
		return value.replace(/[0-9]/g, digit => '۰۱۲۳۴۵۶۷۸۹'[Number(digit)]);
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

	function findBookingForm(host) {
		const directForm = host.closest('form');
		if (directForm) return directForm;

		const suiteId = host.getAttribute('data-mtal-post-id') || '';
		const scope = host.closest('.e-con, .elementor-section, .elementor-column, .elementor-widget-wrap, .elementor') || document;
		const forms = Array.from(scope.querySelectorAll('form.elementor-form, form'));
		const candidates = forms.length ? forms : Array.from(document.querySelectorAll('form.elementor-form, form'));
		if (!candidates.length) return null;

		const scored = candidates.map((form) => {
			let score = 0;
			if (form.querySelector('#form-field-mtal_qty, input[name*="mtal_qty"]')) score += 4;
			if (form.querySelector('#form-field-checkin_checkout, input[name*="checkin_checkout"], input[name*="date"]')) score += 2;
			const idField = form.querySelector('#form-field-mtal_post_id, input[name="form_fields[mtal_post_id]"]');
			if (suiteId && idField && String(idField.value) === String(suiteId)) score += 8;
			return { form, score };
		}).sort((a, b) => b.score - a.score);
		return scored[0] && scored[0].score > 0 ? scored[0].form : null;
	}

	function getRequiredFieldLabel(field) {
		const group = field.closest('.elementor-field-group');
		const label = group && group.querySelector('label');
		return label ? label.textContent.replace(/[*：:]/g, '').trim() : (field.getAttribute('placeholder') || field.name || 'یک فیلد ضروری');
	}

	function validateBookingForm(form, host, cal) {
		const missing = [];
		if (!cal.startDate || !cal.endDate || !cal.isRangeAllowed(cal.startDate, cal.endDate)) {
			missing.push('تاریخ ورود و خروج');
		}
		if (!form) missing.push('فرم رزرو');
		if (form) {
			const fields = Array.from(form.querySelectorAll('input[required], select[required], textarea[required]'));
			fields.forEach((field) => {
				if (field.disabled || field.type === 'hidden' || field.type === 'submit' || field.type === 'button' || !field.getClientRects().length) return;
				if (field.type === 'checkbox' || field.type === 'radio') {
					const group = form.querySelectorAll('[name="' + CSS.escape(field.name) + '"]');
					if (Array.from(group).some((item) => item.checked)) return;
				} else if (String(field.value || '').trim() !== '' && field.checkValidity()) {
					return;
				}
				const label = getRequiredFieldLabel(field);
				if (!missing.includes(label)) missing.push(label);
			});
			if (!form.checkValidity() && !missing.length) missing.push('اطلاعات فرم را بررسی کنید');
		}
		return missing;
	}

	function buildCalendarBookingAction(host, cal) {
		const wrap = document.createElement('div');
		wrap.className = 'mtal-booking-action';
		wrap.setAttribute('dir', 'rtl');

		const message = document.createElement('div');
		message.className = 'mtal-booking-form-hint';
		message.setAttribute('role', 'status');
		message.setAttribute('aria-live', 'polite');

		const button = document.createElement('button');
		button.type = 'button';
		button.className = 'mtal-cal-confirm-btn mtal-booking-submit';
		button.textContent = 'ارسال درخواست رزرو';
		button.disabled = true;

		const form = findBookingForm(host);
		if (form) { form._mtalCalendarHost = host; form._mtalBookingForm = form; host._mtalBookingForm = form; form._mtalBookingActionButton = button; }
		let bookingSubmitted = (() => {
			try {
				const active = JSON.parse(window.localStorage.getItem('mtal_booking_active_request') || 'null');
				return Boolean(active && active.orderId && active.token && Number(active.deadline) > Date.now() && (!active.postId || String(active.postId) === String(host.getAttribute('data-mtal-post-id') || '')));
			} catch (e) { return false; }
		})();
		if (form) {
			form._mtalBookingSubmitted = bookingSubmitted;
			form._mtalBookingFinalized = false;
		}
		function refreshState() {
			bookingSubmitted = Boolean(form && form._mtalBookingSubmitted);
			if (bookingSubmitted) {
				button.disabled = false;
				button.textContent = 'لغو درخواست رزرو';
				button.classList.add('is-cancel-action');
				message.textContent = 'درخواست شما در حال بررسی است؛ در صورت تمایل می‌توانید آن را لغو کنید.';
				return;
			}
			button.textContent = 'ارسال درخواست رزرو';
			const missing = validateBookingForm(form, host, cal);
			const payload = host._mtalLatestPayload || cal.buildPayload(cal.startDate, cal.endDate);
			const priceReady = Boolean(payload && Number(payload.totalPrice) > 0);
			const ready = Boolean(form && missing.length === 0 && priceReady);
			button.disabled = !ready;
			if (!form) {
				message.textContent = 'فرم رزرو مرتبط پیدا نشد؛ فرم المنتور و فیلد تعداد نفرات را بررسی کنید.';
			} else if (missing.length) {
				message.textContent = 'برای فعال شدن ارسال، این موارد را تکمیل کنید: ' + missing.join('، ') + (priceReady ? '' : '، محاسبه مبلغ رزرو');
			} else if (!priceReady) {
				message.textContent = 'لطفاً منتظر بمانید تا مبلغ رزرو محاسبه شود.';
			} else {
				message.textContent = 'اطلاعات کامل است؛ می‌توانید درخواست رزرو را ارسال کنید.';
			}
		}
		button.addEventListener('click', () => {
			// Keep the click handler in sync with the form state set by startBookingCountdown.
			// This ensures the very first click after a successful booking enters the cancel flow.
			if (bookingSubmitted || (form && form._mtalBookingSubmitted)) {
				bookingSubmitted = true;
				const saved = (() => { try { return JSON.parse(window.localStorage.getItem('mtal_booking_active_request') || 'null'); } catch(e) { return null; } })();
				if (!saved || !saved.orderId || !saved.token || !cfg.ajaxUrl) { message.textContent = 'اطلاعات امن درخواست برای لغو پیدا نشد؛ صفحه را تازه‌سازی کنید.'; return; }
				button.disabled = true;
				button.classList.add('is-cancelling');
				button.setAttribute('aria-busy', 'true');
				button.textContent = 'در حال لغو...';
				message.textContent = 'درخواست لغو شما در حال ارسال به سرور است؛ لطفاً کمی صبر کنید.';
				// به محض کلیک، شمارش سبز متوقف و همان محل به لودر تبدیل می‌شود.
				const countdown = form && form._mtalBookingCountdown;
				if (countdown) {
					if (countdown._mtalInterval) window.clearInterval(countdown._mtalInterval);
					if (countdown._mtalStatusInterval) window.clearInterval(countdown._mtalStatusInterval);
					countdown.classList.remove('is-final-status', 'is-rejected');
					countdown.classList.add('is-cancelling');
					countdown.textContent = 'در حال لغو درخواست...';
				}
				const fd = new FormData(); fd.append('action', 'mtal_booking_cancel'); fd.append('order_id', saved.orderId); fd.append('token', saved.token);
				fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' }).then(r => r.json()).then(result => {
					button.classList.remove('is-cancelling');
					button.removeAttribute('aria-busy');
					if (!result || !result.success) {
						button.disabled = false; button.textContent = 'لغو درخواست رزرو';
						if (countdown) {
							countdown.classList.remove('is-cancelling');
							startBookingCountdown(form, { data: { mtal_booking_order_id: saved.orderId, mtal_booking_access_token: saved.token, mtal_booking_expires_at: Math.floor(Number(saved.deadline) / 1000) } });
						}
						message.textContent = result && result.data && result.data.message ? result.data.message : 'لغو درخواست انجام نشد؛ دوباره تلاش کنید.';
						return;
					}
					if (countdown) {
						countdown.classList.remove('is-cancelling');
						countdown.classList.add('is-final-status', 'is-rejected');
						countdown.textContent = result.data.message || 'درخواست شما لغو شد و تاریخ‌های انتخابی آزاد شدند.';
					}
					message.textContent = result.data.message || 'درخواست شما لغو شد.';
					// Refresh the calendar immediately; do not wait for the periodic availability poll.
					if (typeof refreshServerAvailability === 'function') refreshServerAvailability();
					bookingSubmitted = false;
					if (form) { form._mtalBookingSubmitted = false; form._mtalBookingFinalized = false; }
					button.classList.remove('is-cancel-action');
					// Clear the cancelled stay from every synchronized calendar and its persisted state.
					const synchronizedCalendars = instancesByKey.get(groupKey) || [];
					synchronizedCalendars.forEach((item) => {
						item.cal.clear({ notify: true });
						item.cal.setGuestsCount(item.cal.baseCapacity, { notify: true });
					});
					try {
						window.localStorage.removeItem('mtal_booking_active_request');
						if (saved.orderId) window.localStorage.removeItem('mtal_booking_deadline_' + saved.orderId);
						synchronizedCalendars.forEach((item) => {
							window.localStorage.removeItem(item.storageKey);
							window.localStorage.removeItem(item.guestsStorageKey);
						});
					} catch (e) {}
					button.textContent = 'ارسال درخواست رزرو';
					refreshState();
				}).catch(() => {
					button.classList.remove('is-cancelling'); button.removeAttribute('aria-busy');
					button.disabled = false; button.textContent = 'لغو درخواست رزرو';
					if (countdown) {
						countdown.classList.remove('is-cancelling');
						startBookingCountdown(form, { data: { mtal_booking_order_id: saved.orderId, mtal_booking_access_token: saved.token, mtal_booking_expires_at: Math.floor(Number(saved.deadline) / 1000) } });
					}
					message.textContent = 'ارتباط با سرور برقرار نشد؛ دوباره تلاش کنید.';
				});
				return;
			}
			refreshState();
			if (button.disabled || !form) return;
			if (typeof form.requestSubmit === 'function') form.requestSubmit();
			else form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
		});
		if (form) {
			['input', 'change', 'keyup'].forEach((eventName) => {
				form.addEventListener(eventName, refreshState);
			});
			form.addEventListener('submit', (event) => {
				if (bookingSubmitted) {
					event.preventDefault();
					event.stopImmediatePropagation();
					return;
				}
				const missing = validateBookingForm(form, host, cal);
				const payload = host._mtalLatestPayload || cal.buildPayload(cal.startDate, cal.endDate);
				if (missing.length || !payload || !(Number(payload.totalPrice) > 0)) {
					event.preventDefault();
					event.stopImmediatePropagation();
					refreshState();
				}
			}, true);
			host.addEventListener('mtal:bookingFinalized', (event) => {
				const status = event.detail && event.detail.status;
				if (status === 'cancelled') {
					bookingSubmitted = false;
					form._mtalBookingSubmitted = false;
					form._mtalBookingFinalized = false;
					button.classList.remove('is-cancel-action');
					refreshState();
				} else {
					bookingSubmitted = true;
					form._mtalBookingSubmitted = true;
					form._mtalBookingFinalized = true;
					button.classList.remove('is-cancel-action');
					button.disabled = true;
					button.textContent = 'درخواست رزرو نهایی شد';
					message.textContent = event.detail && event.detail.message ? event.detail.message : 'وضعیت درخواست رزرو نهایی شده است.';
				}
			});
		}
		host.addEventListener('mtal:calendarChange', refreshState);
		host.addEventListener('mtal:serverPriceCalculated', (event) => {
			if (host._mtalLatestPayload) host._mtalLatestPayload.totalPrice = Number(event.detail && event.detail.totalPrice) || 0;
			if (host._mtalLatestPayload && typeof host._mtalUpdateStaySummary === 'function') host._mtalUpdateStaySummary(host._mtalLatestPayload);
			refreshState();
		});
		host.addEventListener('mtal:serverPriceError', refreshState);
		wrap.appendChild(button);
		wrap.appendChild(message);
		host.appendChild(wrap);
		refreshState();
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
			const baseScope = host.getAttribute('data-mtal-storage-scope') || 'global';
			const triggerLabel = host.getAttribute('data-mtal-trigger-label') || 'انتخاب تاریخ سفر';
			const attrMax = parsePositiveInt(host.getAttribute('data-mtal-max-months'), NaN);
			const maxMonths = !Number.isNaN(attrMax) ? Math.max(1, Math.min(3, attrMax)) : globalMaxMonths;
			const postId = parsePositiveInt(host.getAttribute('data-mtal-post-id') || cfg.postId, 0);
			// Each suite needs isolated date/guest persistence and sibling synchronization.
			const scope = `${baseScope}::suite-${postId || 'unknown'}`;

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

			let updateExternalDateFields = null;
			let updateExternalStaySummary = null;

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
					host._mtalLatestPayload = payload;
					if (updateExternalDateFields) updateExternalDateFields(payload);
				if (updateExternalStaySummary) updateExternalStaySummary(payload);
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
						const activeBooking = getActiveBookingForSuite(postId);
						if (activeBooking) {
							fd.append('order_id', String(activeBooking.orderId));
							fd.append('token', String(activeBooking.token));
						}
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
								syncSiblings(groupKey, cal, payload, storageKey, guestsStorageKey);
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

			updateExternalDateFields = buildExternalDateFields(host, cal);
			buildGuestCounterBox(host, cal, maxGuests);
			updateExternalStaySummary = buildExternalStaySummary(host, cal);
			host._mtalUpdateStaySummary = updateExternalStaySummary;
			if (cal.triggerBtnEl) cal.triggerBtnEl.remove();
			buildCalendarBookingAction(host, cal);

			calendarEntries.push({ cal, postId, host });

			if (!instancesByKey.has(groupKey)) {
				instancesByKey.set(groupKey, []);
			}
			instancesByKey.get(groupKey).push({ cal, targetSelector, storageKey, guestsStorageKey, host });

			// Keep the active booking's dates and guest count visible until its deadline/status ends.
			try {
				const activeBooking = JSON.parse(window.localStorage.getItem('mtal_booking_active_request') || 'null');
				if (activeBooking && Number(activeBooking.deadline) > Date.now() &&
					(!activeBooking.postId || String(activeBooking.postId) === String(postId))) {
					if (activeBooking.checkIn && activeBooking.checkOut) {
						const persistedRange = activeBooking.checkIn + ' - ' + activeBooking.checkOut;
						savePersistedValue(storageKey, persistedRange);
					}
					if (activeBooking.guests) {
						savePersistedValue(guestsStorageKey, activeBooking.guests);
						const restoredGuests = Math.min(maxGuests, Math.max(1, parsePositiveInt(activeBooking.guests, initialGuests)));
						if (restoredGuests !== cal.guestsCount) cal.setGuestsCount(restoredGuests, { notify: true });
					}
				}
			} catch (e) {}
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
			'submit_success elementor/popup/show jet-engine/ajax-filters/applied',
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


	/**
	 * Refresh temporary holds so rejected/expired requests become selectable
	 * without requiring visitors to reload the page.
	 */
	const availabilitySnapshots = new Map();
	let availabilityRefreshInFlight = false;

	function refreshServerAvailability() {
		if (
			!cfg.ajaxUrl ||
			!calendarEntries.length ||
			availabilityRefreshInFlight ||
			document.visibilityState === 'hidden' ||
			!navigator.onLine
		) return;

		const postIds = Array.from(new Set(calendarEntries.map((entry) => Number(entry.postId)).filter(Boolean)));
		if (!postIds.length) return;

		availabilityRefreshInFlight = true;
		Promise.all(postIds.map((postId) => {
			const fd = new FormData();
			fd.append('action', 'mtal_get_suite_calendar_rules');
			fd.append('post_id', String(postId));
			const activeBooking = getActiveBookingForSuite(postId);
			if (activeBooking) {
				fd.append('order_id', String(activeBooking.orderId));
				fd.append('token', String(activeBooking.token));
			}
			return fetch(cfg.ajaxUrl, {
				method: 'POST',
				body: fd,
				credentials: 'same-origin'
			})
				.then((response) => {
					if (!response.ok) throw new Error('Availability refresh failed');
					return response.json();
				})
				.then((result) => {
					if (!result || !result.success || !result.data) return;
					const snapshot = JSON.stringify(result.data);
					if (availabilitySnapshots.get(postId) === snapshot) return;
					availabilitySnapshots.set(postId, snapshot);
					applyRulesToSuiteCalendars(postId, result.data);
				})
				.catch(() => {});
		})).finally(() => {
			availabilityRefreshInFlight = false;
		});
	}

	// Poll less often, avoid duplicate requests, and do no background work while offline/hidden.
	window.setInterval(refreshServerAvailability, 15000);
	document.addEventListener('visibilitychange', () => {
		if (document.visibilityState === 'visible') refreshServerAvailability();
	});
	window.addEventListener('online', refreshServerAvailability);

	// Fetch the latest reservation availability as soon as the calendar is ready.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', refreshServerAvailability, { once: true });
	} else {
		refreshServerAvailability();
	}

	/**
	 * Show the server workflow's 30-minute request window after Elementor succeeds.
	 * The server remains authoritative; this is a visual countdown only.
	 */
	function startBookingCountdown(form, response) {
		if (!form) return;
		const responseData = response && response.data ? response.data : {};
		const nestedData = responseData.data && typeof responseData.data === 'object' ? responseData.data : {};
		const pick = (key) => responseData[key] !== undefined && responseData[key] !== '' ? responseData[key] : nestedData[key];
		let previous = null;
		try { previous = JSON.parse(window.localStorage.getItem('mtal_booking_active_request') || 'null'); } catch (e) {}
		const receivedOrderId = pick('mtal_booking_order_id');
		const receivedToken = pick('mtal_booking_access_token');
		// A generic Elementor success event is not proof of a booking. Require
		// the server workflow's order ID and one-time access token in this response.
		if (!receivedOrderId || !receivedToken) return false;
		const orderId = String(receivedOrderId);
		const sameRequest = Boolean(previous && String(previous.orderId) === orderId);
		const serverExpiry = parseInt(pick('mtal_booking_expires_at') || '0', 10);
		const deadline = serverExpiry > 0 ? serverExpiry * 1000 : (sameRequest && Number(previous.deadline) > 0 ? Number(previous.deadline) : Date.now() + 30 * 60 * 1000);
		const key = 'mtal_booking_deadline_' + (orderId || form.id || 'latest');
		try {
			window.localStorage.setItem(key, String(deadline));
			const calendarHost = form._mtalCalendarHost || form.closest('.mtal-calendar-host, [data-mtal-post-id]') || null;
			const getFieldValue = (selectors) => {
				for (const selector of selectors) {
					const field = form.querySelector(selector);
					if (field && field.value) return field.value;
				}
				return '';
			};
			const token = String(receivedToken);
			const dateRangeSelector = calendarHost && calendarHost.getAttribute('data-mtal-target') ? calendarHost.getAttribute('data-mtal-target') : '';
			const combinedDateRange = getFieldValue([dateRangeSelector, '#form-field-checkin_checkout', 'input[name*="checkin_checkout"]', 'input[name*="date_range"]'].filter(Boolean));
			const dateParts = String(combinedDateRange || '').match(/\d{4}\/\d{1,2}\/\d{1,2}/g) || [];
			const checkIn = pick('mtal_booking_check_in') || getFieldValue(['[name*="check_in"]','[name*="checkin"]']) || dateParts[0] || (sameRequest ? previous.checkIn : '');
			const checkOut = pick('mtal_booking_check_out') || getFieldValue(['[name*="check_out"]','[name*="checkout"]']) || dateParts[1] || (sameRequest ? previous.checkOut : '');
			const guests = pick('mtal_booking_guests') || getFieldValue(['#form-field-mtal_qty','input[name*="mtal_qty"]']) || (sameRequest ? previous.guests : '');
			const postId = (calendarHost && calendarHost.getAttribute('data-mtal-post-id')) || (sameRequest ? previous.postId : '') || '';
			// پاسخ‌های تکراری یا ناقص نباید توکن/تاریخ‌های معتبر درخواست قبلی را پاک کنند.
			if (orderId && token) {
				window.localStorage.setItem('mtal_booking_active_request', JSON.stringify({
					orderId: orderId, token: String(token), deadline: deadline,
					checkIn: String(checkIn || ''), checkOut: String(checkOut || ''),
					guests: String(guests || ''), postId: String(postId)
				}));
			}
		} catch (e) {}
		const calendarHost = form._mtalCalendarHost || null;
		const timerContainer = calendarHost && calendarHost.querySelector('.mtal-booking-action');
		let timer = timerContainer ? timerContainer.querySelector('.mtal-booking-countdown') : form.querySelector('.mtal-booking-countdown');
		if (!timer) {
			timer = document.createElement('div');
			timer.className = 'mtal-booking-countdown';
			timer.setAttribute('role', 'status');
			timer.setAttribute('aria-live', 'polite');
		}
		(timerContainer || form).appendChild(timer);
		form._mtalBookingCountdown = timer;
		if (timerContainer) timerContainer.classList.add('has-countdown');
		form._mtalBookingSubmitted = true;
		form._mtalBookingFinalized = false;
		if (form._mtalBookingActionButton) {
			form._mtalBookingActionButton.classList.add('is-cancel-action');
			form._mtalBookingActionButton.disabled = false;
			form._mtalBookingActionButton.textContent = 'لغو درخواست رزرو';
		}
		if (timer._mtalInterval) window.clearInterval(timer._mtalInterval);
		if (timer._mtalStatusInterval) window.clearInterval(timer._mtalStatusInterval);
		let finalStatus = false;
		let approvedForPayment = false;
		const activeRequest = (() => { try { return JSON.parse(window.localStorage.getItem('mtal_booking_active_request') || 'null'); } catch (e) { return null; } })();
		const requestToken = pick('mtal_booking_access_token') || (activeRequest && activeRequest.orderId === orderId ? activeRequest.token : '');
		const render = () => {
			if (finalStatus) return;
			const remaining = Math.max(0, deadline - Date.now());
			const minutes = Math.floor(remaining / 60000);
			const seconds = Math.floor((remaining % 60000) / 1000);
			if (remaining <= 0) {
				timer.textContent = approvedForPayment ? 'مهلت پرداخت رزرو تأییدشده به پایان رسید.' : 'مهلت درخواست به پایان رسید؛ لطفاً درخواست رزرو جدید ثبت کنید.';
				window.clearInterval(timer._mtalInterval);
				return;
			}
			timer.textContent = (approvedForPayment ? 'درخواست تأیید شد؛ مهلت پرداخت: ' : 'مهلت بررسی درخواست: ') + toPersianDigits(String(minutes).padStart(2, '0')) + ':' + toPersianDigits(String(seconds).padStart(2, '0'));
		};
		render();
		timer._mtalInterval = window.setInterval(render, 1000);
		if (orderId && requestToken && cfg.ajaxUrl) {
			const checkStatus = () => {
				const fd = new FormData();
				fd.append('action', 'mtal_booking_status');
				fd.append('order_id', orderId);
				fd.append('token', requestToken);
				fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' }).then(r => r.json()).then(result => {
					if (!result || !result.success || !result.data) return;
					if (result.data.status === 'mtal-pending') return;
					if (result.data.status === 'pending') {
						approvedForPayment = true;
						timer.classList.add('is-approved-payment');
						render();
						return;
					}
					finalStatus = true;
					window.clearInterval(timer._mtalInterval);
					window.clearInterval(timer._mtalStatusInterval);
					timer.textContent = result.data.message;
					timer.classList.add('is-final-status');
					timer.classList.toggle('is-rejected', result.data.status === 'cancelled');
					if (result.data.status === 'cancelled') {
						form._mtalBookingSubmitted = false;
						form._mtalBookingFinalized = false;
					} else {
						form._mtalBookingSubmitted = true;
						form._mtalBookingFinalized = true;
					}
					if (form._mtalBookingActionButton) {
						form._mtalBookingActionButton.classList.remove('is-cancel-action');
						form._mtalBookingActionButton.disabled = result.data.status !== 'cancelled';
						form._mtalBookingActionButton.textContent = result.data.status === 'cancelled' ? 'ارسال درخواست رزرو جدید' : 'درخواست رزرو نهایی شد';
					}
					const stateHost = form._mtalCalendarHost || form.closest('.mtal-calendar-host, [data-mtal-post-id]');
					if (stateHost) stateHost.dispatchEvent(new CustomEvent('mtal:bookingFinalized', { detail: { status: result.data.status, message: result.data.message || '' } }));
					try {
						const current = JSON.parse(window.localStorage.getItem('mtal_booking_active_request') || 'null');
						if (current && current.orderId === orderId) {
							// Keep the selected stay and guest count after the workflow reaches a final state.
						const checkIn = result.data.checkIn || current.checkIn || '';
						const checkOut = result.data.checkOut || current.checkOut || '';
						const guests = result.data.guests || current.guests || '';
						const suiteId = String(result.data.suiteId || current.postId || '');
						if (suiteId) {
							const range = checkIn && checkOut ? String(checkIn).replace(/-/g, '/') + ' - ' + String(checkOut).replace(/-/g, '/') : '';
						for (let index = 0; index < window.localStorage.length; index++) {
							const storageKey = window.localStorage.key(index);
							if (!storageKey || !storageKey.includes('suite-' + suiteId + '__')) continue;
							if (range && storageKey.startsWith('mtal_cal_range__')) window.localStorage.setItem(storageKey, range);
							if (guests && storageKey.startsWith('mtal_cal_guests__')) window.localStorage.setItem(storageKey, String(guests));
						}
						}
						window.localStorage.removeItem('mtal_booking_active_request');
						}
						window.localStorage.removeItem(key);
					} catch (e) {}
				}).catch(() => {});
			};
			checkStatus();
			timer._mtalStatusInterval = window.setInterval(checkStatus, 15000);
		}
		return true;
	}
	function handleElementorBookingSuccess(form, response) {
		if (!form || !form._mtalBookingActionButton) return;
		const started = startBookingCountdown(form, response || {});
		if (started) return;
		// Elementor may report a successful form submission even when the booking
		// workflow did not create an order (for example, missing booking fields).
		// Keep the action in submit mode and tell the visitor no booking was confirmed.
		form._mtalBookingSubmitted = false;
		form._mtalBookingFinalized = false;
		const button = form._mtalBookingActionButton;
		button.classList.remove('is-cancel-action', 'is-cancelling');
		button.removeAttribute('aria-busy');
		button.textContent = 'ارسال درخواست رزرو';
		const host = form._mtalCalendarHost || null;
		const hint = host && host.querySelector('.mtal-booking-form-hint');
		if (hint) hint.textContent = 'فرم ارسال شد، اما سرور شناسه سفارش و تأیید ثبت رزرو را برنگرداند؛ درخواست رزرو ثبت‌شده محسوب نمی‌شود. اطلاعات فرم را بررسی کنید و در صورت تکرار، با پشتیبانی تماس بگیرید.';
	}
	if (window.jQuery) {
		window.jQuery(document).on('submit_success', 'form.elementor-form', function (event, response) {
			handleElementorBookingSuccess(this, response || {});
		});
		window.jQuery(document).on('submit_success', function (event, response) {
			const form = event.target && event.target.closest ? event.target.closest('form') : null;
			if (form && !form.matches('form.elementor-form')) handleElementorBookingSuccess(form, response || {});
		});
	}

 // Restore the active request countdown after page refresh.
 function restoreActiveBookingCountdown() {
  let saved; try { saved=JSON.parse(window.localStorage.getItem('mtal_booking_active_request') || 'null'); } catch(e) { saved=null; }
  if (!saved || !saved.orderId || !saved.token) return;
  const forms=Array.from(document.querySelectorAll('form'));
  const form=forms.find(item=>{
   const host=item._mtalCalendarHost || item.closest('.mtal-calendar-host, [data-mtal-post-id]');
   const hostId=host && host.getAttribute('data-mtal-post-id');
   const idField=item.querySelector('#form-field-mtal_post_id, input[name="form_fields[mtal_post_id]"]');
   const formId=idField && idField.value;
   return (saved.postId && String(hostId || formId || '')===String(saved.postId)) || (!saved.postId && (item._mtalCalendarHost || item.matches('form.elementor-form')));
  });
  if (!form) return;
  startBookingCountdown(form,{data:{mtal_booking_order_id:saved.orderId,mtal_booking_access_token:saved.token,mtal_booking_expires_at:Math.floor((Number(saved.deadline)||Date.now())/1000),mtal_booking_check_in:saved.checkIn||'',mtal_booking_check_out:saved.checkOut||'',mtal_booking_guests:saved.guests||''}});
 }
 if (document.readyState==='loading') document.addEventListener('DOMContentLoaded',restoreActiveBookingCountdown,{once:true});
 else window.setTimeout(restoreActiveBookingCountdown,0);
})();