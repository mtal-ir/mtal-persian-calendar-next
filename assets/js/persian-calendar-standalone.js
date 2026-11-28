/**
 * MTAL Persian Calendar Standalone Engine
 * جلوگیری قطعی از انتخاب بازه شامل روزهای مسدود (مانند ۸ تا ۱۰ وقتی ۹ مسدود است)
 */
(function (window, document) {
	'use strict';

	const PERSIAN_MONTHS = [
		'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
		'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند',
	];

	const PERSIAN_WEEKDAYS_SHORT = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];
	const PERSIAN_WEEKDAYS_FULL = [
		'شنبه', 'یک‌شنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه',
	];

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

	function formatNumberPersian(num) {
		const raw = Math.max(0, Number(num) || 0);
		return toPersianDigits(raw.toLocaleString('en-US'));
	}

	function formatShortPricePersian(num) {
		const raw = Math.max(0, Number(num) || 0);
		const trimmed = Math.floor(raw / 1000);
		return toPersianDigits(trimmed.toLocaleString('en-US'));
	}

	function formatFullTomanPersian(num) {
		return `${formatNumberPersian(num)} تومان`;
	}

	function pad2(num) {
		return num < 10 ? '0' + num : String(num);
	}

	function div(a, b) {
		return Math.floor(a / b);
	}

	function mod(a, b) {
		return a - b * Math.floor(a / b);
	}

	function gregorianToJdn(gy, gm, gd) {
		const a = div(14 - gm, 12);
		const y = gy + 4800 - a;
		const m = gm + 12 * a - 3;
		return (
			gd +
			div(153 * m + 2, 5) +
			365 * y +
			div(y, 4) -
			div(y, 100) +
			div(y, 400) -
			32045
		);
	}

	function jdnToGregorian(jdn) {
		let l = jdn + 68569;
		const n = div(4 * l, 146097);
		l = l - div(146097 * n + 3, 4);
		const i = div(4000 * (l + 1), 1461001);
		l = l - div(1461 * i, 4) + 31;
		const j = div(80 * l, 2447);
		const gd = l - div(2447 * j, 80);
		l = div(j, 11);
		const gm = j + 2 - 12 * l;
		const gy = 100 * (n - 49) + i + l;
		return { gy, gm, gd };
	}

	function jalaliToJdn(jy, jm, jd) {
		const epbase = jy - (jy >= 0 ? 474 : 473);
		const epyear = 474 + mod(epbase, 2820);
		return (
			jd +
			(jm <= 7 ? (jm - 1) * 31 : (jm - 1) * 30 + 6) +
			div(epyear * 682 - 110, 2816) +
			(epyear - 1) * 365 +
			div(epbase, 2820) * 1029983 +
			1948320
		);
	}

	function jdnToJalali(jdn) {
		const g = jdnToGregorian(jdn);
		let jy = g.gy - 621;
		const r = jalaliToJdn(jy + 1, 1, 1);
		if (jdn >= r) {
			jy += 1;
		}
		const jdn1Farvardin = jalaliToJdn(jy, 1, 1);
		const dayOfYear = jdn - jdn1Farvardin;

		let jm;
		let jd;
		if (dayOfYear < 186) {
			jm = 1 + div(dayOfYear, 31);
			jd = mod(dayOfYear, 31) + 1;
		} else {
			const rem = dayOfYear - 186;
			jm = 7 + div(rem, 30);
			jd = mod(rem, 30) + 1;
		}

		return { jy, jm, jd };
	}

	function toJalaali(gy, gm, gd) {
		return jdnToJalali(gregorianToJdn(gy, gm, gd));
	}

	function getTodayJalali() {
		const now = new Date();
		return toJalaali(now.getFullYear(), now.getMonth() + 1, now.getDate());
	}

	function isLeapJalali(jy) {
		return jalaliToJdn(jy + 1, 1, 1) - jalaliToJdn(jy, 1, 1) === 366;
	}

	function getJalaliMonthDays(jy, jm) {
		if (jm >= 1 && jm <= 6) return 31;
		if (jm >= 7 && jm <= 11) return 30;
		return isLeapJalali(jy) ? 30 : 29;
	}

	function getJalaliWeekdayIndex(jy, jm, jd) {
		const jdn = jalaliToJdn(jy, jm, jd);
		return mod(jdn + 2, 7);
	}

	function compareDates(a, b) {
		if (!a || !b) return 0;
		if (a.jy !== b.jy) return a.jy - b.jy;
		if (a.jm !== b.jm) return a.jm - b.jm;
		return a.jd - b.jd;
	}

	function isSameDate(a, b) {
		return Boolean(a && b && a.jy === b.jy && a.jm === b.jm && a.jd === b.jd);
	}

	function addMonthsJalali(year, month, offset) {
		const totalMonths = year * 12 + (month - 1) + offset;
		const jy = Math.floor(totalMonths / 12);
		const jm = (totalMonths % 12) + 1;
		return { jy, jm };
	}

	function formatSlash(d) {
		if (!d) return '';
		return `${d.jy}/${pad2(d.jm)}/${pad2(d.jd)}`;
	}

	function toCanonicalKey(input) {
		if (!input) return '';
		if (typeof input === 'object' && input.jy && input.jm && input.jd) {
			return `${parseInt(input.jy, 10)}/${parseInt(input.jm, 10)}/${parseInt(input.jd, 10)}`;
		}
		const clean = toEnglishDigits(String(input)).trim();
		const m = clean.match(/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/);
		if (!m) return '';
		return `${parseInt(m[1], 10)}/${parseInt(m[2], 10)}/${parseInt(m[3], 10)}`;
	}

	function formatShortPersian(d) {
		if (!d) return '';
		return `${toPersianDigits(d.jd)} ${PERSIAN_MONTHS[d.jm - 1]}`;
	}

	function formatFullPersian(d) {
		if (!d) return '';
		const wName = PERSIAN_WEEKDAYS_FULL[getJalaliWeekdayIndex(d.jy, d.jm, d.jd)] || '';
		return `${wName} ${toPersianDigits(d.jd)} ${PERSIAN_MONTHS[d.jm - 1]} ${toPersianDigits(d.jy)}`;
	}

	function normalizeDateSet(list) {
		const set = new Set();
		if (!list) return set;
		const items = Array.isArray(list) ? list : typeof list === 'object' ? Object.values(list) : [];
		items.forEach((item) => {
			const canon = toCanonicalKey(item);
			if (canon) {
				set.add(canon);
			}
		});
		return set;
	}

	function normalizeManualPriceMap(mapObj) {
		const map = new Map();
		if (!mapObj || typeof mapObj !== 'object') return map;
		Object.keys(mapObj).forEach((k) => {
			const canon = toCanonicalKey(k);
			const val = Math.max(0, parseInt(toEnglishDigits(String(mapObj[k])), 10) || 0);
			if (canon && val > 0) {
				map.set(canon, val);
			}
		});
		return map;
	}

	class MTALPersianCalendar {
		constructor(options = {}) {
			const validModes = ['inline', 'inline_2col', 'inline_responsive', 'modal_3months'];
			this.mode = validModes.includes(options.mode) ? options.mode : 'inline';
			this.responsiveDesktop = this.mode === 'inline_responsive' && window.matchMedia('(min-width: 1025px)').matches;
			this.mountEl = options.mountEl || null;
			this.triggerLabel = options.triggerLabel || 'انتخاب تاریخ سفر';
			this.maxMonths = Math.max(1, Math.min(3, parseInt(options.maxMonths || 3, 10)));
			this.onChange = typeof options.onChange === 'function' ? options.onChange : null;
			this.onRangeCompleted =
				typeof options.onRangeCompleted === 'function' ? options.onRangeCompleted : null;

			this.blockedSet = normalizeDateSet(options.blockedDates || []);
			this.holidaySet = normalizeDateSet(options.holidayDates || []);
			this.manualPrices = normalizeManualPriceMap(options.manualPrices || {});
			this.regularPrice = Math.max(0, parseInt(options.regularPrice || 0, 10));
			this.weekendPrice = Math.max(0, parseInt(options.weekendPrice || 0, 10));

			this.baseCapacity = Math.max(1, parseInt(options.baseCapacity || 2, 10));
			this.extraGuestPrice = Math.max(0, parseInt(options.extraGuestPrice || 0, 10));
			this.guestsCount = Math.max(1, parseInt(options.guestsCount || this.baseCapacity, 10));

			this.today = getTodayJalali();
			this.minDate = { jy: this.today.jy, jm: this.today.jm, jd: this.today.jd };

			const horizonMonth = addMonthsJalali(this.today.jy, this.today.jm, this.maxMonths - 1);
			const horizonLastDay = getJalaliMonthDays(horizonMonth.jy, horizonMonth.jm);
			this.maxDate = {
				jy: horizonMonth.jy,
				jm: horizonMonth.jm,
				jd: horizonLastDay,
			};

			this.startDate = null;
			this.endDate = null;
			this.draftStart = null;
			this.draftEnd = null;
			this.warningMessage = '';

			this.viewYear = this.today.jy;
			this.viewMonth = this.today.jm;

			this.rootEl = null;
			this.panelEl = null;
			this.modalBackdropEl = null;
			this.modalFooterEl = null;
			this.triggerBtnEl = null;

			this.build();
			if (this.mode === 'inline_responsive') {
				window.addEventListener('resize', () => {
					const desktop = window.matchMedia('(min-width: 1025px)').matches;
					if (desktop !== this.responsiveDesktop) {
						this.responsiveDesktop = desktop;
						this.render();
					}
				});
			}
			this.render();
		}

		updateRules(rules = {}) {
			if (rules.blockedDates !== undefined) {
				this.blockedSet = normalizeDateSet(rules.blockedDates);
			}
			if (rules.holidayDates !== undefined) {
				this.holidaySet = normalizeDateSet(rules.holidayDates);
			}
			if (rules.manualPrices && typeof rules.manualPrices === 'object') {
				this.manualPrices = normalizeManualPriceMap(rules.manualPrices);
			}
			if (rules.regularPrice !== undefined) {
				this.regularPrice = Math.max(0, parseInt(rules.regularPrice, 10) || 0);
			}
			if (rules.weekendPrice !== undefined) {
				this.weekendPrice = Math.max(0, parseInt(rules.weekendPrice, 10) || 0);
			}
			if (rules.baseCapacity !== undefined) {
				this.baseCapacity = Math.max(1, parseInt(rules.baseCapacity, 10) || 2);
			}
			if (rules.extraGuestPrice !== undefined) {
				this.extraGuestPrice = Math.max(0, parseInt(rules.extraGuestPrice, 10) || 0);
			}

			this.enforceValidState();
			this.render();
		}

		setGuestsCount(count, options = {}) {
			const notify = options.notify !== undefined ? options.notify : true;
			const nextVal = Math.max(1, parseInt(count, 10) || 1);
			if (nextVal === this.guestsCount) return;
			this.guestsCount = nextVal;
			this.enforceValidState();
			this.render();
			this.emitChange(notify);
		}

		isPastOrOutOfHorizon(d) {
			if (compareDates(d, this.minDate) < 0) return true;
			if (compareDates(d, this.maxDate) > 0) return true;
			return false;
		}

		isDateBlocked(d) {
			const canon = toCanonicalKey(d);
			return canon ? this.blockedSet.has(canon) : false;
		}

		isDateDisabled(d) {
			return this.isPastOrOutOfHorizon(d) || this.isDateBlocked(d);
		}

		hasBlockedBetween(start, end) {
			if (!start || !end || compareDates(end, start) <= 0) return false;
			const startJdn = jalaliToJdn(start.jy, start.jm, start.jd);
			const endJdn = jalaliToJdn(end.jy, end.jm, end.jd);
			for (let jdn = startJdn; jdn < endJdn; jdn++) {
				const d = jdnToJalali(jdn);
				if (this.isDateBlocked(d)) {
					return true;
				}
			}
			return false;
		}

		isRangeAllowed(start, end) {
			if (!start || !end) return false;
			if (this.isPastOrOutOfHorizon(start) || this.isPastOrOutOfHorizon(end)) return false;
			if (this.isDateBlocked(start)) return false;
			if (compareDates(end, start) <= 0) return false;
			if (this.hasBlockedBetween(start, end)) return false;
			return true;
		}

		enforceValidState() {
			if (this.startDate && this.endDate && !this.isRangeAllowed(this.startDate, this.endDate)) {
				this.endDate = null;
				if (!this.warningMessage) {
					this.warningMessage =
						'امکان انتخاب بازه‌ای که روز مسدود بین آن قرار دارد وجود ندارد؛ تاریخ خروج لغو شد.';
				}
			}
			if (this.draftStart && this.draftEnd && !this.isRangeAllowed(this.draftStart, this.draftEnd)) {
				this.draftEnd = null;
			}
		}

		isFriday(d) {
			return getJalaliWeekdayIndex(d.jy, d.jm, d.jd) === 6;
		}

		isHoliday(d) {
			const canon = toCanonicalKey(d);
			return canon ? this.holidaySet.has(canon) : false;
		}

		getDayPrice(d) {
			const canon = toCanonicalKey(d);
			if (canon && this.manualPrices.has(canon)) {
				return { price: this.manualPrices.get(canon), source: 'manual' };
			}
			const weekday = getJalaliWeekdayIndex(d.jy, d.jm, d.jd);
			if ((weekday === 4 || weekday === 5) && this.weekendPrice > 0) {
				return { price: this.weekendPrice, source: 'weekend' };
			}
			return { price: this.regularPrice, source: 'regular' };
		}

		calculateRangePricing(start, end) {
			if (!this.isRangeAllowed(start, end)) {
				return {
					nights: 0,
					groups: [],
					extraGuests: 0,
					extraGuestsPerNightTotal: 0,
					extraGuestsTotal: 0,
					totalPrice: 0,
					breakdown: [],
				};
			}

			const startJdn = jalaliToJdn(start.jy, start.jm, start.jd);
			const endJdn = jalaliToJdn(end.jy, end.jm, end.jd);
			const breakdown = [];
			const groupMap = new Map();
			let nightsBaseTotal = 0;

			for (let jdn = startJdn; jdn < endJdn; jdn++) {
				const d = jdnToJalali(jdn);
				const pInfo = this.getDayPrice(d);
				nightsBaseTotal += pInfo.price;

				breakdown.push({
					date: d,
					dateSlash: formatSlash(d),
					price: pInfo.price,
					source: pInfo.source,
				});

				const gKey = `${pInfo.source}__${pInfo.price}`;
				if (!groupMap.has(gKey)) {
					groupMap.set(gKey, {
						source: pInfo.source,
						unitPrice: pInfo.price,
						count: 0,
					});
				}
				groupMap.get(gKey).count += 1;
			}

			const groups = Array.from(groupMap.values());
			const nights = breakdown.length;
			const extraGuests = Math.max(0, this.guestsCount - this.baseCapacity);
			const extraGuestsPerNightTotal = extraGuests * this.extraGuestPrice;
			const extraGuestsTotal = nights * extraGuestsPerNightTotal;
			const totalPrice = nightsBaseTotal + extraGuestsTotal;

			return {
				nights,
				groups,
				extraGuests,
				extraGuestsPerNightTotal,
				extraGuestsTotal,
				totalPrice,
				breakdown,
			};
		}

		getSourceLabelPersian(source) {
			if (source === 'weekend') return 'شب آخر هفته';
			if (source === 'manual') return 'شب نرخ ویژه';
			return 'شب عادی';
		}

		createPricingBreakdownBox(start, end) {
			if (!this.isRangeAllowed(start, end)) {
				return null;
			}

			const pricing = this.calculateRangePricing(start, end);
			if (pricing.nights <= 0) return null;

			const box = document.createElement('div');
			box.className = 'mtal-pricing-breakdown';
			box.setAttribute('dir', 'rtl');

			const stayRow = document.createElement('div');
			stayRow.className = 'mtal-breakdown-row is-stay';
			stayRow.innerHTML = `<span>زمان اقامت</span><strong>${toPersianDigits(pricing.nights)} شب</strong>`;
			box.appendChild(stayRow);

			pricing.groups.forEach((grp) => {
				const row = document.createElement('div');
				row.className = 'mtal-breakdown-row';
				const label = this.getSourceLabelPersian(grp.source);
				const expr = `${toPersianDigits(grp.count)} × ${formatNumberPersian(grp.unitPrice)}`;
				row.innerHTML = `<span>${label}</span><strong dir="rtl">${expr}</strong>`;
				box.appendChild(row);
			});

			if (pricing.extraGuests > 0 && this.extraGuestPrice > 0) {
				const extraRow = document.createElement('div');
				extraRow.className = 'mtal-breakdown-row is-extra';
				const extraExpr =
					pricing.nights > 1
						? `${toPersianDigits(pricing.extraGuests)} نفر × ${toPersianDigits(pricing.nights)} شب × ${formatNumberPersian(this.extraGuestPrice)}`
						: `${toPersianDigits(pricing.extraGuests)} نفر × ${formatNumberPersian(this.extraGuestPrice)}`;
				extraRow.innerHTML = `<span>نفرات اضافه</span><strong dir="rtl">${extraExpr}</strong>`;
				box.appendChild(extraRow);
			}

			const totalRow = document.createElement('div');
			totalRow.className = 'mtal-breakdown-row is-total';
			totalRow.innerHTML = `<span>مبلغ کل (${toPersianDigits(this.guestsCount)} نفر)</span><strong dir="rtl">${formatFullTomanPersian(pricing.totalPrice)}</strong>`;
			box.appendChild(totalRow);

			return box;
		}

		buildPayload(start, end) {
			const hasValidRange = this.isRangeAllowed(start, end);
			const pricing = hasValidRange
				? this.calculateRangePricing(start, end)
				: {
						nights: 0,
						groups: [],
						extraGuests: 0,
						extraGuestsPerNightTotal: 0,
						extraGuestsTotal: 0,
						totalPrice: 0,
						breakdown: [],
				  };

			let rangeText = '';
			if (hasValidRange) {
				rangeText = `${formatFullPersian(start)} (${formatSlash(start)}) الی ${formatFullPersian(end)} (${formatSlash(end)})`;
			} else if (start) {
				rangeText = `تاریخ ورود: ${formatFullPersian(start)} (${formatSlash(start)}) — تاریخ خروج را انتخاب کنید`;
			}

			return {
				checkIn: start ? { ...start } : null,
				checkOut: hasValidRange ? { ...end } : null,
				checkInSlash: start ? formatSlash(start) : '',
				checkOutSlash: hasValidRange ? formatSlash(end) : '',
				checkInShort: start ? formatShortPersian(start) : '',
				checkOutShort: hasValidRange ? formatShortPersian(end) : '',
				checkInDisplay: start ? formatFullPersian(start).replace(/\\s+[۰-۹\\d]{4}$/, '') : '',
				checkOutDisplay: hasValidRange ? formatFullPersian(end).replace(/\\s+[۰-۹\\d]{4}$/, '') : '',
				nights: pricing.nights,
				guestsCount: this.guestsCount,
				extraGuests: pricing.extraGuests,
				extraGuestsTotal: pricing.extraGuestsTotal,
				totalPrice: pricing.totalPrice,
				pricingGroups: pricing.groups,
				pricingBreakdown: pricing.breakdown,
				rangeText,
				value: hasValidRange ? `${formatSlash(start)} الی ${formatSlash(end)}` : '',
			};
		}

		emitChange(notify = true) {
			if (!notify || !this.onChange) return;
			this.onChange(this.buildPayload(this.startDate, this.endDate));
		}

		setRange(start, end, options = {}) {
			const notify = options.notify !== undefined ? options.notify : true;
			const syncDraft = options.syncDraft !== undefined ? options.syncDraft : true;

			this.warningMessage = '';

			if (!start) {
				this.startDate = null;
				this.endDate = null;
				if (syncDraft) {
					this.draftStart = null;
					this.draftEnd = null;
				}
				this.render();
				this.emitChange(notify);
				return;
			}

			const s = { jy: start.jy, jm: start.jm, jd: start.jd };
			let e = end ? { jy: end.jy, jm: end.jm, jd: end.jd } : null;

			if (this.isDateDisabled(s)) return;
			if (e && !this.isRangeAllowed(s, e)) {
				e = null;
				this.warningMessage =
					'امکان انتخاب بازه‌ای که روز مسدود بین آن قرار دارد وجود ندارد؛ لطفاً بازه دیگری انتخاب کنید.';
			}

			this.startDate = s;
			this.endDate = e;
			if (syncDraft) {
				this.draftStart = s ? { ...s } : null;
				this.draftEnd = e ? { ...e } : null;
			}

			const minMonthIndex = this.minDate.jy * 12 + (this.minDate.jm - 1);
			const maxMonthIndex = this.maxDate.jy * 12 + (this.maxDate.jm - 1);
			let targetIdx = s.jy * 12 + (s.jm - 1);
			if (targetIdx < minMonthIndex) targetIdx = minMonthIndex;
			if (targetIdx > maxMonthIndex) targetIdx = maxMonthIndex;

			this.viewYear = Math.floor(targetIdx / 12);
			this.viewMonth = (targetIdx % 12) + 1;

			this.enforceValidState();
			this.render();
			this.emitChange(notify);
		}

		clear(options = {}) {
			const notify = options.notify !== undefined ? options.notify : true;
			this.startDate = null;
			this.endDate = null;
			this.draftStart = null;
			this.draftEnd = null;
			this.warningMessage = '';
			this.render();
			this.emitChange(notify);
		}

		openModal() {
			if (this.mode !== 'modal_3months' || !this.modalBackdropEl) return;
			this.draftStart = this.startDate ? { ...this.startDate } : null;
			this.draftEnd = this.endDate ? { ...this.endDate } : null;
			this.warningMessage = '';
			this.enforceValidState();
			this.render();
			this.modalBackdropEl.classList.add('is-open');
			document.documentElement.classList.add('mtal-modal-open');
		}

		closeModal(discardDraft = true) {
			if (this.mode !== 'modal_3months' || !this.modalBackdropEl) return;
			if (discardDraft) {
				this.draftStart = this.startDate ? { ...this.startDate } : null;
				this.draftEnd = this.endDate ? { ...this.endDate } : null;
				this.warningMessage = '';
			}
			this.modalBackdropEl.classList.remove('is-open');
			const anyOpen = document.querySelector('.mtal-cal-modal-backdrop.is-open');
			if (!anyOpen) {
				document.documentElement.classList.remove('mtal-modal-open');
			}
		}

		confirmModalRange() {
			if (this.mode !== 'modal_3months') return;
			let completedRange = false;
			if (this.isRangeAllowed(this.draftStart, this.draftEnd)) {
				this.startDate = { ...this.draftStart };
				this.endDate = { ...this.draftEnd };
				completedRange = true;
			} else if (this.draftStart) {
				this.startDate = { ...this.draftStart };
				this.endDate = null;
			} else {
				this.startDate = null;
				this.endDate = null;
			}

			this.warningMessage = '';
			this.closeModal(false);
			this.enforceValidState();
			this.render();
			this.emitChange(true);

			if (completedRange && this.onRangeCompleted) {
				this.onRangeCompleted(this.buildPayload(this.startDate, this.endDate));
			}
		}

		handleDaySelect(dayObj) {
			if (this.isPastOrOutOfHorizon(dayObj)) return;

			const blockedRangeRejectedMsg =
				'امکان انتخاب بازه‌ای که روز مسدود بین آن قرار دارد وجود ندارد؛ انتخاب بازه لغو شد و این روز به عنوان تاریخ ورود جدید تنظیم شد.';

			const clickedBlocked = this.isDateBlocked(dayObj);

			if (this.mode === 'modal_3months') {
				if (!this.draftStart || (this.draftStart && this.draftEnd)) {
					if (clickedBlocked) return;
					this.draftStart = { ...dayObj };
					this.draftEnd = null;
					this.warningMessage = '';
				} else if (compareDates(dayObj, this.draftStart) <= 0) {
					if (clickedBlocked) return;
					this.draftStart = { ...dayObj };
					this.draftEnd = null;
					this.warningMessage = '';
				} else if (!this.isRangeAllowed(this.draftStart, dayObj)) {
					if (clickedBlocked) {
						this.draftStart = null;
						this.draftEnd = null;
						this.warningMessage =
							'امکان انتخاب بازه شامل روز مسدود وجود ندارد؛ لطفاً بازه دیگری انتخاب کنید.';
					} else {
						this.draftStart = { ...dayObj };
						this.draftEnd = null;
						this.warningMessage = blockedRangeRejectedMsg;
					}
				} else {
					this.draftEnd = { ...dayObj };
					this.warningMessage = '';
					this.enforceValidState();
					this.confirmModalRange();
					return;
				}
				this.enforceValidState();
				// Commit the partial check-in selection so closing and reopening the modal keeps it.
				this.startDate = this.draftStart ? { ...this.draftStart } : null;
				this.endDate = null;
				this.render();
				this.emitChange(true);
				return;
			}

			let completedRange = false;
			if (!this.startDate || (this.startDate && this.endDate)) {
				if (clickedBlocked) return;
				this.startDate = { ...dayObj };
				this.endDate = null;
				this.warningMessage = '';
			} else if (compareDates(dayObj, this.startDate) <= 0) {
				if (clickedBlocked) return;
				this.startDate = { ...dayObj };
				this.endDate = null;
				this.warningMessage = '';
			} else if (!this.isRangeAllowed(this.startDate, dayObj)) {
				if (clickedBlocked) {
					this.startDate = null;
					this.endDate = null;
					this.warningMessage =
						'امکان انتخاب بازه شامل روز مسدود وجود ندارد؛ لطفاً بازه دیگری انتخاب کنید.';
				} else {
					this.startDate = { ...dayObj };
					this.endDate = null;
					this.warningMessage = blockedRangeRejectedMsg;
				}
			} else {
				this.endDate = { ...dayObj };
				this.warningMessage = '';
				completedRange = true;
			}

			this.draftStart = this.startDate ? { ...this.startDate } : null;
			this.draftEnd = this.endDate ? { ...this.endDate } : null;

			this.enforceValidState();
			this.render();
			this.emitChange(true);

			if (completedRange && this.onRangeCompleted) {
				this.onRangeCompleted(this.buildPayload(this.startDate, this.endDate));
			}
		}

		build() {
			if (!this.mountEl) return;

			this.rootEl = document.createElement('div');
			this.rootEl.className = `mtal-cal-root mtal-cal-mode-${this.mode}`;
			this.rootEl.setAttribute('dir', 'rtl');

			if (this.mode === 'modal_3months') {
				this.triggerBtnEl = document.createElement('button');
				this.triggerBtnEl.type = 'button';
				this.triggerBtnEl.className = 'mtal-cal-modal-trigger';
				this.triggerBtnEl.addEventListener('click', () => this.openModal());

				this.modalBackdropEl = document.createElement('div');
				this.modalBackdropEl.className = 'mtal-cal-modal-backdrop';
				this.modalBackdropEl.setAttribute('role', 'dialog');
				this.modalBackdropEl.setAttribute('aria-modal', 'true');

				this.modalBackdropEl.addEventListener('click', (e) => {
					if (e.target === this.modalBackdropEl) {
						this.closeModal(true);
					}
				});

				const modalSheet = document.createElement('div');
				modalSheet.className = 'mtal-cal-modal-sheet';

				const modalHeader = document.createElement('div');
				modalHeader.className = 'mtal-cal-modal-header';

				const modalTitle = document.createElement('div');
				modalTitle.className = 'mtal-cal-modal-title';
				modalTitle.textContent = 'انتخاب تاریخ';

				const closeBtn = document.createElement('button');
				closeBtn.type = 'button';
				closeBtn.className = 'mtal-cal-modal-close';
				closeBtn.setAttribute('aria-label', 'بستن');
				closeBtn.textContent = '×';
				closeBtn.addEventListener('click', () => this.closeModal(true));

				modalHeader.appendChild(modalTitle);
				modalHeader.appendChild(closeBtn);

				const modalBody = document.createElement('div');
				modalBody.className = 'mtal-cal-modal-body';

				this.panelEl = document.createElement('div');
				this.panelEl.className = 'mtal-cal-panel mtal-cal-panel-stacked';
				modalBody.appendChild(this.panelEl);

				const modalFooter = document.createElement('div');
				modalFooter.className = 'mtal-cal-modal-footer';
				this.modalFooterEl = modalFooter;

				modalSheet.appendChild(modalHeader);
				modalSheet.appendChild(modalBody);
				modalSheet.appendChild(modalFooter);
				this.modalBackdropEl.appendChild(modalSheet);

				this.rootEl.appendChild(this.triggerBtnEl);
				document.body.appendChild(this.modalBackdropEl);
			} else {
				this.panelEl = document.createElement('div');
				this.panelEl.className = 'mtal-cal-panel mtal-cal-panel-unified';
				this.rootEl.appendChild(this.panelEl);
			}

			this.mountEl.innerHTML = '';
			this.mountEl.appendChild(this.rootEl);
		}

		renderMonthHeader(jy, jm, options = {}) {
			const showPrev = Boolean(options.showPrev);
			const showNext = Boolean(options.showNext);
			const showBadge = Boolean(options.showBadge);

			const header = document.createElement('div');
			header.className = 'mtal-cal-header';

			const nextMonth = addMonthsJalali(this.viewYear, this.viewMonth, 1);
			const prevMonth = addMonthsJalali(this.viewYear, this.viewMonth, -1);

			const minMonthIndex = this.minDate.jy * 12 + (this.minDate.jm - 1);
			const maxMonthIndex = this.maxDate.jy * 12 + (this.maxDate.jm - 1);
			const currMonthIndex = this.viewYear * 12 + (this.viewMonth - 1);
			const visibleSpan = this.mode === 'inline_2col' || (this.mode === 'inline_responsive' && window.matchMedia('(min-width: 1025px)').matches) ? 2 : 1;
			const lastVisibleMonthIndex = currMonthIndex + (visibleSpan - 1);

			if (showNext) {
				const nextBtn = document.createElement('button');
				nextBtn.type = 'button';
				nextBtn.className = 'mtal-cal-nav mtal-cal-next';
				nextBtn.setAttribute('aria-label', 'ماه بعد');
				nextBtn.textContent = '‹';
				nextBtn.disabled = lastVisibleMonthIndex >= maxMonthIndex;

				nextBtn.addEventListener('click', () => {
					if (!nextBtn.disabled) {
						this.viewYear = nextMonth.jy;
						this.viewMonth = nextMonth.jm;
						this.render();
					}
				});

				header.appendChild(nextBtn);
			} else {
				const spacer = document.createElement('span');
				spacer.className = 'mtal-cal-nav-spacer';
				header.appendChild(spacer);
			}

			const titleWrap = document.createElement('div');
			titleWrap.className = 'mtal-cal-title-wrap';

			const titleEl = document.createElement('div');
			titleEl.className = 'mtal-cal-month-title';
			titleEl.textContent = `${PERSIAN_MONTHS[jm - 1]} ${toPersianDigits(jy)}`;
			titleWrap.appendChild(titleEl);

			if (showBadge) {
				const badgeEl = document.createElement('span');
				badgeEl.className = 'mtal-cal-horizon-badge';
				badgeEl.textContent = `حداکثر ${toPersianDigits(this.maxMonths)} ماه`;
				titleWrap.appendChild(badgeEl);
			}

			header.appendChild(titleWrap);

			if (showPrev) {
				const prevBtn = document.createElement('button');
				prevBtn.type = 'button';
				prevBtn.className = 'mtal-cal-nav mtal-cal-prev';
				prevBtn.setAttribute('aria-label', 'ماه قبل');
				prevBtn.textContent = '›';
				prevBtn.disabled = currMonthIndex <= minMonthIndex;

				prevBtn.addEventListener('click', () => {
					if (!prevBtn.disabled) {
						this.viewYear = prevMonth.jy;
						this.viewMonth = prevMonth.jm;
						this.render();
					}
				});

				header.appendChild(prevBtn);
			} else {
				const spacer = document.createElement('span');
				spacer.className = 'mtal-cal-nav-spacer';
				header.appendChild(spacer);
			}

			return header;
		}

		renderMonthBlock(jy, jm, activeStart, activeEnd, headerOpts = {}) {
			const block = document.createElement('div');
			block.className = 'mtal-cal-month-block';

			block.appendChild(this.renderMonthHeader(jy, jm, headerOpts));

			const weekdaysRow = document.createElement('div');
			weekdaysRow.className = 'mtal-cal-weekdays';
			PERSIAN_WEEKDAYS_SHORT.forEach((wd, idx) => {
				const wdEl = document.createElement('div');
				wdEl.className = 'mtal-cal-weekday';
				if (idx === 6) {
					wdEl.classList.add('is-friday-col');
				}
				wdEl.textContent = wd;
				weekdaysRow.appendChild(wdEl);
			});
			block.appendChild(weekdaysRow);

			const daysGrid = document.createElement('div');
			daysGrid.className = 'mtal-cal-days';

			const firstWeekday = getJalaliWeekdayIndex(jy, jm, 1);
			const totalDays = getJalaliMonthDays(jy, jm);

			for (let i = 0; i < firstWeekday; i++) {
				const emptyCell = document.createElement('div');
				emptyCell.className = 'mtal-cal-day is-empty';
				emptyCell.setAttribute('aria-hidden', 'true');
				daysGrid.appendChild(emptyCell);
			}

			const canSelectAsCheckout = Boolean(
				activeStart && !activeEnd && !this.isPastOrOutOfHorizon(activeStart)
			);

			const hasValidActiveRange = this.isRangeAllowed(activeStart, activeEnd);

			for (let day = 1; day <= totalDays; day++) {
				const dObj = { jy, jm, jd: day };
				const btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'mtal-cal-day';

				const dayLabel = document.createElement('span');
				dayLabel.className = 'mtal-day-num';
				dayLabel.textContent = toPersianDigits(day);
				btn.appendChild(dayLabel);

				const pastOrOut = this.isPastOrOutOfHorizon(dObj);
				const blocked = !pastOrOut && this.isDateBlocked(dObj);
				const currentJdn = jalaliToJdn(dObj.jy, dObj.jm, dObj.jd);
				const previousDay = jdnToJalali(currentJdn - 1);
				const previousDayBlocked = this.isDateBlocked(previousDay);

				const allowCheckoutOnBlocked =
					blocked &&
					canSelectAsCheckout &&
					compareDates(dObj, activeStart) > 0 &&
					!this.hasBlockedBetween(activeStart, dObj);

				const disabled = pastOrOut || (blocked && !allowCheckoutOnBlocked);

				if (!pastOrOut && !blocked) {
					const dayPriceInfo = this.getDayPrice(dObj);
					if (dayPriceInfo.price > 0) {
						const priceTag = document.createElement('span');
						priceTag.className = 'mtal-day-price';
						priceTag.textContent = formatShortPricePersian(dayPriceInfo.price);
						btn.appendChild(priceTag);
					}
				}

				if (disabled) {
					btn.disabled = true;
					btn.classList.add('is-disabled');
				}
				if (blocked) {
					btn.classList.add('is-blocked');
					if (!previousDayBlocked) {
						btn.classList.add('is-blocked-range-start');
					}
					btn.setAttribute(
						'title',
						allowCheckoutOnBlocked
							? 'امکان انتخاب به عنوان تاریخ خروج (تحویل تا ۱۲ ظهر)'
							: 'مسدود / رزرو شده'
					);
				}

				if (!blocked && !pastOrOut && previousDayBlocked) {
					btn.classList.add('is-blocked-checkout-edge');
					btn.setAttribute('title', 'روز تخلیه؛ تحویل تا ساعت ۱۲ و آماده تحویل از ساعت ۱۴');
				}

				if (!pastOrOut && this.isFriday(dObj)) {
					btn.classList.add('is-friday');
				}
				if (!pastOrOut && this.isHoliday(dObj)) {
					btn.classList.add('is-holiday');
				}

				if (isSameDate(dObj, this.today)) {
					btn.classList.add('is-today');
				}

				const isStart = activeStart && isSameDate(dObj, activeStart);
				const isEnd = hasValidActiveRange && activeEnd && isSameDate(dObj, activeEnd);

				if (isStart) {
					btn.classList.add('is-start', 'is-selected');
				}
				if (isEnd) {
					btn.classList.add('is-end', 'is-selected');
				}
				if (
					hasValidActiveRange &&
					compareDates(dObj, activeStart) > 0 &&
					compareDates(dObj, activeEnd) < 0 &&
					!blocked
				) {
					btn.classList.add('is-in-range');
				}

				if (!disabled) {
					btn.addEventListener('click', () => this.handleDaySelect(dObj));
				}

				daysGrid.appendChild(btn);
			}

			block.appendChild(daysGrid);
			return block;
		}

		createSummaryBoxes(start, end) {
			const hasValidRange = this.isRangeAllowed(start, end);
			const wrapper = document.createElement('div');
			wrapper.className = 'mtal-cal-summary-boxes';

			const inBox = document.createElement('div');
			inBox.className = 'mtal-cal-date-box';
			inBox.innerHTML = `<span class="lbl">تاریخ ورود</span><strong class="val">${
				start ? formatFullPersian(start) : '—'
			}</strong>`;

			const outBox = document.createElement('div');
			outBox.className = 'mtal-cal-date-box';
			outBox.innerHTML = `<span class="lbl">تاریخ خروج</span><strong class="val">${
				hasValidRange ? formatFullPersian(end) : '—'
			}</strong>`;

			wrapper.appendChild(inBox);
			wrapper.appendChild(outBox);
			return wrapper;
		}

		createLegendBar() {
			const legend = document.createElement('div');
			legend.className = 'mtal-cal-legend';
			legend.innerHTML = `
				<span class="mtal-legend-item"><i class="dot is-red"></i>جمعه و تعطیلات رسمی</span>
				<span class="mtal-legend-item"><i class="dot is-blocked"></i>مسدود / رزرو شده</span>
			`;
			return legend;
		}

		createHandoverNoteBox() {
			const note = document.createElement('div');
			note.className = 'mtal-cal-handover-note';
			note.innerHTML =
				'ورود از ساعت <strong>۱۴</strong> و خروج تا ساعت <strong>۱۲</strong>؛ انتخاب بازه شامل روز مسدود مجاز نیست.';
			return note;
		}

		render() {
			this.enforceValidState();
			if (!this.panelEl) return;

			if (this.mode === 'modal_3months') {
				this.renderModalMode();
			} else if (this.mode === 'inline_responsive') {
				this.renderInlineResponsiveMode();
			} else if (this.mode === 'inline_2col') {
				this.renderInlineTwoColMode();
			} else {
				this.renderInlineMode();
			}
		}

		renderInlineResponsiveMode() {
			this.panelEl.innerHTML = '';

			const monthsContainer = document.createElement('div');
			monthsContainer.className = 'mtal-cal-months-grid is-responsive';
			const secondMonth = addMonthsJalali(this.viewYear, this.viewMonth, 1);

			const firstBlock = this.renderMonthBlock(
				this.viewYear,
				this.viewMonth,
				this.startDate,
				this.endDate,
				{ showPrev: true, showNext: !this.responsiveDesktop, showBadge: true }
			);
			const secondBlock = this.renderMonthBlock(
				secondMonth.jy,
				secondMonth.jm,
				this.startDate,
				this.endDate,
				{ showPrev: false, showNext: this.responsiveDesktop, showBadge: false }
			);

			monthsContainer.appendChild(firstBlock);
			monthsContainer.appendChild(secondBlock);
			this.panelEl.appendChild(monthsContainer);

			const footer = document.createElement('div');
			footer.className = 'mtal-cal-footer mtal-cal-footer-display';
			footer.appendChild(this.createLegendBar());
			footer.appendChild(this.createHandoverNoteBox());
			if (this.warningMessage) {
				const warning = document.createElement('div');
				warning.className = 'mtal-cal-warning';
				warning.textContent = this.warningMessage;
				footer.appendChild(warning);
			}
			this.panelEl.appendChild(footer);
		}

		renderInlineMode() {
			this.panelEl.innerHTML = '';

			const monthsContainer = document.createElement('div');
			monthsContainer.className = 'mtal-cal-months-grid is-single';

			const monthBlock = this.renderMonthBlock(
				this.viewYear,
				this.viewMonth,
				this.startDate,
				this.endDate,
				{ showPrev: true, showNext: true, showBadge: true }
			);
			monthsContainer.appendChild(monthBlock);
			this.panelEl.appendChild(monthsContainer);

			this.panelEl.appendChild(this.createUnifiedInlineFooter());
		}

		renderInlineTwoColMode() {
			this.panelEl.innerHTML = '';

			const monthsContainer = document.createElement('div');
			monthsContainer.className = 'mtal-cal-months-grid is-two-col';

			const firstMonth = { jy: this.viewYear, jm: this.viewMonth };
			const secondMonth = addMonthsJalali(this.viewYear, this.viewMonth, 1);

			const block1 = this.renderMonthBlock(
				firstMonth.jy,
				firstMonth.jm,
				this.startDate,
				this.endDate,
				{ showPrev: true, showNext: false, showBadge: true }
			);

			const block2 = this.renderMonthBlock(
				secondMonth.jy,
				secondMonth.jm,
				this.startDate,
				this.endDate,
				{ showPrev: false, showNext: true, showBadge: false }
			);

			monthsContainer.appendChild(block1);
			monthsContainer.appendChild(block2);
			this.panelEl.appendChild(monthsContainer);

			this.panelEl.appendChild(this.createUnifiedInlineFooter());
		}

		createUnifiedInlineFooter() {
			const footer = document.createElement('div');
			footer.className = 'mtal-cal-footer mtal-cal-footer-unified';

			footer.appendChild(this.createLegendBar());
			footer.appendChild(this.createHandoverNoteBox());

			if (this.warningMessage) {
				const warnEl = document.createElement('div');
				warnEl.className = 'mtal-cal-warning';
				warnEl.textContent = this.warningMessage;
				footer.appendChild(warnEl);
			}

			footer.appendChild(this.createSummaryBoxes(this.startDate, this.endDate));

			const breakdownBox = this.createPricingBreakdownBox(this.startDate, this.endDate);
			if (breakdownBox) {
				footer.appendChild(breakdownBox);
			}

			const bottomRow = document.createElement('div');
			bottomRow.className = 'mtal-cal-footer-bottom';

			const statusWrap = document.createElement('div');
			statusWrap.className = 'mtal-cal-status-wrap';

			const badgeEl = document.createElement('span');
			badgeEl.className = 'mtal-cal-nights-badge';

			if (this.isRangeAllowed(this.startDate, this.endDate)) {
				const pricing = this.calculateRangePricing(this.startDate, this.endDate);
				badgeEl.textContent = `${toPersianDigits(pricing.nights)} شب · ${toPersianDigits(this.guestsCount)} نفر · ${formatFullTomanPersian(pricing.totalPrice)}`;
				badgeEl.style.display = 'inline-flex';
			} else if (this.startDate) {
				badgeEl.textContent = 'تاریخ خروج را انتخاب کنید';
				badgeEl.style.display = 'inline-flex';
			} else {
				badgeEl.textContent = 'بازه تاریخ انتخاب نشده است';
				badgeEl.style.display = 'inline-flex';
			}

			statusWrap.appendChild(badgeEl);

			const clearBtn = document.createElement('button');
			clearBtn.type = 'button';
			clearBtn.className = 'mtal-cal-clear-btn';
			clearBtn.textContent = 'پاک کردن';
			clearBtn.addEventListener('click', () => this.clear({ notify: true }));

			bottomRow.appendChild(statusWrap);
			bottomRow.appendChild(clearBtn);
			footer.appendChild(bottomRow);

			return footer;
		}

		renderModalMode() {
			if (this.triggerBtnEl) {
				this.triggerBtnEl.textContent = this.triggerLabel;
				this.triggerBtnEl.classList.toggle('has-selection', Boolean(this.startDate && this.endDate));
			}

			this.panelEl.innerHTML = '';

			for (let offset = 0; offset < 3; offset++) {
				const m = addMonthsJalali(this.today.jy, this.today.jm, offset);
				const block = this.renderMonthBlock(
					m.jy,
					m.jm,
					this.draftStart,
					this.draftEnd,
					{ showPrev: false, showNext: false, showBadge: false }
				);
				this.panelEl.appendChild(block);
			}

			if (this.modalFooterEl) {
				this.modalFooterEl.innerHTML = '';

				// Keep the modal focused on date selection while retaining the clear action.
				this.modalFooterEl.appendChild(this.createLegendBar());
				this.modalFooterEl.appendChild(this.createHandoverNoteBox());

				if (this.warningMessage) {
					const warnEl = document.createElement('div');
					warnEl.className = 'mtal-cal-warning';
					warnEl.textContent = this.warningMessage;
					this.modalFooterEl.appendChild(warnEl);
				}

				const actions = document.createElement('div');
				actions.className = 'mtal-cal-modal-actions';
				const clearBtn = document.createElement('button');
				clearBtn.type = 'button';
				clearBtn.className = 'mtal-cal-clear-btn';
				clearBtn.textContent = 'پاک کردن';
				clearBtn.addEventListener('click', () => this.clear({ notify: true }));
				actions.appendChild(clearBtn);
				this.modalFooterEl.appendChild(actions);
			}
		}
	}

	window.MTALPersianCalendar = MTALPersianCalendar;
})(window, document);