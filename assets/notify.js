(function () {
	const cfg = window.aceNudge;
	if (!cfg) return;
	// wp_localize_script hands every top-level value over as a string, so "18" + 1 would be "181" and
	// the age rule would turn everyone away. The minimum age is a number from here on.
	cfg.minAge = parseInt(cfg.minAge, 10) || 0;
	const qs = new URLSearchParams(location.search);
	const store = {
		get(k) { try { return localStorage.getItem('aceNudge.' + k); } catch (e) { return null; } },
		set(k, v) { try { localStorage.setItem('aceNudge.' + k, v); } catch (e) {} },
		del(k) { try { localStorage.removeItem('aceNudge.' + k); } catch (e) {} },
	};
	// The prototype's keys, so a browser that already subscribed stays subscribed.
	['token', 'age', 'preview'].forEach((k) => {
		try {
			const old = localStorage.getItem('aceNotify.' + k);
			if (old !== null) { store.set(k, old); localStorage.removeItem('aceNotify.' + k); }
		} catch (e) {}
	});
	if (qs.has('ace_nudge_preview')) store.set('preview', '1');
	// Other plugins (the settings panel) ask cfg.api what this browser follows. Where NUDGE stays
	// quiet, it still answers, so they can say why rather than show an empty list.
	// A site with a settings panel (Ace Accessibility's "Your settings") manages alerts in its Alerts
	// tab; without one, NUDGE's own dialog does it.
	const settingsPanel = () => (window.aceSettings && document.getElementById('ace-accessibility-panel') ? window.aceSettings : null);
	const bellSvg = '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path fill="currentColor" d="M12 22a2.5 2.5 0 0 0 2.45-2h-4.9A2.5 2.5 0 0 0 12 22zm7-6v-5a7 7 0 0 0-5.5-6.84V3.5a1.5 1.5 0 0 0-3 0v.66A7 7 0 0 0 5 11v5l-2 2v1h18v-1z"/></svg>';
	const unavailable = (reason) => {
		cfg.api = { available: false, reason, status: () => ({ available: false, reason }), follows: () => [], categories: () => [], info: () => ({ name: cfg.name }), on: () => () => {}, open: () => {},
			notifications: () => ({ items: [], unread: 0, loaded: true }), unread: () => 0, markRead: () => {}, markAllRead: () => {}, dismiss: () => {} };
		document.dispatchEvent(new CustomEvent('ace-nudge:change', { detail: { status: cfg.api.status(), follows: [] } }));
		// The bell still leads to the Alerts tab, which says why alerts are not on offer here.
		const placeBell = () => {
			const panel = settingsPanel();
			const search = cfg.bell && document.querySelector(cfg.bell);
			const slot = document.querySelector('.ace-nudge-bell--slot');
			if (!panel || !search) {
				if (slot) slot.remove();
				return;
			}
			if (document.querySelector('.ace-nudge-bell:not(.ace-nudge-bell--slot)')) return;
			const bell = document.createElement('button');
			bell.type = 'button';
			bell.className = 'ace-nudge-bell';
			bell.setAttribute('aria-label', 'Alerts and newsletter');
			bell.title = 'Alerts and newsletter';
			bell.innerHTML = bellSvg;
			bell.addEventListener('click', () => panel.open('alerts', bell));
			if (slot) slot.replaceWith(bell); else search.before(bell);
		};
		document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', placeBell) : placeBell();
	};
	if (cfg.mode === 'test' && !store.get('preview')) return unavailable('test');
	// Someone who gave an under-18 year is never asked again, and can't retry with another year.
	if (store.get('age') === 'no') return unavailable('age');

	const pushable = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
	const calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	let follows = [];
	let items = [];

	const post = (route, body) => fetch(cfg.rest + route, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify(body),
	}).then((r) => r.json().then((j) => (r.ok ? j : Promise.reject(j))));

	const el = (tag, attrs, ...kids) => {
		const n = document.createElement(tag);
		Object.entries(attrs || {}).forEach(([k, v]) => {
			if (k === 'on') Object.entries(v).forEach(([e, f]) => n.addEventListener(e, f));
			else if (k === 'class') n.className = v;
			else n.setAttribute(k, v);
		});
		kids.forEach((c) => n.append(c));
		return n;
	};
	const between = (min, max) => min + Math.random() * (max - min);

	const dialog = el('dialog', { class: 'ace-nudge-dialog', 'aria-labelledby': 'ace-nudge-title' });

	// Arrives like the cookie banner: from below at an angle, overshoots, then settles.
	const arrive = () => {
		if (calm || !dialog.animate) return;
		const tilt = between(1, 3) * (Math.random() < .5 ? -1 : 1);
		const rise = between(14, 26);
		dialog.animate([
			{ transform: `translate(${tilt * 3}px, 110vh) rotate(${tilt}deg)`, easing: 'cubic-bezier(.2,.7,.3,1)' },
			{ transform: `translateY(${-rise}px) rotate(${tilt * -.5}deg)`, offset: .55, easing: 'ease-in-out' },
			{ transform: `translateY(${rise * .25}px) rotate(${tilt * .2}deg)`, offset: .78, easing: 'ease-in-out' },
			{ transform: 'none' },
		], { duration: 750 });
	};
	let bounce = null;
	dialog.addEventListener('click', (e) => {
		if (e.target !== dialog || calm || !dialog.animate) return;
		const r = dialog.getBoundingClientRect();
		// A tap inside the box can land on the dialog itself, so only the page around it counts.
		if (e.clientX >= r.left && e.clientX <= r.right && e.clientY >= r.top && e.clientY <= r.bottom) return;
		const tilt = between(.6, 1.6) * (Math.random() < .5 ? -1 : 1);
		const up = between(10, 22);
		if (bounce) bounce.cancel();
		bounce = dialog.animate([
			{ transform: 'none' },
			{ transform: `translateY(${-up}px) rotate(${tilt}deg)` },
			{ transform: `translateY(${up * .3}px) rotate(${tilt * -.5}deg)` },
			{ transform: 'none' },
		], { duration: 450, easing: 'ease-in-out' });
	});
	const dismiss = () => {
		if (calm || !dialog.animate) return dialog.close();
		const tilt = between(2, 5) * (Math.random() < .5 ? -1 : 1);
		dialog.animate([{ transform: 'none', opacity: 1 }, { transform: `translateY(100vh) rotate(${tilt}deg)`, opacity: 1 }], { duration: 300, easing: 'cubic-bezier(.5,0,.9,.5)' })
			.finished.then(() => dialog.close(), () => dialog.close());
	};
	const show = (...kids) => {
		dialog.replaceChildren(...kids);
		if (!dialog.open) { dialog.showModal(); arrive(); }
		dialog.scrollTop = 0;
	};

	const closeBtn = (label) => el('button', { type: 'button', class: 'ace-nudge-btn ace-nudge-btn--ghost', on: { click: dismiss } }, label || 'Not now');
	const primary = (label, on) => el('button', on ? { type: 'button', class: 'ace-nudge-btn', on: { click: on } } : { type: 'submit', class: 'ace-nudge-btn' }, label);
	const secondary = (label, on) => el('button', { type: 'button', class: 'ace-nudge-btn ace-nudge-btn--outline', on: { click: on } }, label);
	const actions = (...kids) => el('div', { class: 'ace-nudge-actions' }, ...kids);
	const heading = (t) => el('h2', { id: 'ace-nudge-title' }, t);
	const lead = (t) => el('p', { class: 'ace-nudge-lead' }, t);
	const privacy = () => (cfg.privacy ? el('p', { class: 'ace-nudge-small' }, el('a', { href: cfg.privacy }, 'How we use your data')) : '');
	const errorBox = () => el('p', { class: 'ace-nudge-error', role: 'alert', hidden: '' });
	const fail = (box, err) => {
		box.textContent = (err && err.message) || 'Something went wrong. Please try again.';
		box.hidden = false;
	};

	// The label sits inside the input and floats up once there's something in it.
	const field = (id, label, attrs) => {
		const input = el('input', { id, placeholder: ' ', ...attrs });
		return { input, node: el('div', { class: 'ace-nudge-field' }, input, el('label', { for: id }, label)) };
	};
	const toggle = (title, detail, checked) => {
		const input = el('input', { type: 'checkbox', role: 'switch' });
		input.checked = !!checked;
		const node = el('label', { class: 'ace-nudge-toggle' },
			el('span', { class: 'ace-nudge-toggle__text' }, el('span', { class: 'ace-nudge-toggle__title' }, title), detail ? el('span', { class: 'ace-nudge-toggle__detail' }, detail) : ''),
			input,
			el('span', { class: 'ace-nudge-toggle__track', 'aria-hidden': 'true' }));
		return { input, node };
	};

	// Asking for a year doesn't reveal the qualifying answer, unlike "Are you 18?". Someone born
	// in the year that turns 18 may not have reached it yet, so that year doesn't pass.
	const ageStep = (next) => {
		if (!cfg.minAge || store.get('age') === 'yes') return next();
		const year = field('ace-nudge-year', 'Year of birth', { type: 'number', inputmode: 'numeric', min: '1900', max: String(new Date().getFullYear()), required: '' });
		const error = errorBox();
		show(el('form', { on: { submit: (e) => {
			e.preventDefault();
			const y = parseInt(year.input.value, 10), now = new Date().getFullYear();
			if (!y || y < 1900 || y > now) return fail(error, { message: 'Please enter a four-digit year.' });
			if (y > now - (cfg.minAge + 1)) {
				store.set('age', 'no');
				document.querySelectorAll('.ace-nudge-bar, .ace-nudge-bell, .ace-nudge-footer, [data-ace-nudge]').forEach((b) => b.remove());
				return show(heading('Updates aren\'t available'), lead('This service isn\'t available to you.'), actions(closeBtn('Close')));
			}
			store.set('age', 'yes');
			next();
		} } },
			heading('First, a quick check'),
			lead('What year were you born? We don\'t store your answer, only that this check was passed.'),
			year.node, error,
			actions(primary('Continue'), closeBtn())
		));
		year.input.focus();
	};

	const subscribePush = async (purpose) => {
		const hadToken = !!store.get('token');
		const permission = await Notification.requestPermission();
		if (permission !== 'granted') throw { message: 'Notifications are blocked in your browser settings.' };
		const reg = await navigator.serviceWorker.register(cfg.sw, { scope: '/' });
		await navigator.serviceWorker.ready;
		const key = Uint8Array.from(atob(cfg.vapid.replace(/-/g, '+').replace(/_/g, '/')), (c) => c.charCodeAt(0));
		const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
		const json = sub.toJSON();
		const res = await post('subscribe', { channel: 'push', consent: true, age_confirmed: store.get('age') === 'yes' || !cfg.minAge, purpose, endpoint: json.endpoint, p256dh: json.keys.p256dh, auth: json.keys.auth });
		store.set('token', res.token);
		// Order updates aren't consent to anything else, so remember what this one covers.
		if (purpose === 'orders') { if (!store.get('scope')) store.set('scope', hadToken ? 'all' : 'orders'); } else store.set('scope', 'all');
	};

	const apply = (s) => { follows = s.follows; items = s.items || []; paint(); followsChanged(); };
	const follow = (t) => post('follow', { ...t, token: store.get('token') }).then(apply);
	const notifyOn = () => pushable && !!store.get('token') && Notification.permission === 'granted';
	const marketingOn = () => notifyOn() && store.get('scope') !== 'orders';

	// Nobody logs in here, so follows belong to this browser's subscription. Following therefore
	// starts by turning notifications on. The toggle starts off and covers this channel only.
	// Why this browser cannot take notifications, or null when it can.
	const pushBlocker = () => {
		const ios = /iP(hone|ad|od)/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
		if (ios && !navigator.standalone) return 'ios';
		if (!pushable) return 'unsupported';
		if (Notification.permission === 'denied') return 'denied';
		return null;
	};
	const cantPush = () => {
		const ios = /iP(hone|ad|od)/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
		if (ios && !navigator.standalone) {
			show(heading('Add us to your Home Screen'), lead('On iPhone and iPad, notifications only work once the site is on your Home Screen. Tap Share, choose Add to Home Screen, then open it from there and follow again.'), actions(closeBtn('Close')));
			return true;
		}
		if (!pushable) {
			show(heading('Notifications aren\'t available'), lead('This browser doesn\'t support notifications from websites. '), actions(closeBtn('Close')));
			return true;
		}
		if (Notification.permission === 'denied') {
			show(heading('Notifications are blocked'), lead('You\'ve blocked notifications for this site. To follow anything, allow them in your browser\'s site settings, then try again.'), actions(closeBtn('Close')));
			return true;
		}
		return false;
	};

	const pushStep = (target) => {
		if (cantPush()) return;
		const consent = toggle('Turn on browser notifications', cfg.wording.push);
		const error = errorBox();
		show(el('form', { on: { submit: async (e) => {
			e.preventDefault();
			error.hidden = true;
			if (!consent.input.checked) return fail(error, { message: 'Please switch this on to turn notifications on.' });
			try {
				await subscribePush();
				if (target) await follow(target);
				interestsStep(target);
			} catch (err) { fail(error, err); }
		} } },
			heading(target ? 'Follow ' + target.label : 'Turn on notifications'),
			lead('What you follow is saved on this browser, with no account needed. Your browser will ask you to allow notifications next.'),
			consent.node, privacy(), error,
			actions(primary('Continue'), closeBtn())
		));
	};

	const categoryOn = (c) => c.ids.some((id) => follows.includes('category:' + id));
	const categoryToggles = (isOn) => {
		const rows = cfg.categories.map((c) => ({ c, ...toggle(c.label, '', isOn(c)) }));
		return { rows, node: el('div', { class: 'ace-nudge-cats' }, ...rows.map((r) => r.node)) };
	};

	const setFollow = async (type, id, want, key) => {
		if (want === follows.includes(key)) return;
		apply(await post(want ? 'follow' : 'unfollow', { type, id, token: store.get('token') }));
	};

	const interestsStep = (target) => {
		const all = toggle('Everything on ' + cfg.name, 'All new items, grouped together.', follows.includes('site:all'));
		const reads = toggle('Updates to what I\'ve read', cfg.wording.reads, follows.includes('site:reads'));
		const cats = categoryToggles(categoryOn);
		const sync = () => cats.node.classList.toggle('is-covered', all.input.checked);
		all.input.addEventListener('change', sync);
		sync();
		const error = errorBox();
		show(el('form', { on: { submit: async (e) => {
			e.preventDefault();
			try {
				await setFollow('site', 'all', all.input.checked, 'site:all');
				await setFollow('site', 'reads', reads.input.checked, 'site:reads');
				for (const { c, input } of cats.rows) {
					for (const id of c.ids) await setFollow('category', id, input.checked, 'category:' + id);
				}
				paint();
				show(heading('You\'re all set'), lead('We group things together so you don\'t get swamped. '+(cfg.newsletter ? 'Want a round-up by email too?' : '')),
					actions(cfg.newsletter ? primary('Get the newsletter', () => newsletterStep()) : '', closeBtn('Done')));
			} catch (err) { fail(error, err); }
		} } },
			heading(target ? 'You\'re following ' + target.label : 'What do you want alerts about?'),
			target ? lead('Anything else you\'d like alerts about?') : '',
			all.node, reads.node,
			el('h3', { class: 'ace-nudge-subhead' }, 'Or choose categories'),
			cats.node, error,
			actions(primary('Save'), closeBtn('Skip'))
		));
	};

	// A separate sign-up with its own consent: a general summary, shaped by the top categories.
	// Categories already followed for alerts start switched on, so nobody picks them twice.
	const newsletterStep = () => {
		const consent = toggle('Send me the newsletter', cfg.wording.email);
		const email = field('ace-nudge-email', 'Email address', { type: 'email', autocomplete: 'email', required: '' });
		const trap = el('input', { type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off', class: 'ace-nudge-trap', 'aria-hidden': 'true' });
		const radio = (value, label, checked) => {
			const input = el('input', { type: 'radio', name: 'ace-nudge-frequency', value });
			input.checked = checked;
			return el('label', {}, input, el('span', {}, label));
		};
		const frequency = el('div', { class: 'ace-nudge-segments', role: 'radiogroup', 'aria-label': 'How often' }, radio('daily', 'Daily', true), radio('weekly', 'Weekly', false));
		const cats = categoryToggles(categoryOn);
		const error = errorBox();
		show(el('form', { on: { submit: async (e) => {
			e.preventDefault();
			error.hidden = true;
			if (!consent.input.checked) return fail(error, { message: 'Please switch this on to sign up.' });
			try {
				const categories = cats.rows.filter((r) => r.input.checked).flatMap((r) => r.c.ids);
				await post('subscribe', { channel: 'email', consent: true, age_confirmed: true, email: email.input.value, website: trap.value, frequency: frequency.querySelector('input:checked').value, categories });
				show(heading('Check your inbox'), lead('Confirm using the link we\'ve sent and your newsletter will start.'), actions(closeBtn('Close')));
			} catch (err) { fail(error, err); }
		} } },
			heading('The ' + cfg.name + ' newsletter'),
			lead('A round-up of the best new things. Choose what interests you, or leave these off for a bit of everything.'),
			cats.node, frequency, email.node, trap, consent.node, privacy(), error,
			actions(primary('Sign up'), closeBtn())
		));
	};

	const withdraw = async () => {
		const token = store.get('token');
		if (token) await post('withdraw', { token });
		const reg = navigator.serviceWorker && await navigator.serviceWorker.getRegistration();
		const sub = reg && await reg.pushManager.getSubscription();
		if (sub) await sub.unsubscribe();
		store.del('token');
		store.del('scope');
		follows = [];
		items = [];
		paint();
	};

	// Everything this browser follows, one switch each. Email is managed from the link in each email.
	const openManage = (focus, from) => {
		const panel = settingsPanel();
		if (panel) return panel.open('alerts', from);
		return manage(focus);
	};
	const manage = (focus) => {
		const token = store.get('token');
		const rows = items.map((item) => {
			const row = toggle(item.label, '', true);
			row.input.addEventListener('change', async () => {
				row.input.disabled = true;
				try {
					const body = row.input.checked ? { type: item.type, id: item.id, post_id: item.post_id, token } : { key: item.key, token };
					const s = await post(row.input.checked ? 'follow' : 'unfollow', body);
					follows = s.follows;
					paint();
				} catch (err) { row.input.checked = !row.input.checked; }
				row.input.disabled = false;
			});
			if (focus && focus.key === item.key) row.node.classList.add('is-focus');
			return row.node;
		});
		show(heading(token ? 'What you\'re following' : 'Alerts and newsletter'),
			lead(token ? 'Saved on this browser. Switch anything off here.' : 'Choose how you\'d like to hear from us.'),
			rows.length ? el('div', { class: 'ace-nudge-following' }, ...rows) : '',
			el('div', { class: 'ace-nudge-actions ace-nudge-stack' },
				marketingOn() ? primary('Categories and whole site', () => interestsStep(null)) : primary('Turn on notifications', () => ageStep(() => pushStep(null))),
				cfg.newsletter ? secondary('Get the newsletter', () => ageStep(newsletterStep)) : '',
				token ? secondary('Turn off and delete my subscription', async () => {
					await withdraw();
					show(heading('Turned off'), lead('We won\'t send you any more notifications, and your subscription has been deleted.'), actions(closeBtn('Close')));
				}) : '',
				closeBtn('Close')));
	};

	const buttons = [];
	const listeners = [];
	const bells = [];
	const paint = () => {
		buttons.forEach(([b, t]) => {
			const on = follows.includes(t.key);
			(b.querySelector('.ace-nudge-follow__label') || b).textContent = (on ? 'Following ' : 'Follow ') + t.label;
			b.setAttribute('aria-pressed', String(on));
		});
		listeners.forEach((f) => { try { f(follows.slice()); } catch (e) {} });
		// The bell shows how many notifications are unread (what this browser follows that is new or
		// has changed since it was read). The last count is kept, so the badge doesn't flash on load.
		const count = described().length;
		bells.forEach((bell) => {
			const badge = bell.querySelector('.ace-nudge-bell__count');
			if (badge) { badge.textContent = unread > 99 ? '99+' : String(unread); badge.hidden = !unread; }
			const label = 'Alerts' + (unread ? ', ' + unread + ' new' : '') + (count ? ', following ' + count : '');
			bell.setAttribute('aria-label', label);
			bell.title = label;
		});
		document.dispatchEvent(new CustomEvent('ace-nudge:change', { detail: { status: status(), follows: described() } }));
	};

	// What this browser follows, named. The server names games, stories and most categories; the
	// whole-site switches and the top categories are named here. A top category can be several
	// terms with one name, so it is one entry carrying all of its keys.
	const described = () => {
		const list = items.map((i) => ({ key: i.key, keys: [i.key], type: i.type, label: i.label }));
		const seen = new Set(list.map((i) => i.key));
		if (follows.includes('site:all')) list.unshift({ key: 'site:all', keys: ['site:all'], type: 'site', label: 'Everything on ' + cfg.name });
		if (follows.includes('site:reads')) list.push({ key: 'site:reads', keys: ['site:reads'], type: 'site', label: 'Updates to what I\'ve read' });
		['site:all', 'site:reads'].forEach((k) => seen.add(k));
		// Alerts other plugins offer (a predictor's round updates), with the names they gave them.
		Object.entries(cfg.siteLabels || {}).forEach(([id, label]) => {
			const k = 'site:' + id;
			if (follows.includes(k)) { list.push({ key: k, keys: [k], type: 'site', label }); seen.add(k); }
		});
		(cfg.categories || []).forEach((c) => {
			const keys = c.ids.map((id) => 'category:' + id).filter((k) => follows.includes(k) && !seen.has(k));
			if (!keys.length) return;
			keys.forEach((k) => seen.add(k));
			list.push({ key: keys[0], keys, type: 'category', label: c.label });
		});
		follows.filter((k) => !seen.has(k) && !/^(read|slip):/.test(k)).forEach((k) => list.push({ key: k, keys: [k], type: k.split(':')[0], label: 'Something else you follow' }));
		return list;
	};
	let ready = false;
	const status = () => ({
		available: true,
		ready,
		push: pushable ? Notification.permission : 'unsupported',
		subscribed: !!store.get('token'),
		notifications: notifyOn(),
		scope: store.get('scope') || '',
		newsletter: !!cfg.newsletter,
		count: described().length,
	});

	// A follow button that's already following opens the panel rather than quietly unfollowing.
	const clicked = (t) => {
		if (!marketingOn()) return ageStep(() => pushStep(t));
		return follows.includes(t.key) ? openManage(t) : follow(t);
	};

	// A team colour can be too pale to read on the button's white (sky blue, yellow). The border keeps
	// the colour; the label gets it darkened just enough to reach 4.5:1 against white.
	const readableOnWhite = (hex) => {
		const m = /^#?([0-9a-f]{6})$/i.exec(String(hex || '').trim());
		if (!m) return '';
		let rgb = [0, 2, 4].map((i) => parseInt(m[1].slice(i, i + 2), 16));
		const lum = (c) => {
			const [r, g, b] = c.map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; });
			return 0.2126 * r + 0.7152 * g + 0.0722 * b;
		};
		let steps = 0;
		while (1.05 / (lum(rgb) + 0.05) < 4.5 && steps++ < 20) rgb = rgb.map((v) => Math.round(v * 0.9));
		return steps ? '#' + rgb.map((v) => v.toString(16).padStart(2, '0')).join('') : '';
	};

	const followButton = (t) => {
		const b = el('button', { type: 'button', class: 'ace-nudge-follow', on: { click: () => clicked(t) } });
		if (t.side) b.dataset.side = t.side;
		// A target can bring its own colours (a team's shirt) and an icon (its kit).
		if (t.colour) {
			b.style.setProperty('--ace-nudge-follow', t.colour);
			const text = readableOnWhite(t.colour);
			if (text) b.style.setProperty('--ace-nudge-follow-text', text);
		}
		if (t.contrast) b.style.setProperty('--ace-nudge-follow-contrast', t.contrast);
		if (t.icon) b.append(el('img', { class: 'ace-nudge-follow__icon', src: t.icon, alt: '', width: 22, height: 22 }));
		b.append(el('span', { class: 'ace-nudge-follow__label' }, ''));
		buttons.push([b, t]);
		return b;
	};

	// Other scripts on the page (the bet calculator's slip) can follow through the same flow:
	// age check, notification permission, then the follow. `days` on a target makes it lapse.
	// unfollow takes a target or an entry from follows() (every key it carries), or a type and an id.
	const unfollowKeys = async (keys) => {
		let s = null;
		for (const key of keys) s = await post('unfollow', { key, token: store.get('token') });
		if (s) apply(s);
		return s;
	};
	/* ---------------------------------------------------------------- notifications */

	// What this browser has seen, read and been shown, kept here only: nothing about a reader is sent
	// anywhere. seen maps a post to when it was viewed and the version (modified time) viewed; the feed
	// is public and the same for everyone, and this script works out what is unread.
	const DAY = 86400;
	const nowS = () => Math.floor(Date.now() / 1000);
	const loadMap = (k) => { try { return JSON.parse(store.get(k) || '{}') || {}; } catch (e) { return {}; } };
	const stamp = (v) => (Array.isArray(v) ? v[0] : v) || 0;
	const saveMap = (k, m, cap) => {
		const cut = nowS() - 60 * DAY;
		const keep = Object.entries(m).filter(([, v]) => stamp(v) >= cut).sort((a, b) => stamp(b[1]) - stamp(a[1])).slice(0, cap || 400);
		Object.keys(m).forEach((id) => { if (!keep.some(([k2]) => k2 === id)) delete m[id]; });
		store.set(k, JSON.stringify(Object.fromEntries(keep)));
	};
	const seen = loadMap('seen');
	const readMap = loadMap('read');
	const shown = loadMap('shown');
	const gone = loadMap('gone');
	if (cfg.post_id) {
		seen[cfg.post_id] = [nowS(), parseInt(cfg.post_modified, 10) || nowS()];
		saveMap('seen', seen);
	}
	// Opened from a system notification (ref=nudge-ID): that item is read, and the reference comes off
	// the address so it is never shared or bookmarked.
	const opened = /^nudge-(\d+)$/.exec(qs.get('ref') || '');
	if (opened) {
		readMap[opened[1]] = nowS();
		saveMap('read', readMap);
		qs.delete('ref');
		try { history.replaceState(history.state, '', location.pathname + (qs.toString() ? '?' + qs : '') + location.hash); } catch (e) {}
	}
	let feed = null;
	let feedDays = 5;
	let notes = [];
	let unread = parseInt(store.get('unread') || '0', 10) || 0;
	const REASONS = { new: 'New', updated: 'Updated', tips: 'Tips updated', prices: 'Prices changed', result: 'Result in' };

	// Sports a reader reads, learnt on this device only and only while "Recommend bets from sports I
	// read" is on (it is unless they switch it off, which also forgets what was learnt). Each term the
	// reader has read is kept with when and how often, for 60 days.
	const recoOn = () => store.get('reco') !== 'off';
	const interest = loadMap('interest');
	if (recoOn() && cfg.post_id && Array.isArray(cfg.post_terms)) {
		cfg.post_terms.forEach((t) => { const was = interest[t]; interest[t] = [nowS(), (Array.isArray(was) ? was[1] : 0) + 1]; });
		saveMap('interest', interest, 60);
	}
	const setReco = (on) => {
		store.set('reco', on ? 'on' : 'off');
		if (!on) { Object.keys(interest).forEach((k) => delete interest[k]); store.del('interest'); }
		followsChanged();
	};

	// The reader's selection list (slip), where the site has one: its picks are read from this browser, their state
	// comes from the site's cached endpoint (the same answer for anyone asking about the same picks),
	// and nothing about the slip is stored anywhere else. The price first seen for each pick is kept
	// here, so a move is measured from what the reader saw.
	const slipCfg = cfg.slip && cfg.slip.state ? cfg.slip : null;
	const slipPicks = () => {
		if (!slipCfg) return [];
		try {
			if (window.aceSlip && typeof window.aceSlip.picks === 'function') return window.aceSlip.picks();
			const saved = JSON.parse(localStorage.getItem(slipCfg.storage || '') || 'null');
			return saved && Array.isArray(saved.picks) ? saved.picks : [];
		} catch (e) { return []; }
	};
	const pickKey = (p) => p.market_id + ':' + p.selection_id;
	const slipBase = loadMap('slipbase');
	let slipState = null;
	let slipAsked = '';
	const oddsFormat = () => { try { return localStorage.getItem('aceOddsFormat') || 'fractional'; } catch (e) { return 'fractional'; } };
	const FRACS = [[1, 10], [1, 8], [1, 6], [1, 5], [2, 9], [1, 4], [2, 7], [3, 10], [1, 3], [4, 11], [2, 5], [4, 9], [1, 2], [8, 15], [4, 7], [8, 13], [4, 6], [8, 11], [4, 5], [5, 6], [10, 11], [1, 1], [11, 10], [6, 5], [5, 4], [11, 8], [6, 4], [13, 8], [7, 4], [15, 8], [2, 1], [9, 4], [5, 2], [11, 4], [3, 1], [10, 3], [7, 2], [4, 1], [9, 2], [5, 1], [11, 2], [6, 1], [13, 2], [7, 1], [15, 2], [8, 1], [9, 1], [10, 1], [11, 1], [12, 1], [14, 1], [16, 1], [18, 1], [20, 1], [25, 1], [33, 1], [40, 1], [50, 1], [66, 1], [100, 1]];
	const price = (dec, frac) => {
		const f = oddsFormat();
		if (!(dec > 1)) return frac || '';
		if (f === 'decimal') return dec.toFixed(2);
		if (f === 'probability') return Math.round(100 / dec) + '%';
		if (frac) return frac === '1/1' ? 'Evens' : frac;
		const best = FRACS.reduce((a, b) => (Math.abs(b[0] / b[1] + 1 - dec) < Math.abs(a[0] / a[1] + 1 - dec) ? b : a));
		return best[0] === best[1] ? 'Evens' : best[0] + '/' + best[1];
	};
	const loadSlip = () => {
		const picks = slipPicks().filter((p) => p.market_id && p.selection_id && !p.example);
		const ask = picks.map(pickKey).sort().join(',');
		if (!slipCfg || !picks.length || ask === slipAsked) return;
		slipAsked = ask;
		fetch(slipCfg.state + (slipCfg.state.indexOf('?') === -1 ? '?' : '&') + 's=' + encodeURIComponent(ask), { credentials: 'omit' })
			.then((r) => (r.ok ? r.json() : Promise.reject(r)))
			.then((j) => { slipState = j && j.picks ? j.picks : {}; compute(); bubble(); })
			.catch(() => { slipAsked = ''; });
	};
	// What has changed on the slip: a result, a market closing or suspended, the event under way, a
	// real price move, and the article a pick came from being updated since it was added.
	const slipNotes = () => {
		const out = [];
		const picks = slipPicks().filter((p) => p.market_id && p.selection_id && !p.example);
		let based = false;
		picks.forEach((p) => {
			const key = pickKey(p);
			const name = p.runner || 'Your pick';
			const st = slipState && slipState[key];
			const base = Array.isArray(slipBase[key]) ? slipBase[key] : null;
			if (st && st.dec > 1 && !base) { slipBase[key] = [nowS(), p.dec > 1 ? p.dec : st.dec]; based = true; }
			const was = base ? base[1] : (p.dec > 1 ? p.dec : 0);
			const add = (kind, label, text, value) => out.push({
				id: 'slip:' + key + ':' + kind + ':' + value, group: 'slip', kind, label, title: text, sub: p.event || '',
				url: (p.source && p.source.url) || '', action: 'slip', at: nowS(), thumb: '',
			});
			if (st && st.state === 'settled') {
				const words = { won: [' won', 'Won'], placed: [' placed', 'Placed'], lost: [' lost', 'Lost'], removed: [' is a non-runner', 'Non-runner'] }[st.result] || [' settled', 'Result'];
				add('result', words[1], name + words[0], st.result || 'settled');
			} else if (st && (st.state === 'closed' || st.state === 'suspended')) {
				add('closed', st.state === 'closed' ? 'Market closed' : 'Suspended', name + ': the market is ' + st.state + '. Price may have moved', st.state);
			} else if (st && st.state === 'open') {
				if (st.inplay) add('started', 'Under way', name + ': ' + (p.event || 'the event') + ' has started', 'inplay');
				if (was > 1 && st.dec > 1 && Math.abs(st.dec - was) / was >= 0.01) {
					const up = st.dec > was;
					add('price', up ? 'Drifted' : 'Shortened', name + (up ? ' drifted' : ' shortened') + ' from ' + price(was) + ' to ' + price(st.dec, st.frac), String(st.dec));
				}
			}
			const src = p.source && p.source.post_id && feed ? feed.items.find((it) => it.id === p.source.post_id) : null;
			const addedS = Math.floor((p.added || 0) / 1000);
			if (src && src.updated && src.updated > addedS) {
				out.push({ id: 'slipart:' + src.id + ':' + src.updated, group: 'slip', kind: 'updated', label: 'Article updated', title: src.title,
					sub: 'Where you picked ' + name, url: src.url, thumb: src.thumb, at: src.updated, post: src.id });
			}
		});
		if (based) saveMap('slipbase', slipBase, 60);
		return out;
	};
	// Recommended: tips from recent articles in sports the reader follows, or reads and puts on their
	// slip (while that is switched on), that they haven't opened yet. A few at most, newest first.
	const recoNotes = (taken) => {
		if (!feed) return [];
		const terms = new Set(follows.filter((k) => k.indexOf('category:') === 0).map((k) => parseInt(k.slice(9), 10)));
		const sports = new Set();
		if (recoOn()) {
			Object.keys(interest).forEach((t) => terms.add(parseInt(t, 10)));
			slipPicks().forEach((p) => { if (p.sport) sports.add(String(p.sport).toLowerCase()); });
		}
		if (!terms.size && !sports.size && !follows.includes('site:all')) return [];
		return feed.items.filter((it) => Array.isArray(it.tips) && it.tips.length && !seen[it.id] && !taken.has(it.id)
			&& it.published > nowS() - feedDays * DAY
			&& (follows.includes('site:all') || it.terms.some((t) => terms.has(t)) || it.tips.some((tp) => sports.has(String(tp.sport || '').toLowerCase()))))
			.slice(0, 4)
			.map((it) => {
				const tp = it.tips[0];
				const more = it.tips.length > 1 ? ' and ' + (it.tips.length - 1) + ' more' : '';
				return { id: 'tip:' + it.id, post: it.id, group: 'recommended', kind: 'tip', label: 'Tip', url: it.url, thumb: it.thumb, at: it.published,
					title: tp.runner + (tp.price ? ' at ' + price(tp.dec, tp.price) : '') + more, sub: it.title };
			});
	};

	// Whether what this browser follows covers an item, and as what: a result in, a change since it was
	// read, or something new. Edits found only by their modified time count just as a change since the
	// reader saw the post, never as news.
	const kindOf = (it) => {
		const s = seen[it.id];
		const viewedAt = s ? s[0] : 0;
		const version = s ? s[1] : 0;
		const has = (k) => follows.includes(k);
		const game = !!it.fixture && has('fixture:' + it.fixture);
		const story = has('post:' + it.id) || game;
		const broad = has('site:all') || has('ptype:' + it.type) || it.terms.some((t) => has('category:' + t)) || game;
		const read = !!s && has('site:reads');
		if (it.result && it.result > viewedAt && (story || read || (broad && s))) return { kind: 'result', at: it.result, label: REASONS.result };
		if (it.updated && s && it.updated > version + 60 && (story || read || broad)) return { kind: 'updated', at: it.updated, label: REASONS[it.reason] || REASONS.updated };
		if (it.updated && !it.soft && !s && story) return { kind: 'updated', at: it.updated, label: REASONS[it.reason] || REASONS.updated };
		if (!s && broad && it.published > nowS() - feedDays * DAY) return { kind: 'new', at: it.published, label: REASONS.new };
		return null;
	};
	const announce = () => document.dispatchEvent(new CustomEvent('ace-nudge:notifications', { detail: { items: notes.slice(), unread } }));
	const compute = () => {
		const updates = feed ? feed.items.map((it) => {
			const k = kindOf(it);
			return k && !gone[it.id] ? Object.assign({}, it, k, { group: 'updates', unread: (readMap[it.id] || 0) < k.at }) : null;
		}).filter(Boolean) : [];
		const taken = new Set(updates.map((n) => n.id));
		const extra = slipNotes().concat(recoNotes(taken)).filter((n) => !gone[n.id]).map((n) => Object.assign(n, { unread: !readMap[n.id] && !(n.post && (readMap[n.post] || 0) >= n.at) }));
		notes = updates.concat(extra).sort((a, b) => (b.unread - a.unread) || (b.at - a.at)).slice(0, 50);
		unread = notes.filter((n) => n.unread).length;
		store.set('unread', String(unread));
		paint();
		announce();
	};
	let feedAsked = false;
	const loadFeed = () => {
		if (feedAsked || !cfg.feed) return;
		feedAsked = true;
		fetch(cfg.feed + (cfg.feed.indexOf('?') === -1 ? '?' : '&') + 'm=' + Math.floor(Date.now() / 60000), { credentials: 'omit' }).then((r) => (r.ok ? r.json() : Promise.reject(r))).then((j) => {
			feed = j;
			feedDays = parseInt(j.days, 10) || 5;
			compute();
			bubble();
		}).catch(() => { feedAsked = false; });
	};
	const idle = (f) => ('requestIdleCallback' in window ? requestIdleCallback(f, { timeout: 3000 }) : setTimeout(f, 1200));
	// While the tab is the one in view, look again once a minute, and straight away on coming back to it
	// after a minute away, so anything new arrives in place with its bubble, count and sound, without a
	// refresh. Background tabs do nothing (push covers those). Each check asks for the current minute's
	// copy of the shared feed, so all readers share one cached answer per minute.
	let lastCheck = Date.now();
	const checkAgain = () => {
		if (document.hidden) return;
		lastCheck = Date.now();
		if (feed) { feedAsked = false; loadFeed(); }
		if (slipCfg) { slipAsked = ''; loadSlip(); }
	};
	setInterval(checkAgain, 60000);
	document.addEventListener('visibilitychange', () => {
		if (!document.hidden && Date.now() - lastCheck > 60000) checkAgain();
	});
	// Nothing followed, nothing to tell: the list stays empty and the feed isn't fetched.
	const followsChanged = () => {
		const hasSlip = !!slipCfg && slipPicks().some((p) => p.market_id && !p.example);
		if (hasSlip) idle(loadSlip);
		if (!follows.length && !hasSlip && !(recoOn() && Object.keys(interest).length)) {
			notes = [];
			unread = 0;
			store.set('unread', '0');
			paint();
			announce();
			return;
		}
		if (feed) compute(); else idle(loadFeed);
	};
	const markRead = (id) => { readMap[id] = nowS(); saveMap('read', readMap); compute(); };
	const markAllRead = () => { notes.forEach((n) => { readMap[n.id] = nowS(); }); saveMap('read', readMap); compute(); };
	const dismissNote = (id) => { gone[id] = nowS(); readMap[id] = nowS(); saveMap('gone', gone); saveMap('read', readMap); compute(); };

	// The settings sheet's Alerts tab shows the list; without one, NUDGE's own dialog does.
	const openNotes = (from) => {
		const panel = settingsPanel();
		if (panel) return panel.open(unread || notes.length ? 'alerts-notifications' : 'alerts', from);
		return manage();
	};

	// A small bubble from the bell names the newest unread item, once per item. It overlays the page
	// (nothing moves), leaves on its own after six seconds unless pointed at or focused, and never
	// shows inside the app pages a site names (the bet calculator, the map).
	// An arcade sound as a notification comes in, made in the browser (no sound file to load). Site
	// notifications get a "ding-ding", two bright bell notes like a arcade game paying out; selection list
	// ones get a quick three-note coin run that ends on a ring, so the two are told apart by ear. Each
	// note is a square wave softened by a triangle an octave up, dying away quickly. Browsers only let
	// a page make sound once the reader has interacted with it, so if it is not allowed yet it waits
	// for their first tap or key while the bubble is showing, and otherwise stays silent. Readers can
	// switch it off in Alerts.
	let audio = null;
	const soundOn = () => store.get('sound') !== 'off';
	const TUNES = {
		site: [[1568, 0, 0.38], [2093, 0.12, 0.38]],
		slip: [[1319, 0, 0.12], [1568, 0.07, 0.12], [2093, 0.14, 0.12], [2637, 0.21, 0.5]]
	};
	const ding = (kind, forMs) => {
		const Ctx = window.AudioContext || window.webkitAudioContext;
		if (!Ctx || !soundOn()) return;
		const tune = TUNES[kind] || TUNES.site;
		const go = () => {
			const t0 = audio.currentTime + 0.01;
			const out = audio.createGain();
			out.gain.value = 0.16;
			out.connect(audio.destination);
			tune.forEach(([f, at, len]) => {
				[['square', f, 0.35], ['triangle', f * 2, 0.5]].forEach(([type, freq, level]) => {
					const o = audio.createOscillator();
					const g = audio.createGain();
					o.type = type;
					o.frequency.value = freq;
					g.gain.setValueAtTime(0.0001, t0 + at);
					g.gain.exponentialRampToValueAtTime(level, t0 + at + 0.006);
					g.gain.exponentialRampToValueAtTime(0.0001, t0 + at + len);
					o.connect(g);
					g.connect(out);
					o.start(t0 + at);
					o.stop(t0 + at + len + 0.02);
				});
			});
		};
		const wait = () => {
			if (!forMs) return;
			const until = Date.now() + forMs;
			const once = () => {
				stop();
				if (Date.now() <= until) audio.resume().then(() => { if (audio.state === 'running') go(); }, () => {});
			};
			const stop = () => {
				document.removeEventListener('pointerdown', once, true);
				document.removeEventListener('keydown', once, true);
			};
			document.addEventListener('pointerdown', once, true);
			document.addEventListener('keydown', once, true);
			setTimeout(stop, forMs);
		};
		try {
			audio = audio || new Ctx();
			if (audio.state === 'running') return go();
			// Try anyway: a browser that already allows sound here resumes straight away.
			audio.resume().then(() => { if (audio.state === 'running') go(); else wait(); }, wait);
		} catch (e) {}
	};

	let bubbleEl = null;
	let reloaded = false;
	try { const nav = performance.getEntriesByType('navigation')[0]; reloaded = !!nav && nav.type === 'reload'; } catch (e) {}
	// While the page plays its first-load intro, the bubble (and its sound) waits, then arrives a moment
	// after everything else has come in. cfg.holdWhile names what to wait for on the <html> element.
	const HOLD = cfg.holdWhile || '';
	let holding = null;
	const held = () => { try { return !!HOLD && document.documentElement.matches(HOLD); } catch (e) { return false; } };
	const afterIntro = (fn) => {
		if (holding) return;
		holding = new MutationObserver(() => {
			if (held()) return;
			holding.disconnect();
			holding = null;
			setTimeout(fn, 600);
		});
		holding.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
		setTimeout(() => { if (holding) { holding.disconnect(); holding = null; fn(); } }, 12000); // never wait for ever
	};
	// One bubble at a time, hung from an element in the header (the bell, or whatever an onboarding
	// step points at). It names one thing: a picture or an icon, a title and a line under it, top
	// aligned, with its label on a flag that slides out from behind its left edge once it has arrived
	// and tucks back in before it goes. A bar along its foot shows how long it has left; pointing at
	// the bubble or focusing inside it holds the bar, and leaving lets it carry on. When it runs out
	// the bubble slides off to the side; a dismissal just tucks away. With reduced motion there is no
	// bar or flag movement: the same time is kept on a plain timer, held and resumed the same way.
	const showBubble = (o) => {
		const anchor = o.anchor;
		if (!anchor || !anchor.getClientRects().length || bubbleEl) return false;
		const r = anchor.getBoundingClientRect();
		const right = Math.max(8, Math.round(window.innerWidth - r.right));
		const b = el('div', { class: 'ace-nudge-bubble' + (o.onboard ? ' ace-nudge-bubble--onboard' : ''), role: 'status', 'aria-live': 'polite' });
		b.style.top = Math.round(r.bottom + 10) + 'px';
		b.style.right = right + 'px';
		b.style.setProperty('--ace-nudge-bubble-arrow', Math.max(12, Math.round(window.innerWidth - r.right - right + r.width / 2 - 7)) + 'px');
		const LIFE = o.life || 6000;
		const bar = el('span', { class: 'ace-nudge-bubble__timer', 'aria-hidden': 'true' }, el('span', {}));
		// The label is a small flag that sits across the bubble's left edge. It is the bubble's sibling,
		// not its child, so it can pass behind the bubble and in front of it.
		const flag = o.label ? el('span', { class: 'ace-nudge-bubble-flag is-waiting', 'data-kind': o.kind || 'new', 'aria-hidden': 'true' }, o.label) : null;
		const Z = 100001;
		const rnd = (a, c) => a + Math.random() * (c - a);
		let flagOut = false;
		let flagAnim = null;
		const placeFlag = () => {
			const box = b.getBoundingClientRect();
			flag.style.left = Math.round(box.left - flag.offsetWidth / 2) + 'px';
			flag.style.top = Math.round(box.top + 14) + 'px';
		};
		// The flag pokes out from behind the bubble to the left until it is clear of the edge, then
		// comes in front and tucks back over the edge into its place with a small spring and a tilt.
		const flagIn = () => {
			if (!flag || bubbleEl !== b) return;
			placeFlag();
			if (calm || !flag.animate) { flag.classList.remove('is-waiting'); flagOut = true; return; }
			const clear = flag.offsetWidth / 2 + 3;
			const tilt = (Math.random() < 0.5 ? -1 : 1) * rnd(2, 4);
			flag.style.zIndex = String(Z - 1);
			flag.classList.remove('is-waiting');
			flagOut = true;
			flagAnim = flag.animate([{ translate: clear + 'px 0', rotate: '0deg' }, { translate: -clear + 'px 0', rotate: '0deg' }], { duration: rnd(680, 820), easing: 'cubic-bezier(.42, 0, .58, 1)', fill: 'forwards' });
			flagAnim.onfinish = () => {
				flag.style.zIndex = String(Z + 1);
				flagAnim.cancel();
				flagAnim = flag.animate([
					{ translate: -clear + 'px 0', rotate: '0deg', offset: 0, easing: 'cubic-bezier(.16, .8, .25, 1)' },
					{ translate: '1.5px 0', rotate: tilt + 'deg', offset: 0.65, easing: 'cubic-bezier(.35, 0, .65, 1)' },
					{ translate: '-.5px 0', rotate: (-tilt * 0.3) + 'deg', offset: 0.85, easing: 'cubic-bezier(.35, 0, .65, 1)' },
					{ translate: '0 0', rotate: '0deg', offset: 1 },
				], { duration: rnd(220, 280), easing: 'linear' });
			};
		};
		// The reverse: out from its place to the left, then back under the bubble.
		const flagAway = (done) => {
			if (!flag) return done();
			if (flagAnim) { flagAnim.onfinish = null; flagAnim.cancel(); flagAnim = null; }
			if (!flagOut || calm || !flag.animate) { flag.remove(); return done(); }
			const clear = flag.offsetWidth / 2 + 3;
			flag.style.zIndex = String(Z + 1);
			const lift = flag.animate([{ translate: '0 0', rotate: '0deg' }, { translate: -clear + 'px 0', rotate: '0deg' }], { duration: 150, easing: 'cubic-bezier(.35, 0, .65, 1)', fill: 'forwards' });
			lift.onfinish = () => {
				flag.style.zIndex = String(Z - 1);
				const under = flag.animate([{ translate: -clear + 'px 0', rotate: '0deg' }, { translate: clear + 'px 0', rotate: '0deg' }], { duration: 260, easing: 'cubic-bezier(.4, 0, .8, .3)', fill: 'forwards' });
				under.onfinish = () => { flag.remove(); done(); };
			};
		};
		let timer = null;
		let run = null;
		let left = LIFE;
		let since = 0;
		let flagTimer = null;
		const close = (e, why) => {
			if (bubbleEl !== b) return;
			clearTimeout(timer);
			clearTimeout(flagTimer);
			if (run) { run.onfinish = null; run.cancel(); run = null; }
			bubbleEl = null;
			document.removeEventListener('keydown', onKey);
			const gone = () => { b.remove(); if (o.onDone) o.onDone(why || 'closed'); };
			if (calm || !b.animate) { if (flag) flag.remove(); return gone(); }
			const leave = () => {
				const out = why === 'time'
					? b.animate([{ opacity: 1, transform: 'none' }, { opacity: 1, transform: 'translateX(-8px)', offset: .25 }, { opacity: 0, transform: 'translateX(calc(100% + 32px))' }], { duration: 420, easing: 'cubic-bezier(.5,0,.8,.4)' })
					: b.animate([{ opacity: 1, transform: 'none' }, { opacity: 0, transform: 'translateY(-6px) scale(.96)' }], { duration: 180, easing: 'ease-in' });
				out.finished.then(gone, gone);
			};
			// The flag goes back behind the bubble first, then the bubble leaves.
			flagAway(leave);
		};
		const onKey = (e) => { if (e.key === 'Escape') close(); };
		const hold = () => {
			if (run) return run.pause();
			if (timer) { clearTimeout(timer); timer = null; left = Math.max(0, left - (Date.now() - since)); }
		};
		const wait = () => {
			if (run) return run.play();
			clearTimeout(timer);
			since = Date.now();
			timer = setTimeout(() => close(null, 'time'), left);
		};
		let media = '';
		if (o.thumb) media = el('img', { class: 'ace-nudge-bubble__thumb', src: o.thumb, alt: '', width: '44', height: '44', decoding: 'async' });
		else if (o.icon) { media = el('span', { class: 'ace-nudge-bubble__icon', 'aria-hidden': 'true' }); media.innerHTML = o.icon; }
		b.append(
			el('button', { type: 'button', class: 'ace-nudge-bubble__open', on: { click: () => { close(null, 'opened'); if (o.onOpen) o.onOpen(); } } },
				media,
				el('span', { class: 'ace-nudge-bubble__text' },
					el('span', { class: 'ace-nudge-bubble__title' }, o.title),
					o.sub ? el('span', { class: 'ace-nudge-bubble__more' }, o.sub) : '')),
			el('button', { type: 'button', class: 'ace-nudge-bubble__close', 'aria-label': 'Dismiss', on: { click: () => close() } }, '×'),
			bar);
		b.addEventListener('mouseenter', hold);
		b.addEventListener('mouseleave', wait);
		b.addEventListener('focusin', hold);
		b.addEventListener('focusout', wait);
		document.addEventListener('keydown', onKey);
		document.addEventListener('ace-sheet:open', () => close(), { once: true });
		window.addEventListener('resize', () => close(), { once: true });
		document.body.append(b);
		if (flag) document.body.append(flag);
		bubbleEl = b;
		if (!calm && b.animate) {
			b.animate([{ opacity: 0, transform: 'translateY(-10px) scale(.6)' }, { opacity: 1, transform: 'translateY(2px) scale(1.02)', offset: .7 }, { opacity: 1, transform: 'none' }], { duration: 380, easing: 'cubic-bezier(.2,.8,.3,1)' });
			flagTimer = setTimeout(flagIn, 420);
		} else {
			flagIn();
		}
		if (!calm && bar.firstChild.animate) {
			run = bar.firstChild.animate([{ transform: 'scaleX(1)' }, { transform: 'scaleX(0)' }], { duration: LIFE, easing: 'linear', fill: 'forwards' });
			run.onfinish = () => close(null, 'time');
			// Already under the pointer as it arrives: it starts held.
			if (b.matches(':hover')) run.pause();
		} else {
			b.classList.add('is-untimed');
			wait();
		}
		return true;
	};
	const clear = () => !(cfg.quietOn && document.querySelector(cfg.quietOn)) && !document.documentElement.classList.contains('has-modal-open') && !document.querySelector('dialog[open]');
	const bubble = () => {
		if (held()) return afterIntro(bubble);
		const bell = bells[0];
		if (!bell || !bell.getClientRects().length || bubbleEl) return;
		if (!clear()) return;
		// A reload (hard refresh included) shows the bubble again for what is unread, so it can be
		// previewed; an ordinary visit tells each item once.
		const fresh = notes.filter((n) => n.unread && (reloaded || !shown[n.id]));
		if (!fresh.length) return;
		reloaded = false;
		fresh.forEach((n) => { shown[n.id] = nowS(); });
		saveMap('shown', shown);
		ding(fresh[0].group === 'slip' ? 'slip' : 'site', 6000);
		const first = fresh[0];
		showBubble({ anchor: bell, label: first.label, kind: first.kind, title: first.title, thumb: first.thumb, sub: fresh.length > 1 ? '+' + (fresh.length - 1) + ' more' : '', onOpen: () => openNotes(bell) });
	};

	// A reader who follows nothing yet is shown round, once: a bubble from the bell saying what alerts
	// are for, then one for each further step a site lists (cfg.onboard, the ace_nudge_onboarding
	// filter), such as its settings menu. Each runs its time or is closed, then the next arrives. A step
	// is told once per browser; a step whose target is not on the page is skipped.
	const sliders = '<svg viewBox="0 0 24 24" width="24" height="24"><path d="M4 7h10M18 7h2M4 17h2M10 17h10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="16" cy="7" r="2.2" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="8" cy="17" r="2.2" fill="none" stroke="currentColor" stroke-width="2"/></svg>';
	const onboard = () => {
		if (held()) return afterIntro(onboard);
		if (bubbleEl || store.get('token') || follows.length || !clear()) return;
		const told = loadMap('onboard');
		const steps = (Array.isArray(cfg.onboard) ? cfg.onboard : []).filter((s) => s && s.id && s.title && !told[s.id]);
		const next = () => {
			const step = steps.shift();
			if (!step || bubbleEl || !clear()) return;
			const anchor = step.target === 'bell' || !step.target ? bells[0] : document.querySelector(step.target);
			if (!anchor || !anchor.getClientRects().length) return next();
			told[step.id] = nowS();
			saveMap('onboard', told);
			const shownIt = showBubble({
				anchor, onboard: true, life: 8000, label: step.label, kind: 'tip', title: step.title, sub: step.text,
				icon: step.icon === 'settings' ? sliders : bellSvg,
				onOpen: () => (step.target === 'bell' || !step.target ? (settingsPanel() ? settingsPanel().open('alerts', anchor) : anchor.click()) : anchor.click()),
				// Opening what a step points at ends the tour there; otherwise the next step follows.
				onDone: (why) => { if (why !== 'opened') setTimeout(next, 500); },
			});
			if (!shownIt) next();
		};
		next();
	};

	cfg.api = {
		available: true,
		follow: (t) => clicked(t),
		unfollow: (t, id) => unfollowKeys(typeof t === 'string' ? [id !== undefined ? t + ':' + id : t] : (t.keys || [t.key])),
		following: (key) => follows.includes(key),
		follows: () => described(),
		status: () => status(),
		on: (f) => { listeners.push(f); return () => listeners.splice(listeners.indexOf(f), 1); },
		manage: () => manage(),
		notifications: () => ({ items: notes.slice(), unread, loaded: !!feed || !follows.length }),
		unread: () => unread,
		markRead: (id) => markRead(id),
		// Bets recommended from sports this reader reads (kept on this device): on unless switched off.
		recommending: () => recoOn(),
		setRecommending: (on) => setReco(!!on),
		// The ding as a notification comes in: on unless switched off, kept in this browser. Switching
		// it on plays it once, so the reader hears what they chose.
		sound: () => soundOn(),
		setSound: (on) => { store.set('sound', on ? 'on' : 'off'); if (on) ding('site', 0); },
		hasSlip: () => !!slipCfg,
		markAllRead: () => markAllRead(),
		dismiss: (id) => dismissNote(id),
		// What the settings panel needs to run every alert control in its own tab. The rules (age
		// check, consent wording, what a follow means) stay here.
		info: () => ({ name: cfg.name, wording: cfg.wording, privacy: cfg.privacy, minAge: cfg.minAge || 0, newsletter: !!cfg.newsletter, quiet: cfg.quiet || null }),
		pushBlocker: () => pushBlocker(),
		ageNeeded: () => !!cfg.minAge && store.get('age') !== 'yes',
		// The same rule as the dialog: someone born in the year that turns 18 may not be 18 yet.
		confirmAge: (year) => {
			const y = parseInt(year, 10), now = new Date().getFullYear();
			if (!y || y < 1900 || y > now) return { ok: false, message: 'Please enter a four-digit year.' };
			if (cfg.minAge && y > now - (cfg.minAge + 1)) {
				store.set('age', 'no');
				document.querySelectorAll('.ace-nudge-bar, .ace-nudge-footer, [data-ace-nudge]').forEach((b) => b.remove());
				return { ok: false, blocked: true, message: 'This service isn\'t available to you.' };
			}
			store.set('age', 'yes');
			return { ok: true };
		},
		// Asks the browser for permission (it must run from a click), then subscribes this browser.
		pushOn: async () => {
			const blocker = pushBlocker();
			if (blocker === 'ios') throw { message: 'On iPhone and iPad, alerts only work once the site is on your Home Screen. Tap Share, choose Add to Home Screen, then open it from there.' };
			if (blocker === 'unsupported') throw { message: 'This browser can\'t show alerts from websites.' };
			if (blocker === 'denied') throw { message: 'Alerts are blocked for this site. Allow them in your browser\'s site settings, then try again.' };
			await subscribePush();
			paint();
			return status();
		},
		pushOff: async () => { await withdraw(); return status(); },
		categories: () => (cfg.categories || []).map((c) => ({ label: c.label, ids: c.ids, on: categoryOn(c) })),
		site: (which) => follows.includes('site:' + which),
		setSite: async (which, want) => { await setFollow('site', which, want, 'site:' + which); paint(); },
		setCategory: async (label, want) => {
			const c = (cfg.categories || []).find((x) => x.label === label);
			if (!c) return;
			for (const id of c.ids) await setFollow('category', id, want, 'category:' + id);
			paint();
		},
		newsletter: ({ email, frequency, categories, website }) => post('subscribe', { channel: 'email', consent: true, age_confirmed: true, email, website: website || '', frequency: frequency === 'weekly' ? 'weekly' : 'daily', categories: categories || [] }),
		open: (section) => {
			if (section === 'newsletter') return ageStep(newsletterStep);
			if (section === 'push' || (section === 'interests' && !marketingOn())) return ageStep(() => pushStep(null));
			if (section === 'interests') return interestsStep(null);
			return manage();
		},
	};

	const mount = () => {
		const single = document.body.classList.contains('single');
		const blocks = document.querySelectorAll('[data-ace-nudge="follow"]');
		blocks.forEach((block) => block.append(...cfg.targets.map(followButton)));
		document.querySelectorAll('[data-ace-nudge="alerts"] button').forEach((b) => b.addEventListener('click', () => openManage(null, b)));
		document.querySelectorAll('[data-ace-nudge="newsletter"] button').forEach((b) => b.addEventListener('click', () => ageStep(newsletterStep)));
		if (cfg.autoBar && !blocks.length && cfg.targets.length) {
			const host = (cfg.host && document.querySelector(cfg.host))
				|| document.querySelector(single ? '.wp-block-post-title, h1' : '.wp-block-query-title, h1');
			const bar = el('div', { class: 'ace-nudge-bar' }, ...cfg.targets.map(followButton));
			if (cfg.targets.some((t) => t.side)) bar.classList.add('has-sides');
			if (host) {
				host.after(bar);
				// Archive headers sit centred in their cover, so the button follows them.
				if (!single && (host.closest('.wp-block-cover') || getComputedStyle(host).textAlign === 'center')) bar.classList.add('is-centred');
			} else {
				(document.querySelector('main') || document.body).prepend(bar);
			}
		}
		paint();

		// A tick at checkout or sign-up. The browser only allows the question on a click, so it's asked
		// now, and the order or account is linked on the next page.
		const deviceTick = async (e) => {
			const box = e.target.closest('[data-ace-nudge="device"]');
			if (!box || e.target.type !== 'checkbox') return;
			const note = box.querySelector('[data-ace-nudge-note]');
			if (!e.target.checked) { store.del('link'); note.textContent = ''; return; }
			try {
				if (!notifyOn()) await subscribePush('orders');
				store.set('link', '1');
				note.textContent = ' Order updates will start with your next order.';
			} catch (err) {
				e.target.checked = false;
				note.textContent = ' ' + ((err && err.message) || 'This browser can\'t do that.');
			}
		};
		document.addEventListener('change', deviceTick);

		// Block checkout is drawn by its own script, so the tick joins once its buttons appear.
		if (document.querySelector('.wp-block-woocommerce-checkout') && pushable && Notification.permission !== 'denied') {
			const place = () => {
				const actions = document.querySelector('.wc-block-checkout__actions');
				if (!actions || document.querySelector('[data-ace-nudge="device"]')) return;
				actions.before(el('p', { class: 'ace-nudge-tick', 'data-ace-nudge': 'device' },
					el('label', {}, el('input', { type: 'checkbox' }), ' ', cfg.ordersWording), el('span', { 'data-ace-nudge-note': '', role: 'status' })));
			};
			new MutationObserver(place).observe(document.body, { childList: true, subtree: true });
			place();
		}

		document.querySelectorAll('[data-ace-nudge="orders"]').forEach((box) => {
			const button = box.querySelector('button');
			const note = box.querySelector('[data-ace-nudge-note]');
			const turnOn = async () => {
				if (cantPush()) return;
				try {
					button.disabled = true;
					if (!notifyOn()) await subscribePush('orders');
					const res = await fetch(cfg.rest + 'orders', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json', ...(box.dataset.nonce ? { 'X-WP-Nonce': box.dataset.nonce } : {}) },
						body: JSON.stringify({ token: store.get('token'), order_id: box.dataset.order, order_key: box.dataset.key }),
					});
					if (!res.ok) throw await res.json();
					apply(await res.json());
					note.textContent = 'Order updates are on for this device.';
					button.hidden = true;
				} catch (err) {
					note.textContent = (err && err.message) || 'Something went wrong. Please try again.';
					button.disabled = false;
				}
			};
			button.addEventListener('click', turnOn);
			// Ticked at checkout or sign-up, so finish the job without asking again.
			if (store.get('link') && notifyOn()) {
				store.del('link');
				turnOn();
			}
		});
		// Nothing to suggest where it's on already, or where the browser can't do it.
		const prompts = () => document.querySelectorAll('[data-ace-nudge="orders"]').forEach((box) => {
			const on = box.dataset.order ? follows.includes('order:' + box.dataset.order) : follows.some((f) => f.startsWith('customer:'));
			if ((on || !pushable || Notification.permission === 'denied') && !box.querySelector('button').hidden) box.hidden = true;
		});
		prompts();
		document.querySelectorAll('[data-ace-nudge="device"]').forEach((box) => { if (!pushable || Notification.permission === 'denied') box.hidden = true; });

		const search = cfg.bell && document.querySelector(cfg.bell);
		if (search) {
			const bell = el('button', { type: 'button', class: 'ace-nudge-bell', 'aria-label': 'Alerts and newsletter', title: 'Alerts and newsletter', on: { click: () => (settingsPanel() ? openNotes(bell) : openManage(null, bell)) } });
			bell.innerHTML = bellSvg + '<span class="ace-nudge-bell__count" hidden></span>';
			// The footer put a stand-in here as the page was parsed (class-front.php); take its place.
			const slot = document.querySelector('.ace-nudge-bell--slot');
			if (slot) slot.replaceWith(bell); else search.before(bell);
			bells.push(bell);
		}

		const footer = cfg.footerAfter && document.querySelector(cfg.footerAfter);
		if (footer) {
			footer.after(el('p', { class: 'ace-nudge-footer' },
				cfg.newsletter ? el('button', { type: 'button', on: { click: (e) => (settingsPanel() ? openManage(null, e.currentTarget) : ageStep(newsletterStep)) } }, 'Newsletter') : '', ' ',
				el('button', { type: 'button', on: { click: (e) => openManage(null, e.currentTarget) } }, 'Browser alerts')));
		}
		document.body.append(dialog);
		if (qs.has('ace_nudge_manage')) setTimeout(() => openManage(), 300);
		const token = store.get('token');
		// Blocking notifications in the browser is a withdrawal, so honour it here too.
		if (token && pushable && Notification.permission !== 'granted') {
			store.del('token');
			post('withdraw', { token }).catch(() => {});
		} else if (token) post('state', { token }).then((s) => {
			if (!s.subscribed) store.del('token');
			ready = true;
			apply(s);
			prompts();
			if (cfg.post_id && follows.includes('site:reads')) post('read', { token, post_id: cfg.post_id }).catch(() => {});
		}).catch(() => { ready = true; paint(); });
		if (!store.get('token')) { ready = true; paint(); followsChanged(); idle(() => setTimeout(onboard, 1500)); }
	};
	document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', mount) : mount();
})();
