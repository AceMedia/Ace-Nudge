self.addEventListener('push', (e) => {
	const m = e.data ? e.data.json() : {};
	e.waitUntil(self.registration.showNotification(m.title || '', {
		body: m.body || '',
		tag: m.tag || 'news',
		data: { url: m.url || self.registration.scope },
		actions: [{ action: 'stop', title: 'Stop alerts' }],
	}));
});

self.addEventListener('notificationclick', (e) => {
	e.notification.close();
	// The worker's scope is the site's own path, so this also works for a site in a subdirectory.
	const url = e.action === 'stop' ? self.registration.scope + '?ace_nudge_manage=1' : e.notification.data.url;
	e.waitUntil(clients.openWindow(new URL(url, self.location.origin).href));
});
