(function (window, document) {
	'use strict';

	if (typeof window.MTALPersianCalendar !== 'function') return;

	const cfg = window.mtalCalendarConfig || {};
	const digits = '۰۱۲۳۴۵۶۷۸۹';
	const toPersian = (value) => String(value).replace(/\d/g, (digit) => digits[Number(digit)]);
	const formatPrice = (value) => toPersian(Math.max(0, Number(value) || 0).toLocaleString('en-US'));
	const sourceLabels = {
		regular: 'شب عادی',
		weekend: 'شب آخر هفته',
		manual: 'شب نرخ ویژه'
	};

	function postAjax(fields) {
		const body = new FormData();
		Object.keys(fields).forEach((key) => body.append(key, String(fields[key])));
		return fetch(cfg.ajaxUrl, {
			method: 'POST',
			body,
			credentials: 'same-origin'
		}).then((response) => response.json());
	}

	function buildServerPricingBox(cal, data) {
		const pricing = document.createElement('div');
		pricing.className = 'mtal-pricing-breakdown';
		pricing.setAttribute('dir', 'rtl');

		const stayRow = document.createElement('div');
		stayRow.className = 'mtal-breakdown-row is-stay';
		stayRow.innerHTML = '<span>مدت اقامت</span><strong>' + toPersian(data.nights || 0) + ' شب</strong>';
		pricing.appendChild(stayRow);

		const groups = new Map();
		(Array.isArray(data.breakdown) ? data.breakdown : []).forEach((night) => {
			const source = night.source || 'regular';
			const unitPrice = Number(night.price) || 0;
			const key = source + '__' + unitPrice;
			if (!groups.has(key)) groups.set(key, { source, unitPrice, count: 0 });
			groups.get(key).count += 1;
		});

		groups.forEach((group) => {
			const row = document.createElement('div');
			row.className = 'mtal-breakdown-row';
			const label = sourceLabels[group.source] || sourceLabels.regular;
			row.innerHTML = '<span>' + label + '</span><strong>' +
				toPersian(group.count) + ' × ' + formatPrice(group.unitPrice) + '</strong>';
			pricing.appendChild(row);
		});

		const totalRow = document.createElement('div');
		totalRow.className = 'mtal-breakdown-row is-total';
		totalRow.innerHTML = '<span>مبلغ کل (ظرفیت پایه)</span><strong>' +
			formatPrice(data.totalPrice) + ' تومان</strong>';
		pricing.appendChild(totalRow);
		return pricing;
	}

	function initDisplayHost(host) {
		if (host.getAttribute('data-mtal-display-initialized') === '1') return;
		host.setAttribute('data-mtal-display-initialized', '1');

		const select = host.querySelector('.mtal-cal-display-room');
		const calendarWrap = host.querySelector('.mtal-cal-display-calendar');
		const mount = host.querySelector('.mtal-cal-display-mount');
		const pricingWrap = host.querySelector('.mtal-cal-display-pricing');
		const clearButton = host.querySelector('.mtal-cal-display-clear');
		const bookButton = host.querySelector('.mtal-cal-display-book');
		const message = host.querySelector('.mtal-cal-display-message');
		const targetSelector = host.getAttribute('data-mtal-target-widget') || '.mtal-cal-inline-host, .mtal-cal-modal-host';
		let selectedReference = 0;
		let calendar = null;
		let latestRules = null;
		let priceRequestId = 0;
		let priceReady = false;

		if (!select || !calendarWrap || !mount || !pricingWrap || !clearButton || !bookButton) return;

		function setMessage(text) {
			if (message) message.textContent = text || '';
		}

		function clearPrice() {
			priceReady = false;
			pricingWrap.hidden = true;
			pricingWrap.innerHTML = '';
			bookButton.disabled = true;
		}

		function showClientPricing(payload) {
			if (!payload || !payload.checkIn || !payload.checkOut || !calendar) {
				clearPrice();
				if (payload && payload.checkIn) {
					pricingWrap.hidden = false;
					pricingWrap.innerHTML = '<p class="mtal-cal-display-message">تاریخ ورود انتخاب شد؛ لطفاً تاریخ خروج را مشخص کنید.</p>';
				}
				return;
			}

			clearPrice();
			pricingWrap.hidden = false;
			pricingWrap.innerHTML = '<p class="mtal-cal-display-message">در حال محاسبه قیمت از اطلاعات ثبت‌شده در وردپرس...</p>';
			const requestId = ++priceRequestId;
			const baseCapacity = Math.max(1, Number(latestRules && latestRules.baseCapacity) || 1);
			postAjax({
				action: 'mtal_calculate_suite_price',
				nonce: cfg.nonce || '',
				post_id: selectedReference,
				check_in: payload.checkInSlash,
				check_out: payload.checkOutSlash,
				guests: baseCapacity,
				max_months: 3
			}).then((result) => {
				if (requestId !== priceRequestId) return;
				if (!result || !result.success || !result.data) {
					pricingWrap.innerHTML = '<p class="mtal-cal-display-message">' +
						((result && result.data && result.data.message) || 'محاسبه قیمت انجام نشد؛ لطفاً تاریخ دیگری انتخاب کنید.') +
						'</p>';
					priceReady = false;
					bookButton.disabled = true;
					return;
				}
				pricingWrap.innerHTML = '';
				pricingWrap.appendChild(buildServerPricingBox(calendar, result.data));
				priceReady = Number(result.data.totalPrice) > 0;
				bookButton.disabled = !priceReady;
				setMessage('قیمت بر اساس ظرفیت پایه سوئیت محاسبه شده است؛ مبلغ نهایی پس از انتخاب تعداد مسافران در ویجت رزرو مشخص می‌شود.');
			}).catch(() => {
				if (requestId !== priceRequestId) return;
				pricingWrap.innerHTML = '<p class="mtal-cal-display-message">ارتباط با سرور برای محاسبه قیمت برقرار نشد.</p>';
				priceReady = false;
				bookButton.disabled = true;
			});
		}

		function onCalendarChange(payload) {
			showClientPricing(payload);
			if (!payload || !payload.checkIn || !payload.checkOut) {
				bookButton.disabled = true;
			}
		}

		function fetchSuiteRules(referenceId) {
			return postAjax({
				action: 'mtal_get_suite_calendar_rules',
				post_id: referenceId
			}).then((result) => {
				if (!result || !result.success || !result.data) {
					throw new Error(result && result.data && result.data.message ? result.data.message : 'اطلاعات تقویم سوئیت دریافت نشد.');
				}
				return result.data;
			});
		}

		function applySuiteRules(referenceId, rules) {
			selectedReference = Number(referenceId);
			latestRules = rules;
			host.setAttribute('data-mtal-post-id', String(selectedReference));
			const options = {
				mode: 'inline_responsive',
				mountEl: mount,
				maxMonths: 3,
				blockedDates: rules.blockedDates || [],
				holidayDates: rules.holidayDates || [],
				manualPrices: rules.manualPrices || {},
				regularPrice: rules.regularPrice || 0,
				weekendPrice: rules.weekendPrice || 0,
				baseCapacity: rules.baseCapacity || 1,
				extraGuestPrice: rules.extraGuestPrice || 0,
				guestsCount: rules.baseCapacity || 1,
				onChange: onCalendarChange,
				onRangeCompleted: function () {}
			};

			if (!calendar) {
				calendar = new window.MTALPersianCalendar(options);
			} else {
				calendar.clear({ notify: false });
				calendar.updateRules(rules);
				calendar.setGuestsCount(rules.baseCapacity || 1, { notify: false });
			}

			calendarWrap.hidden = false;
			clearPrice();
			setMessage('تاریخ ورود و خروج را از تقویم انتخاب کنید.');
		}

		function loadSuite(referenceId) {
			const id = Number(referenceId) || 0;
			priceRequestId += 1;
			clearPrice();
			if (!id) {
				selectedReference = 0;
				calendarWrap.hidden = true;
				if (calendar) calendar.clear({ notify: false });
				setMessage('');
				return;
			}

			calendarWrap.hidden = true;
			setMessage('در حال دریافت تقویم و قیمت‌های سوئیت...');
			fetchSuiteRules(id).then((rules) => {
				if (Number(select.value) !== id) return;
				applySuiteRules(id, rules);
			}).catch((error) => {
				calendarWrap.hidden = true;
				setMessage(error.message || 'دریافت اطلاعات سوئیت انجام نشد.');
			});
		}

		select.addEventListener('change', () => loadSuite(select.value));

		clearButton.addEventListener('click', () => {
			if (calendar) calendar.clear({ notify: true });
			clearPrice();
			setMessage('انتخاب تاریخ پاک شد؛ می‌توانید بازه جدیدی انتخاب کنید.');
		});

		bookButton.addEventListener('click', () => {
			if (!calendar || !priceReady || !calendar.startDate || !calendar.endDate ||
				!calendar.isRangeAllowed(calendar.startDate, calendar.endDate)) {
				setMessage('ابتدا یک بازه معتبر انتخاب کنید و منتظر محاسبه قیمت بمانید.');
				return;
			}

			bookButton.disabled = true;
			const bookingReference = selectedReference;
			setMessage('در حال انتقال تاریخ‌ها به فرم رزرو...');
			fetchSuiteRules(bookingReference).then((rules) => {
				if (selectedReference !== bookingReference) {
					throw new Error('سوئیت انتخابی تغییر کرده است؛ دوباره تلاش کنید.');
				}
				calendar.updateRules(rules);
				if (!calendar.isRangeAllowed(calendar.startDate, calendar.endDate)) {
					clearPrice();
					throw new Error('وضعیت تقویم تغییر کرده است؛ لطفاً تاریخ‌ها را دوباره انتخاب کنید.');
				}
				latestRules = rules;
				if (!window.MTALBookingBridge || typeof window.MTALBookingBridge.startFromDisplay !== 'function') {
					throw new Error('ویجت اصلی انتخاب مشتری در این صفحه پیدا نشد. لطفاً ویجت اصلی رزرو را در همین صفحه قرار دهید.');
				}
				return window.MTALBookingBridge.startFromDisplay({
					postId: selectedReference,
					checkIn: calendar.startDate,
					checkOut: calendar.endDate,
					targetSelector: targetSelector,
					rules: rules
				});
			}).then((started) => {
				if (!started) {
					throw new Error('ویجت اصلی انتخاب مشتری پیدا نشد؛ سلکتور ویجت مقصد را بررسی کنید.');
				}
				setMessage('تاریخ‌ها منتقل شدند؛ انتخاب تعداد مسافران را در بخش رزرو ادامه دهید.');
			}).catch((error) => {
				bookButton.disabled = !priceReady;
				setMessage(error.message || 'انتقال تاریخ‌ها انجام نشد.');
			});
		});

		const initialReference = Number(host.getAttribute('data-mtal-display-suite')) || Number(select.value) || 0;
		if (initialReference) {
			select.value = String(initialReference);
			loadSuite(initialReference);
		} else {
			calendarWrap.hidden = true;
			setMessage(select.options.length <= 1 ? 'سوئیت قابل رزروی برای نمایش وجود ندارد.' : 'برای مشاهده تقویم، ابتدا سوئیت را انتخاب کنید.');
		}
	}

	function boot(root) {
		const scope = root || document;
		const hosts = [];
		if (scope.matches && scope.matches('.mtal-cal-display-host')) hosts.push(scope);
		if (scope.querySelectorAll) hosts.push(...scope.querySelectorAll('.mtal-cal-display-host'));
		hosts.forEach(initDisplayHost);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', () => boot(document));
	} else {
		boot(document);
	}
	window.addEventListener('elementor/popup/show', () => boot(document));
	if (window.jQuery) {
		window.jQuery(document).on('jet-engine/ajax-filters/applied', () => window.setTimeout(() => boot(document), 250));
	}
})(window, document);
