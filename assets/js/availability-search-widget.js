(function () {
	'use strict';

	const cfg = window.mtalCalendarConfig || {};
	const toPersian = (value) => String(value).replace(/\d/g, (digit) => '۰۱۲۳۴۵۶۷۸۹'[Number(digit)]);

	function initAvailabilitySearch(root = document) {
		const hosts = [];
		if (root.matches && root.matches('.mtal-availability-search:not([data-mtal-initialized="1"])')) hosts.push(root);
		if (root.querySelectorAll) {
			hosts.push(...root.querySelectorAll('.mtal-availability-search:not([data-mtal-initialized="1"])'));
		}

		hosts.forEach((host) => {
			host.setAttribute('data-mtal-initialized', '1');
			const mount = host.querySelector('.mtal-availability-calendar-source');
			const fields = Array.from(host.querySelectorAll('.mtal-availability-date-field'));
			const searchButton = host.querySelector('.mtal-availability-search-button');
			const status = host.querySelector('.mtal-availability-search-status');
			const results = host.querySelector('.mtal-availability-search-results');
			const fieldValues = {
				checkIn: fields.find((field) => field.dataset.dateField === 'check-in'),
				checkOut: fields.find((field) => field.dataset.dateField === 'check-out')
			};
			let range = { checkIn: '', checkOut: '', checkInShort: '', checkOutShort: '' };
			let requestId = 0;

			const setField = (field, value) => {
				if (!field) return;
				const valueEl = field.querySelector('.mtal-availability-date-value');
				if (valueEl) valueEl.textContent = value || 'انتخاب تاریخ';
				field.classList.toggle('has-value', Boolean(value));
			};

			const clearSearchState = () => {
				requestId++;
				results.hidden = true;
				results.innerHTML = '';
				status.hidden = true;
				status.textContent = '';
				searchButton.disabled = !range.checkIn || !range.checkOut;
			};

			const calendar = new window.MTALPersianCalendar({
				mode: 'modal_3months',
				mountEl: mount,
				maxMonths: 3,
				onChange(payload) {
					const nextIn = payload && payload.checkInSlash ? payload.checkInSlash : '';
					const nextOut = payload && payload.checkOutSlash ? payload.checkOutSlash : '';
					const changed = nextIn !== range.checkIn || nextOut !== range.checkOut;
					range = {
						checkIn: nextIn,
						checkOut: nextOut,
						checkInShort: payload && payload.checkInShort ? payload.checkInShort : '',
						checkOutShort: payload && payload.checkOutShort ? payload.checkOutShort : ''
					};
					setField(fieldValues.checkIn, range.checkInShort);
					setField(fieldValues.checkOut, range.checkOutShort);
					searchButton.hidden = !(range.checkIn && range.checkOut);
					searchButton.disabled = !(range.checkIn && range.checkOut);
					if (changed) clearSearchState();
				}
			});

			fields.forEach((field) => {
				field.addEventListener('click', () => {
					if (calendar && calendar.openModal) calendar.openModal();
				});
			});

			const renderResults = (suites) => {
				results.innerHTML = '';
				if (!Array.isArray(suites) || !suites.length) {
					results.textContent = 'هیچ سوئیتی در این تاریخ وجود ندارد';
					results.classList.add('is-empty');
					results.hidden = false;
					return;
				}

				results.classList.remove('is-empty');
				const list = document.createElement('div');
				list.className = 'mtal-availability-suite-list';
				suites.forEach((suite) => {
					const link = document.createElement('a');
					link.className = 'mtal-availability-suite';
					link.href = suite.url || '#';
					link.setAttribute('aria-label', (suite.title || 'سوئیت') + '، گنجایش ' + toPersian(suite.maxCapacity || 1) + ' نفر');

					const imageWrap = document.createElement('span');
					imageWrap.className = 'mtal-availability-suite-image';
					if (suite.imageUrl) {
						const image = document.createElement('img');
						image.src = suite.imageUrl;
						image.alt = suite.title || 'تصویر سوئیت';
						image.loading = 'lazy';
						image.decoding = 'async';
						imageWrap.appendChild(image);
					} else {
						imageWrap.textContent = '⌂';
					}

					const title = document.createElement('span');
					title.className = 'mtal-availability-suite-title';
					title.textContent = suite.title || 'سوئیت';

					const capacity = document.createElement('span');
					capacity.className = 'mtal-availability-suite-capacity';
					capacity.textContent = 'گنجایش: ' + toPersian(suite.maxCapacity || 1) + ' نفر';

					link.append(imageWrap, title, capacity);
					list.appendChild(link);
				});
				results.appendChild(list);
				results.hidden = false;
			};

			searchButton.addEventListener('click', () => {
				if (!range.checkIn || !range.checkOut || !cfg.ajaxUrl) return;
				const currentRequest = ++requestId;
				searchButton.disabled = true;
				status.hidden = false;
				status.textContent = 'در حال بررسی سوئیت‌های قابل رزرو…';
				results.hidden = true;
				results.innerHTML = '';

				const formData = new FormData();
				formData.append('action', 'mtal_search_available_suites');
				formData.append('nonce', cfg.nonce || '');
				formData.append('check_in', range.checkIn);
				formData.append('check_out', range.checkOut);

				fetch(cfg.ajaxUrl, {
					method: 'POST',
					body: formData,
					credentials: 'same-origin'
				})
					.then((response) => response.json())
					.then((response) => {
						if (currentRequest !== requestId) return;
						status.hidden = true;
						if (!response || !response.success || !response.data) {
							status.hidden = false;
							status.textContent = response && response.data && response.data.message
								? response.data.message
								: 'بررسی تاریخ‌ها با خطا مواجه شد؛ لطفاً دوباره تلاش کنید.';
							return;
						}
						renderResults(response.data.suites || []);
					})
					.catch(() => {
						if (currentRequest !== requestId) return;
						status.hidden = false;
						status.textContent = 'ارتباط با سرور برقرار نشد؛ لطفاً دوباره تلاش کنید.';
					})
					.finally(() => {
						if (currentRequest === requestId) searchButton.disabled = false;
					});
			});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', () => initAvailabilitySearch());
	} else {
		initAvailabilitySearch();
	}

	window.addEventListener('elementor/popup/show', () => initAvailabilitySearch());
	if (window.jQuery) {
		window.jQuery(document).on('elementor/popup/show jet-engine/ajax-filters/applied', () => initAvailabilitySearch());
	}
})();
