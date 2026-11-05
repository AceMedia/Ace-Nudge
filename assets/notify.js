(function () {
	const cfg = window.aceNudge;
	if (!cfg) return;
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
	if (cfg.mode === 'test' && !store.get('preview')) return;
	// Someone who gave an under-18 year is never asked again, and can't retry with another year.
	if (store.get('age') === 'no') return;

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

	const apply = (s) => { follows = s.follows; items = s.items || []; paint(); };
	const follow = (t) => post('follow', { ...t, token: store.get('token') }).then(apply);
	const notifyOn = () => pushable && !!store.get('token') && Notification.permission === 'granted';
	const marketingOn = () => notifyOn() && store.get('scope') !== 'orders';

	// Nobody logs in here, so follows belong to this browser's subscription. Following therefore
	// starts by turning notifications on. The toggle starts off and covers this channel only.
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

	// Everything this browser follows, one switch each. Email is managed from the link in each email.
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
					await post('withdraw', { token });
					const reg = await navigator.serviceWorker.getRegistration();
					const sub = reg && await reg.pushManager.getSubscription();
					if (sub) await sub.unsubscribe();
					store.del('token');
					store.del('scope');
					follows = [];
					items = [];
					paint();
					show(heading('Turned off'), lead('We won\'t send you any more notifications, and your subscription has been deleted.'), actions(closeBtn('Close')));
				}) : '',
				closeBtn('Close')));
	};

	const buttons = [];
	const paint = () => buttons.forEach(([b, t]) => {
		const on = follows.includes(t.key);
		b.textContent = (on ? 'Following ' : 'Follow ') + t.label;
		b.setAttribute('aria-pressed', String(on));
	});

	// A follow button that's already following opens the panel rather than quietly unfollowing.
	const clicked = (t) => {
		if (!marketingOn()) return ageStep(() => pushStep(t));
		return follows.includes(t.key) ? manage(t) : follow(t);
	};

	const followButton = (t) => {
		const b = el('button', { type: 'button', class: 'ace-nudge-follow', on: { click: () => clicked(t) } });
		if (t.side) b.dataset.side = t.side;
		buttons.push([b, t]);
		return b;
	};

	const mount = () => {
		const single = document.body.classList.contains('single');
		const blocks = document.querySelectorAll('[data-ace-nudge="follow"]');
		blocks.forEach((block) => block.append(...cfg.targets.map(followButton)));
		document.querySelectorAll('[data-ace-nudge="alerts"] button').forEach((b) => b.addEventListener('click', () => manage()));
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
			const bell = el('button', { type: 'button', class: 'ace-nudge-bell', 'aria-label': 'Alerts and newsletter', title: 'Alerts and newsletter', on: { click: () => manage() } });
			bell.innerHTML = '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path fill="currentColor" d="M12 22a2.5 2.5 0 0 0 2.45-2h-4.9A2.5 2.5 0 0 0 12 22zm7-6v-5a7 7 0 0 0-5.5-6.84V3.5a1.5 1.5 0 0 0-3 0v.66A7 7 0 0 0 5 11v5l-2 2v1h18v-1z"/></svg>';
			search.before(bell);
		}

		const footer = cfg.footerAfter && document.querySelector(cfg.footerAfter);
		if (footer) {
			footer.after(el('p', { class: 'ace-nudge-footer' },
				cfg.newsletter ? el('button', { type: 'button', on: { click: () => ageStep(newsletterStep) } }, 'Newsletter') : '', ' ',
				el('button', { type: 'button', on: { click: () => manage() } }, 'Browser alerts')));
		}
		document.body.append(dialog);
		if (qs.has('ace_nudge_manage')) manage();
		const token = store.get('token');
		// Blocking notifications in the browser is a withdrawal, so honour it here too.
		if (token && pushable && Notification.permission !== 'granted') {
			store.del('token');
			post('withdraw', { token }).catch(() => {});
		} else if (token) post('state', { token }).then((s) => {
			if (!s.subscribed) store.del('token');
			apply(s);
			prompts();
			if (cfg.post_id && follows.includes('site:reads')) post('read', { token, post_id: cfg.post_id }).catch(() => {});
		}).catch(() => {});
	};
	document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', mount) : mount();
})();
