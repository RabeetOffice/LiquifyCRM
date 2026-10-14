// LiquifyCRM's service worker. It does three small things and nothing else:
//  1. when the internet is gone, a page opened in LiquifyCRM shows our own "You are offline" page;
//  2. it draws the desktop notification for a new bell entry (the page sends it, see app.js);
//  3. a click on that notification brings LiquifyCRM forward on the right screen.
// It keeps no copy of the app's pages or files, so every deploy is used at once. The fetch handler
// is also what lets Chrome offer "Install app".

const CACHE = 'lq-offline-v1';
const OFFLINE = '/offline.html';
const ICON = '/images/brand/apple-touch-icon.png';
const HOME = '/b';

// The offline page is a nicety: when the browser cannot keep it (storage full or blocked), the worker
// still installs, so desktop alerts and "Install app" keep working.
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE)
            .then((cache) => cache.add(new Request(OFFLINE, { cache: 'reload' })))
            .catch(() => {})
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const names = await caches.keys();
        await Promise.all(names.filter((name) => name !== CACHE).map((name) => caches.delete(name)));

        // The page is asked for while the worker starts, so having one costs no time.
        if (self.registration.navigationPreload) await self.registration.navigationPreload.enable();

        await self.clients.claim();
    })());
});

// Only opening a page goes through here, straight to the server; everything else never stops by.
self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.mode !== 'navigate' || request.method !== 'GET') return;

    event.respondWith((async () => {
        try {
            return (await event.preloadResponse) ?? (await fetch(request));
        } catch (error) {
            return (await caches.match(OFFLINE)) ?? Response.error();
        }
    })());
});

// New bell entries, sent by the tab that heard them first. While somebody is looking at LiquifyCRM
// the bell and its toast are enough; otherwise each entry becomes a desktop notification.
self.addEventListener('message', (event) => {
    const message = event.data ?? {};

    if (message.type !== 'lq-alert') return;

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

        if (! message.always && windows.some((client) => client.focused && client.visibilityState === 'visible')) return;

        for (const item of message.items ?? []) {
            await self.registration.showNotification(String(item.title ?? 'LiquifyCRM'), {
                body: String(item.body ?? ''),
                icon: ICON,
                // The same entry heard by two tabs replaces itself instead of showing twice.
                tag: String(item.id ?? ''),
                data: { url: item.url ?? HOME },
                silent: Boolean(message.silent),
            });
        }
    })());
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const asked = new URL(event.notification.data?.url ?? HOME, self.location.origin);
    // Only screens of this site; anything else opens the start page.
    const url = asked.origin === self.location.origin ? asked.href : new URL(HOME, self.location.origin).href;

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        const open = windows.find((client) => client.focused) ?? windows[0];

        if (open) {
            try {
                await open.focus();
            } catch (error) {
                // Some systems do not let a window be brought forward; the screen still changes.
            }

            open.postMessage({ type: 'lq-open', url });

            return;
        }

        await self.clients.openWindow(url);
    })());
});
