// LiquifyCRM's service worker. It does four small things and nothing else:
//  1. when the internet is gone, a page opened in LiquifyCRM shows our own "You are offline" page;
//  2. it draws the desktop notification for a new bell entry, sent by an open page (see app.js)
//     or, with LiquifyCRM closed, by the server through the browser's push service (Web Push);
//  3. a click on that notification brings LiquifyCRM forward on the right screen;
//  4. it remembers this computer's sound choice, so a push with LiquifyCRM closed can stay quiet.
// It keeps no copy of the app's pages or files, so every deploy is used at once. The fetch handler
// is also what lets Chrome offer "Install app".

const CACHE = 'lq-offline-v1';
const PREFS = 'lq-prefs-v1';
const PREFS_KEY = '/__lq-prefs';
const OFFLINE = '/offline.html';
const ICON = '/images/brand/icon-192.png';
const HOME = '/b';

const show = (item, silent) => self.registration.showNotification(String(item.title ?? 'LiquifyCRM'), {
    body: String(item.body ?? ''),
    icon: ICON,
    // The same entry from two tabs, or from a tab and a push, replaces itself instead of showing twice.
    tag: String(item.id ?? ''),
    data: { url: item.url ?? HOME },
    silent: Boolean(silent),
});

// Somebody is looking at LiquifyCRM: its bell, toast and chime announce what is new.
const looking = (windows) => windows.some((client) => client.focused && client.visibilityState === 'visible');

const readPrefs = async () => {
    try {
        const saved = await (await caches.open(PREFS)).match(PREFS_KEY);

        return saved ? await saved.json() : {};
    } catch (error) {
        return {};
    }
};

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
        await Promise.all(names.filter((name) => name !== CACHE && name !== PREFS).map((name) => caches.delete(name)));

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

self.addEventListener('message', (event) => {
    const message = event.data ?? {};

    // This computer's sound choice, for pushes that arrive while no page is open.
    if (message.type === 'lq-prefs') {
        event.waitUntil(caches.open(PREFS).then((cache) => cache.put(PREFS_KEY, new Response(JSON.stringify({ sound: Boolean(message.sound) })))).catch(() => {}));

        return;
    }

    // New bell entries, sent by the tab that heard them first: desktop notifications unless somebody is looking.
    if (message.type !== 'lq-alert') return;

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

        if (! message.always && looking(windows)) return;

        for (const item of message.items ?? []) {
            await show(item, message.silent);
        }
    })());
});

// A new bell entry sent by the server (Web Push), also with LiquifyCRM closed. Browsers want every push to
// show something; only while a LiquifyCRM window is in front, which the browser counts as shown, does the
// open page announce it instead. Open pages hear of it either way, so their bell looks at once.
self.addEventListener('push', (event) => {
    event.waitUntil((async () => {
        let item;

        try {
            item = event.data ? event.data.json() : {};
        } catch (error) {
            item = { title: 'LiquifyCRM', body: event.data?.text() ?? '' };
        }

        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        const shown = ! looking(windows);

        if (shown) {
            const prefs = await readPrefs();

            await show(item, prefs.sound === false);
        }

        windows.forEach((client) => client.postMessage({ type: 'lq-pushed', id: item.id ?? null, shown }));
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
