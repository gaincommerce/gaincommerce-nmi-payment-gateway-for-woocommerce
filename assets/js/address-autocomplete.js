/**
 * Address autocomplete for the checkout address fields (Blocks + classic).
 *
 * - Uses Google Places API (New) data API (AutocompleteSuggestion) with our own small
 *   dropdown, so it can attach to the existing WooCommerce inputs, including the
 *   React-controlled ones in the Blocks checkout.
 * - The Google script is requested only when the shopper first focuses an address field.
 * - Any failure (bad key, quota, blocked script) silently turns the feature off for the
 *   page; the address fields keep working as normal inputs.
 */
(function () {
	'use strict';

	var cfg = window.apnmiAddressAutocomplete;
	if (!cfg || !cfg.apiKey) {
		return;
	}

	var MIN_CHARS = 3;
	var DEBOUNCE_MS = 220;
	// Countries that write the house number before the street name.
	var NUMBER_FIRST = ['US', 'CA', 'GB', 'IE', 'AU', 'NZ', 'ZA', 'SG', 'MY', 'PH', 'TH', 'IN', 'HK'];

	var disabled = false;
	var placesPromise = null;
	var instances = [];
	var uid = 0;

	// ── Google loader ────────────────────────────────────────────────────────────

	function disableAll() {
		if (disabled) {
			return;
		}
		disabled = true;
		instances.forEach(function (inst) {
			inst.close();
		});
	}

	function loadPlaces() {
		if (placesPromise) {
			return placesPromise;
		}

		placesPromise = new Promise(function (resolve, reject) {
			function ready() {
				if (window.google && google.maps && typeof google.maps.importLibrary === 'function') {
					google.maps.importLibrary('places').then(resolve, reject);
				} else {
					reject(new Error('maps-unavailable'));
				}
			}

			// Another plugin/theme already loaded the Maps JS API: reuse it.
			if (window.google && google.maps && typeof google.maps.importLibrary === 'function') {
				ready();
				return;
			}

			// Invalid / restricted key: Google calls this global. Chain any existing handler.
			var previousAuthFailure = window.gm_authFailure;
			window.gm_authFailure = function () {
				disableAll();
				if (typeof previousAuthFailure === 'function') {
					previousAuthFailure();
				}
			};

			window.__apnmiMapsReady = ready;

			var src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(cfg.apiKey) +
				'&loading=async&v=weekly&callback=__apnmiMapsReady';
			if (cfg.language) {
				src += '&language=' + encodeURIComponent(cfg.language);
			}

			var script = document.createElement('script');
			script.src = src;
			script.async = true;
			script.onerror = function () {
				reject(new Error('load-failed'));
			};
			document.head.appendChild(script);
		});

		placesPromise.catch(disableAll);
		return placesPromise;
	}

	// ── Address parsing ──────────────────────────────────────────────────────────

	function parseComponents(components) {
		var byType = {};
		(components || []).forEach(function (comp) {
			(comp.types || []).forEach(function (t) {
				if (!byType[t]) {
					byType[t] = comp;
				}
			});
		});

		function long(t) {
			return byType[t] ? byType[t].longText || '' : '';
		}
		function short(t) {
			return byType[t] ? byType[t].shortText || '' : '';
		}

		var country = short('country').toUpperCase();
		var number = long('street_number');
		var route = long('route');
		var line1 = (NUMBER_FIRST.indexOf(country) !== -1 ? [number, route] : [route, number])
			.filter(Boolean)
			.join(' ');

		var sub = long('subpremise');
		if (sub && /^\d/.test(sub)) {
			sub = '#' + sub;
		}

		return {
			address_1: line1,
			address_2: sub,
			city: long('locality') || long('postal_town') || long('sublocality_level_1') ||
				long('sublocality') || long('administrative_area_level_3'),
			stateCandidates: [
				short('administrative_area_level_1'),
				long('administrative_area_level_1'),
				short('administrative_area_level_2'),
				long('administrative_area_level_2')
			].filter(Boolean),
			postcode: long('postal_code'),
			country: country
		};
	}

	/**
	 * Pick the WooCommerce state code that matches Google's value for the given country.
	 */
	function resolveStateBlocks(country, candidates) {
		var data = null;
		try {
			data = window.wc && wc.wcSettings && wc.wcSettings.getSetting
				? wc.wcSettings.getSetting('countryData', null)
				: null;
		} catch (e) {
			data = null;
		}

		var states = data && data[country] && data[country].states;
		if (!states || typeof states !== 'object') {
			// Country data unavailable: best effort.
			return candidates[0] || '';
		}

		var codes = Object.keys(states);
		if (!codes.length) {
			return ''; // Country has no state list.
		}

		for (var i = 0; i < candidates.length; i++) {
			var c = String(candidates[i]).toLowerCase();
			for (var j = 0; j < codes.length; j++) {
				if (codes[j].toLowerCase() === c || String(states[codes[j]]).toLowerCase() === c) {
					return codes[j];
				}
			}
		}
		return '';
	}

	function matchSelectOption(select, candidates) {
		for (var i = 0; i < candidates.length; i++) {
			var c = String(candidates[i]).toLowerCase();
			for (var j = 0; j < select.options.length; j++) {
				var opt = select.options[j];
				if (!opt.value) {
					continue;
				}
				if (opt.value.toLowerCase() === c || opt.text.trim().toLowerCase() === c) {
					return opt.value;
				}
			}
		}
		return '';
	}

	// ── Writing values into the form ─────────────────────────────────────────────

	function nativeSet(el, value) {
		if (!el) {
			return;
		}
		var proto = el.tagName === 'SELECT' ? window.HTMLSelectElement.prototype : window.HTMLInputElement.prototype;
		var desc = Object.getOwnPropertyDescriptor(proto, 'value');
		if (desc && desc.set) {
			desc.set.call(el, value);
		} else {
			el.value = value;
		}
		el.dispatchEvent(new Event('input', { bubbles: true }));
		el.dispatchEvent(new Event('change', { bubbles: true }));
	}

	function fieldEl(type, mode, name) {
		return document.getElementById(mode === 'blocks' ? type + '-' + name : type + '_' + name);
	}

	/**
	 * Blocks checkout: write through the cart data store so React state and the saved
	 * customer address stay in sync. Returns false if the store isn't available.
	 */
	function fillBlocksStore(type, a) {
		var data = window.wp && wp.data;
		if (!data || typeof data.select !== 'function' || typeof data.dispatch !== 'function') {
			return false;
		}

		try {
			var select = data.select('wc/store/cart');
			var dispatch = data.dispatch('wc/store/cart');
			if (!select || !dispatch) {
				return false;
			}

			var customer = typeof select.getCustomerData === 'function' ? select.getCustomerData() : null;
			var key = type === 'shipping' ? 'shippingAddress' : 'billingAddress';
			var current = (customer && customer[key]) || {};

			var next = Object.assign({}, current, {
				address_1: a.address_1,
				address_2: a.address_2,
				city: a.city,
				state: resolveStateBlocks(a.country, a.stateCandidates),
				postcode: a.postcode,
				country: a.country || current.country || ''
			});

			var setter = type === 'shipping' ? dispatch.setShippingAddress : dispatch.setBillingAddress;
			if (typeof setter !== 'function') {
				return false;
			}
			setter(next);

			// "Use same address for billing" hides the billing form; keep it in step.
			if (type === 'shipping' && !document.getElementById('billing-address_1') &&
				typeof dispatch.setBillingAddress === 'function') {
				dispatch.setBillingAddress(Object.assign({}, (customer && customer.billingAddress) || {}, next));
			}
			return true;
		} catch (e) {
			return false;
		}
	}

	function fillBlocksDom(type, a) {
		function set(name, value) {
			nativeSet(fieldEl(type, 'blocks', name), value);
		}
		if (a.country) {
			set('country', a.country);
		}
		set('address_1', a.address_1);
		set('address_2', a.address_2);
		set('city', a.city);
		set('postcode', a.postcode);
		var stateEl = fieldEl(type, 'blocks', 'state');
		if (stateEl) {
			set('state', stateEl.tagName === 'SELECT' ? matchSelectOption(stateEl, a.stateCandidates) : (a.stateCandidates[0] || ''));
		}
	}

	function fillBlocks(type, a) {
		var viaStore = fillBlocksStore(type, a);

		// Verify the visible field actually took the value; otherwise fall back to the DOM.
		setTimeout(function () {
			var input = fieldEl(type, 'blocks', 'address_1');
			if (!viaStore || (input && a.address_1 && input.value !== a.address_1)) {
				fillBlocksDom(type, a);
			}
		}, 400);
	}

	function fillClassic(type, a) {
		var $ = window.jQuery;

		function set(name, value) {
			var el = fieldEl(type, 'classic', name);
			if (!el) {
				return;
			}
			if ($) {
				$(el).val(value).trigger('change');
			} else {
				nativeSet(el, value);
			}
		}

		function fillRest() {
			set('address_1', a.address_1);
			set('address_2', a.address_2);
			set('city', a.city);
			set('postcode', a.postcode);

			var stateEl = fieldEl(type, 'classic', 'state');
			if (stateEl) {
				set('state', stateEl.tagName === 'SELECT' ? matchSelectOption(stateEl, a.stateCandidates) : (a.stateCandidates[0] || ''));
			}

			if ($) {
				$(document.body).trigger('update_checkout');
			}
		}

		var countryEl = fieldEl(type, 'classic', 'country');
		if (countryEl && a.country && countryEl.value !== a.country) {
			set('country', a.country);
			// WooCommerce rebuilds the state field when the country changes.
			setTimeout(fillRest, 60);
		} else {
			fillRest();
		}
	}

	// ── Per-input autocomplete ───────────────────────────────────────────────────

	function attach(input) {
		if (input.dataset.apnmiAc) {
			return;
		}
		input.dataset.apnmiAc = '1';

		// Another autocomplete is already bound to this field: leave it alone.
		if (input.classList.contains('pac-target-input') || input.getAttribute('aria-autocomplete') === 'list') {
			return;
		}

		var mode = input.id.indexOf('-') !== -1 ? 'blocks' : 'classic';
		var type = input.id.indexOf('shipping') === 0 ? 'shipping' : 'billing';
		var listId = 'apnmi-ac-' + (++uid);

		var panel = document.createElement('div');
		panel.className = 'apnmi-ac';
		panel.hidden = true;

		var list = document.createElement('ul');
		list.className = 'apnmi-ac__list';
		list.id = listId;
		list.setAttribute('role', 'listbox');
		panel.appendChild(list);

		var attribution = document.createElement('div');
		attribution.className = 'apnmi-ac__attribution';
		attribution.textContent = (cfg.i18n && cfg.i18n.poweredBy) || 'Powered by Google';
		panel.appendChild(attribution);

		var status = document.createElement('div');
		status.className = 'apnmi-ac__status';
		status.setAttribute('role', 'status');
		status.setAttribute('aria-live', 'polite');
		panel.appendChild(status);

		document.body.appendChild(panel);

		input.setAttribute('autocomplete', 'off');
		input.setAttribute('role', 'combobox');
		input.setAttribute('aria-autocomplete', 'list');
		input.setAttribute('aria-expanded', 'false');
		input.setAttribute('aria-controls', listId);

		var predictions = [];
		var active = -1;
		var token = null;
		var timer = null;
		var reqId = 0;
		var suppressUntil = 0;
		var open = false;

		function position() {
			var r = input.getBoundingClientRect();
			panel.style.left = (r.left + window.pageXOffset) + 'px';
			panel.style.top = (r.bottom + window.pageYOffset + 2) + 'px';
			panel.style.width = r.width + 'px';
		}

		function close() {
			clearTimeout(timer);
			reqId++;
			open = false;
			active = -1;
			predictions = [];
			panel.hidden = true;
			input.setAttribute('aria-expanded', 'false');
			input.removeAttribute('aria-activedescendant');
			window.removeEventListener('resize', position);
			window.removeEventListener('scroll', position, true);
		}

		function setActive(i) {
			var items = list.children;
			if (active >= 0 && items[active]) {
				items[active].classList.remove('is-active');
				items[active].setAttribute('aria-selected', 'false');
			}
			active = i;
			if (active >= 0 && items[active]) {
				items[active].classList.add('is-active');
				items[active].setAttribute('aria-selected', 'true');
				input.setAttribute('aria-activedescendant', items[active].id);
				if (items[active].scrollIntoView) {
					items[active].scrollIntoView({ block: 'nearest' });
				}
			} else {
				input.removeAttribute('aria-activedescendant');
			}
		}

		function render(items) {
			predictions = items;
			list.innerHTML = '';

			if (!items.length) {
				close();
				return;
			}

			items.forEach(function (p, i) {
				var li = document.createElement('li');
				li.className = 'apnmi-ac__item';
				li.id = listId + '-' + i;
				li.setAttribute('role', 'option');
				li.setAttribute('aria-selected', 'false');

				var main = document.createElement('strong');
				main.textContent = (p.mainText && p.mainText.text) || (p.text && p.text.text) || '';
				li.appendChild(main);

				if (p.secondaryText && p.secondaryText.text) {
					var secondary = document.createElement('span');
					secondary.textContent = ' ' + p.secondaryText.text;
					li.appendChild(secondary);
				}

				// mousedown (not click) so the input keeps focus and blur doesn't close first.
				li.addEventListener('mousedown', function (e) {
					e.preventDefault();
					choose(i);
				});
				li.addEventListener('mousemove', function () {
					if (active !== i) {
						setActive(i);
					}
				});
				list.appendChild(li);
			});

			status.textContent = ((cfg.i18n && cfg.i18n.suggestions) || '%d suggestions').replace('%d', String(items.length));

			position();
			panel.hidden = false;
			open = true;
			active = -1;
			input.setAttribute('aria-expanded', 'true');
			window.addEventListener('resize', position);
			window.addEventListener('scroll', position, true);
		}

		function query(text) {
			var id = ++reqId;

			loadPlaces().then(function (lib) {
				if (!token) {
					token = new lib.AutocompleteSessionToken();
				}
				var request = {
					input: text,
					sessionToken: token,
					includedPrimaryTypes: ['street_address', 'premise', 'subpremise']
				};
				// The country the shopper already picked in the form wins; otherwise fall back
				// to the merchant's list (or the countries the store sells to).
				var countryField = fieldEl(type, mode, 'country');
				var chosen = countryField && countryField.value ? String(countryField.value).toUpperCase() : '';
				if (/^[A-Z]{2}$/.test(chosen)) {
					request.includedRegionCodes = [chosen];
				} else if (cfg.countries && cfg.countries.length) {
					request.includedRegionCodes = cfg.countries;
				}
				if (cfg.language) {
					request.language = cfg.language;
				}
				return lib.AutocompleteSuggestion.fetchAutocompleteSuggestions(request);
			}).then(function (res) {
				if (id !== reqId || disabled) {
					return;
				}
				var items = ((res && res.suggestions) || [])
					.map(function (s) { return s.placePrediction; })
					.filter(Boolean);
				render(items);
			}).catch(function () {
				disableAll();
			});
		}

		function choose(i) {
			var prediction = predictions[i];
			if (!prediction) {
				return;
			}
			var fallbackStreet = (prediction.mainText && prediction.mainText.text) || '';
			close();

			var place = prediction.toPlace();
			place.fetchFields({ fields: ['addressComponents'] }).then(function () {
				token = null; // Selecting a place ends the billing session.

				var parts = parseComponents(place.addressComponents);
				if (!parts.address_1) {
					parts.address_1 = fallbackStreet;
				}
				if (!parts.address_1 && !parts.postcode) {
					return;
				}

				suppressUntil = Date.now() + 800;
				if (mode === 'blocks') {
					fillBlocks(type, parts);
				} else {
					fillClassic(type, parts);
				}
			}).catch(function () {
				token = null;
				disableAll();
			});
		}

		var api = { close: close };
		instances.push(api);

		input.addEventListener('focus', function () {
			if (!disabled) {
				loadPlaces().catch(function () {}); // Warm up the Google script.
			}
		});

		input.addEventListener('input', function () {
			if (disabled || Date.now() < suppressUntil) {
				return;
			}
			clearTimeout(timer);
			var value = input.value.trim();
			if (value.length < MIN_CHARS) {
				close();
				return;
			}
			timer = setTimeout(function () {
				query(value);
			}, DEBOUNCE_MS);
		});

		input.addEventListener('keydown', function (e) {
			if (!open) {
				return;
			}
			var n = predictions.length;
			if (e.key === 'ArrowDown') {
				e.preventDefault();
				setActive(active + 1 >= n ? 0 : active + 1);
			} else if (e.key === 'ArrowUp') {
				e.preventDefault();
				setActive(active - 1 < 0 ? n - 1 : active - 1);
			} else if (e.key === 'Enter' && active >= 0) {
				e.preventDefault();
				choose(active);
			} else if (e.key === 'Escape') {
				e.preventDefault();
				close();
			} else if (e.key === 'Tab') {
				close();
			}
		});

		input.addEventListener('blur', function () {
			setTimeout(close, 150);
		});
	}

	// ── Find the address fields (Blocks render them late and re-render them) ─────

	var SELECTOR = '#shipping-address_1, #billing-address_1, #shipping_address_1, #billing_address_1';

	function scan() {
		if (disabled) {
			return;
		}
		var found = document.querySelectorAll(SELECTOR);
		for (var i = 0; i < found.length; i++) {
			attach(found[i]);
		}
	}

	var scanTimer = null;
	function scheduleScan() {
		clearTimeout(scanTimer);
		scanTimer = setTimeout(scan, 150);
	}

	function start() {
		scan();
		if (typeof MutationObserver === 'function') {
			new MutationObserver(scheduleScan).observe(document.body, { childList: true, subtree: true });
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
