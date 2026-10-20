import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';

// The chart library is as large as the rest of the app together and only a few screens draw charts,
// so it is fetched the first time a chart appears instead of on every page.
let chartLibrary = null;
const loadChart = () => (chartLibrary ??= import('chart.js/auto').then((module) => module.default));

const tokens = () => {
    const style = getComputedStyle(document.documentElement);
    const read = (name) => style.getPropertyValue(name).trim();

    return {
        ink: read('--lq-ink'),
        muted: read('--lq-muted'),
        hairline: read('--lq-hairline'),
        orange: read('--lq-orange'),
        surface: read('--lq-surface'),
        success: read('--lq-success'),
        info: read('--lq-info'),
    };
};

// ----------------------------------------------------------------- theme
Alpine.store('theme', {
    mode: 'system',
    current: 'light',

    init() {
        this.mode = localStorage.getItem('theme') ?? 'system';
        this.apply();

        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
            if (this.mode === 'system') this.apply();
        });
    },

    apply() {
        const dark = this.mode === 'dark' || (this.mode === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        this.current = dark ? 'dark' : 'light';
        document.documentElement.classList.toggle('dark', dark);
        // The server reads this cookie so the next page already arrives in the right mode.
        document.cookie = 'lq_theme=' + this.current + '; path=/; max-age=31536000; samesite=lax';
        window.dispatchEvent(new CustomEvent('theme-applied', { detail: this.current }));
    },

    set(mode) {
        this.mode = mode;
        localStorage.setItem('theme', mode);
        this.apply();
    },

    toggle() {
        this.set(this.current === 'dark' ? 'light' : 'dark');
    },
});

// --------------------------------------------------------- time zone
// This computer's time zone, for the server (ViewerTime): unless the Profile names one, times are shown and
// typed in it. Set on every page, the sign-in page too, so the first screen after signing in is already right.
try {
    const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
    const cookie = 'lq_tz=' + encodeURIComponent(zone);

    if (zone && ! document.cookie.split('; ').includes(cookie)) {
        document.cookie = cookie + '; path=/; max-age=31536000; samesite=lax';
    }
} catch (error) {
    // An old browser without Intl: LiquifyCRM's default time zone is used.
}

// --------------------------------------------------------------- sidebar
Alpine.store('sidebar', {
    open: true,
    mobile: false,

    init() {
        this.open = localStorage.getItem('sidebar') !== 'collapsed';
    },

    toggle() {
        if (window.matchMedia('(max-width: 1023px)').matches) {
            this.mobile = ! this.mobile;
            return;
        }

        this.open = ! this.open;
        localStorage.setItem('sidebar', this.open ? 'open' : 'collapsed');
    },

    closeMobile() {
        this.mobile = false;
    },
});

// ---------------------------------------------------------------- toasts
Alpine.store('toasts', {
    items: [],

    push(toast) {
        const id = Date.now() + Math.random();
        const item = { id, title: '', body: null, type: 'success', duration: 5000, undo: null, ...toast };
        this.items.push(item);

        if (item.duration > 0) {
            setTimeout(() => this.remove(id), item.duration);
        }
    },

    remove(id) {
        this.items = this.items.filter((item) => item.id !== id);
    },
});

window.addEventListener('toast', (event) => Alpine.store('toasts').push(event.detail ?? {}));

// -------------------------------------------------------------- behaviours
Alpine.data('dropdown', () => ({
    open: false,
    toggle() { this.open = ! this.open; },
    close() { this.open = false; },
}));

// A choice from a long list with a search box (<x-ui.picker>). The list stays out of Alpine's
// reactive data: four hundred time zones need no watching, only filtering.
Alpine.data('picker', ({ model, options, pinned }) => ({
    open: false,
    query: '',
    active: 0,

    get value() {
        return this.$wire[model];
    },

    get current() {
        return options.find((option) => option.value === this.value) ?? null;
    },

    get shown() {
        const words = this.query.trim().toLowerCase().split(/\s+/).filter(Boolean);

        // Nothing typed: the usual choices and the current one, not the whole list.
        if (words.length === 0) {
            return options.filter((option) => pinned.includes(option.value) || option.value === this.value);
        }

        const starts = (option) => Number(option.label.toLowerCase().startsWith(words[0]));

        return options
            .filter((option) => words.every((word) => option.search.includes(word)))
            .sort((a, b) => starts(b) - starts(a))
            .slice(0, 40);
    },

    toggle() {
        this.open ? this.close() : this.show();
    },

    show() {
        this.query = '';
        this.open = true;
        this.active = Math.max(0, this.shown.findIndex((option) => option.value === this.value));
        this.$nextTick(() => this.$refs.search.focus());
    },

    close(refocus = false) {
        if (! this.open) return;

        this.open = false;
        if (refocus) this.$refs.button.focus();
    },

    move(step) {
        const count = this.shown.length;
        if (count === 0) return;

        this.active = (this.active + step + count) % count;
        this.$nextTick(() => this.$refs.list.querySelector('.lq-picker-active')?.scrollIntoView({ block: 'nearest' }));
    },

    choose(option) {
        if (! option) return;

        this.$wire[model] = option.value;
        this.close(true);
    },
}));

// Give a lead to somebody from where you are (<x-leads::assign-menu>): the Owner cell of the list, the Assign
// tile of a lead. The menu is drawn at the end of <body> and placed beside whatever asked for it, so a table's
// scroll box or a slide-over cannot cut it. The chosen name shows at once (`pending`), before the answer.
Alpine.data('assignMenu', ({ people, teams = [], assign, send = null }) => ({
    open: false,
    query: '',
    place: '',
    lead: null,
    dept: null,
    owner: null,
    pending: {},
    people,
    teams,

    // A lead that is already in a department is offered that department's people.
    get inReach() {
        return this.dept === null ? this.people : this.people.filter((person) => person.departments.includes(this.dept));
    },

    get shown() {
        const query = this.query.trim().toLowerCase();

        return query === '' ? this.inReach : this.inReach.filter((person) => (person.name + ' ' + person.team).toLowerCase().includes(query));
    },

    // The menu stands still while the page under it scrolls, so scrolling anything but the menu closes it.
    init() {
        this.onScroll = (event) => {
            if (this.open && ! this.$refs.menu?.contains(event.target)) this.close();
        };

        window.addEventListener('scroll', this.onScroll, true);
    },

    destroy() {
        window.removeEventListener('scroll', this.onScroll, true);
    },

    initials(name) {
        return name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0].toUpperCase()).join('');
    },

    openFor(trigger, { lead, dept = null, owner = null }) {
        if (this.open && this.lead === lead) return this.close();

        Object.assign(this, { lead, dept, owner, query: '', open: true });

        this.$nextTick(() => {
            const box = trigger.getBoundingClientRect();
            const menu = this.$refs.menu;
            const left = Math.max(12, Math.min(box.left, window.innerWidth - menu.offsetWidth - 12));
            const below = box.bottom + 6;
            // No room underneath: open upwards.
            const top = below + menu.offsetHeight > window.innerHeight - 12 ? Math.max(12, box.top - menu.offsetHeight - 6) : below;

            this.place = `left: ${left}px; top: ${top}px`;

            if (this.inReach.length > 6) this.$refs.search.focus();
        });
    },

    close() {
        this.open = false;
    },

    pick(person) {
        const lead = this.lead;

        this.pending[lead] = person.name;
        this.close();

        Promise.resolve(assign(lead, person.id)).finally(() => { delete this.pending[lead]; });
    },

    sendTo(team) {
        const lead = this.lead;

        this.close();
        if (send) send(lead, team.id);
    },
}));

Alpine.data('chart', (config) => {
    // Kept out of Alpine's reactive data: a Chart wrapped in a proxy recurses without end on update().
    let chart = null;
    let retheme = null;

    return {
    async init() {
        const Chart = await loadChart();
        const canvas = this.$refs.canvas;

        // The screen may have moved on while the library was on its way.
        if (! canvas?.isConnected) return;

        const palette = tokens();
        Chart.defaults.color = palette.muted;
        Chart.defaults.borderColor = palette.hairline;
        Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
        Chart.defaults.font.size = 12;
        Chart.defaults.animation.duration = 700;
        Chart.defaults.animation.easing = 'easeOutCubic';

        const height = canvas.parentElement?.clientHeight || 260;
        const datasets = config.data?.datasets ?? [];

        // `lqFill: 'orange'` paints bars with the Liquify Orange fading downwards.
        datasets.forEach((dataset) => {
            if (dataset.lqFill === 'orange') {
                const gradient = canvas.getContext('2d').createLinearGradient(0, 0, 0, height);
                gradient.addColorStop(0, 'rgba(255, 122, 61, 1)');
                gradient.addColorStop(1, 'rgba(255, 107, 44, 0.28)');
                dataset.backgroundColor = gradient;
                dataset.hoverBackgroundColor = palette.orange;
            }
        });

        const money = datasets.find((dataset) => dataset.lqPrefix !== undefined);

        config.options = config.options ?? {};
        config.options.plugins = config.options.plugins ?? {};
        config.options.plugins.tooltip = {
            backgroundColor: 'rgba(24, 22, 22, 0.94)',
            titleColor: '#fafaf9',
            bodyColor: '#e7e5e4',
            padding: 10,
            cornerRadius: 10,
            displayColors: false,
            titleFont: { weight: '600' },
            ...(money ? { callbacks: { label: (item) => money.lqPrefix + Number(item.parsed.y ?? item.parsed).toLocaleString('en-GB') } } : {}),
            ...(config.options.plugins.tooltip ?? {}),
        };

        if (money && config.options.scales?.y) {
            config.options.scales.y.ticks = {
                ...(config.options.scales.y.ticks ?? {}),
                callback: (value) => money.lqPrefix + (Math.abs(value) >= 1000 ? (value / 1000).toLocaleString('en-GB', { maximumFractionDigits: 1 }) + 'k' : value),
            };
        }

        chart = new Chart(canvas, config);

        retheme = () => {
            const next = tokens();
            chart.options.color = next.muted;
            chart.options.scales && Object.values(chart.options.scales).forEach((scale) => {
                scale.grid = { ...(scale.grid ?? {}), color: next.hairline };
                scale.ticks = { ...(scale.ticks ?? {}), color: next.muted };
            });
            chart.update();
        };

        window.addEventListener('theme-applied', retheme);
    },

    destroy() {
        window.removeEventListener('theme-applied', retheme);
        chart?.destroy();
        chart = null;
    },
    };
});

// Numbers count up once when a card appears ("12", "£1,500", "4 / 4" keeps its text).
Alpine.directive('count', (el) => {
    const text = el.textContent.trim();
    const match = text.match(/^(\D*)([\d,]+(?:\.\d+)?)(\D*)$/);

    if (! match || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    const [, prefix, raw, suffix] = match;
    const target = parseFloat(raw.replace(/,/g, ''));
    const decimals = (raw.split('.')[1] ?? '').length;

    if (! Number.isFinite(target) || target === 0) return;

    const format = (value) => prefix + value.toLocaleString('en-GB', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }) + suffix;
    const started = performance.now();
    const duration = 700;

    let shown = el.textContent;

    const tick = (now) => {
        // A live update wrote the new number meanwhile: it stays, the old one is not put back.
        if (el.textContent !== shown) return;

        const progress = Math.min(1, (now - started) / duration);
        const eased = 1 - Math.pow(1 - progress, 3);
        el.textContent = shown = progress < 1 ? format(target * eased) : text;
        if (progress < 1) requestAnimationFrame(tick);
    };

    requestAnimationFrame(tick);
});

// Copy a value and show "Copied" for a moment.
Alpine.data('copy', (value) => ({
    copied: false,
    copy() {
        const done = () => {
            this.copied = true;
            setTimeout(() => { this.copied = false; }, 1600);
        };

        // Where the clipboard API says no (an older browser, a page that lost focus), the old way still copies.
        const fallback = () => {
            const box = Object.assign(document.createElement('textarea'), { value, readOnly: true });
            box.style.cssText = 'position: fixed; top: 0; left: 0; opacity: 0;';
            document.body.append(box);
            box.select();

            try {
                if (document.execCommand('copy')) done();
            } finally {
                box.remove();
            }
        };

        navigator.clipboard ? navigator.clipboard.writeText(value).then(done, fallback) : fallback();
    },
}));

// After a page has settled, rows that arrive later (live updates) are highlighted.
const markReady = () => {
    document.body.classList.remove('lq-ready');
    setTimeout(() => document.body.classList.add('lq-ready'), 600);
};

// A row or two that join a live list are lit for a moment. A whole new set of rows
// (another page, view or sort) is not an arrival, and neither is the first load.
new MutationObserver((records) => {
    if (! document.body.classList.contains('lq-ready')) return;

    const arrived = new Map();

    for (const record of records) {
        if (record.target instanceof Element && record.target.classList.contains('lq-live')) {
            const rows = arrived.get(record.target) ?? [];
            record.addedNodes.forEach((node) => node instanceof Element && rows.push(node));
            arrived.set(record.target, rows);
        }
    }

    arrived.forEach((rows, list) => {
        if (rows.length > 0 && rows.length <= 3 && list.children.length > rows.length) {
            rows.forEach((row) => row.classList.add('lq-arrived'));
        }
    });
}).observe(document.documentElement, { childList: true, subtree: true });

document.addEventListener('livewire:navigated', () => {
    Alpine.store('theme').apply();
    markReady();
});

// ---------------------------------------------------------------- alerts
// A new bell entry plays a short chime and, while nobody is looking at LiquifyCRM, shows on the desktop.
// Both are a choice of this computer (kept in localStorage, like the theme). The desktop notification is
// drawn by the service worker (public/sw.js), which also brings the app forward when it is clicked.
const remembered = (key) => {
    try {
        return localStorage.getItem(key);
    } catch (error) {
        return null;
    }
};

const remember = (key, value) => {
    try {
        value === null ? localStorage.removeItem(key) : localStorage.setItem(key, value);
    } catch (error) {
        // Private window or blocked storage: the choice lasts until the page closes.
    }
};

const worker = 'serviceWorker' in navigator && window.isSecureContext;
let audio = null;

// Two soft rising notes, made in the browser: no sound file to download. Browsers keep a page quiet
// until the person has clicked or typed on it once, so it says whether the chime can be heard.
const chime = () => {
    const Context = window.AudioContext ?? window.webkitAudioContext;

    if (! Context || navigator.userActivation?.hasBeenActive === false) return false;

    audio ??= new Context();
    if (audio.state === 'suspended') audio.resume().catch(() => {});

    const start = audio.currentTime + 0.02;

    [[784, 0], [1175, 0.12]].forEach(([frequency, delay]) => {
        const tone = audio.createOscillator();
        const level = audio.createGain();

        tone.type = 'sine';
        tone.frequency.value = frequency;
        level.gain.setValueAtTime(0.0001, start + delay);
        level.gain.exponentialRampToValueAtTime(0.18, start + delay + 0.02);
        level.gain.exponentialRampToValueAtTime(0.0001, start + delay + 0.7);
        tone.connect(level).connect(audio.destination);
        tone.start(start + delay);
        tone.stop(start + delay + 0.75);
    });

    return true;
};

const tellWorker = (message) => {
    if (! worker) return;

    navigator.serviceWorker.ready.then((registration) => registration.active?.postMessage(message)).catch(() => {});
};

Alpine.store('alerts', {
    sound: true,
    desktop: false,
    // 'default' (not asked yet), 'granted', 'denied' or 'unsupported'.
    permission: 'unsupported',

    init() {
        this.sound = remembered('lq_sound') !== 'off';
        this.permission = worker && 'Notification' in window ? Notification.permission : 'unsupported';
        this.desktop = this.permission === 'granted' && remembered('lq_desktop') !== 'off';
        tellWorker({ type: 'lq-prefs', sound: this.sound });
    },

    get listening() {
        return this.sound || this.desktop;
    },

    // Alerts also with LiquifyCRM closed: this browser can take pushes and the server sends them.
    get whenClosed() {
        return worker && 'PushManager' in window && pushKey() !== null;
    },

    toggleSound() {
        this.sound = ! this.sound;
        remember('lq_sound', this.sound ? null : 'off');
        tellWorker({ type: 'lq-prefs', sound: this.sound });

        // A sample, so the person hears what it will sound like.
        if (this.sound) chime();
    },

    async toggleDesktop() {
        if (this.permission === 'unsupported' || this.permission === 'denied') return;

        if (this.permission === 'default') {
            this.permission = await Notification.requestPermission();
            this.desktop = this.permission === 'granted';
            remember('lq_desktop', null);

            if (this.desktop) {
                tellWorker({ type: 'lq-alert', always: true, silent: true, items: [{ id: 'lq-sample', title: 'Desktop alerts are on', body: 'New leads and assignments will show here, also while LiquifyCRM is closed.', url: location.pathname }] });
                syncPush();
            }

            return;
        }

        this.desktop = ! this.desktop;
        remember('lq_desktop', this.desktop ? null : 'off');
        syncPush();
    },
});

// Desktop alerts with LiquifyCRM closed (Web Push). While desktop alerts are on, this browser keeps a subscription
// with its maker's push service and gives the server its address, every time a page opens, so a browser follows
// whoever signed in last; turning them off takes it back. The key is on the page (<meta name="lq-push-key">) once
// an Admin has applied the update; without it the alerts still come while a page is open.
const pushKey = () => document.querySelector('meta[name="lq-push-key"]')?.content || null;

const keyBytes = (key) => {
    const base64 = (key + '='.repeat((4 - (key.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');

    return Uint8Array.from(atob(base64), (character) => character.charCodeAt(0));
};

const sameKey = (buffer, key) => {
    if (! buffer) return false;

    const held = new Uint8Array(buffer);
    const wanted = keyBytes(key);

    return held.length === wanted.length && held.every((byte, index) => byte === wanted[index]);
};

const tellServer = (method, subscription) => fetch('/push/subscription', {
    method,
    credentials: 'same-origin',
    headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
    },
    body: JSON.stringify(subscription.toJSON()),
});

async function syncPush() {
    const key = pushKey();

    if (! worker || ! key || ! ('PushManager' in window)) return;

    try {
        const registration = await navigator.serviceWorker.ready;
        let subscription = await registration.pushManager.getSubscription();

        if (! Alpine.store('alerts').desktop) {
            if (subscription) {
                await tellServer('DELETE', subscription).catch(() => null);
                await subscription.unsubscribe();
            }

            return;
        }

        // Made with an older key pair: the push service would refuse every alert.
        if (subscription && ! sameKey(subscription.options?.applicationServerKey, key)) {
            await subscription.unsubscribe();
            subscription = null;
        }

        subscription ??= await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(key) });
        await tellServer('POST', subscription);
    } catch (error) {
        // No push service here (some browsers, private windows): the alerts still come while a page is open.
    }
}

// One chime and one desktop notification per entry, however many tabs are open: the first tab to hear it takes it.
const claim = (id) => {
    try {
        const taken = JSON.parse(localStorage.getItem('lq_alerted') ?? '[]');

        if (taken.includes(id)) return false;

        localStorage.setItem('lq_alerted', JSON.stringify([id, ...taken].slice(0, 40)));
    } catch (error) {
        // No storage: every tab sounds, which is still better than none.
    }

    return true;
};

// New bell entries (NotificationBell::tick).
window.addEventListener('lq-alert', (event) => {
    const items = (event.detail?.items ?? []).filter((item) => claim(item.id));
    const alerts = Alpine.store('alerts');

    if (items.length === 0) return;

    const sounded = alerts.sound && chime();

    // With the chime heard (or the sound turned off) the desktop notification stays quiet; else the system's own sound plays.
    if (alerts.desktop && Notification.permission === 'granted') {
        tellWorker({ type: 'lq-alert', items, silent: sounded || ! alerts.sound });
    }
});

// ------------------------------------------------------------ install app
// Chrome offers to install LiquifyCRM as an app (its own window, on the desktop and taskbar) once it has seen
// the manifest and the service worker. The offer is kept here for "Install app" in the account menu and the
// bell; it stays outside Alpine's reactive data, because a proxied event refuses prompt().
let installOffer = null;

Alpine.store('install', {
    available: false,

    async run() {
        const offer = installOffer;

        if (! offer) return;

        // An offer can be shown once; Chrome makes a new one if this one is turned down.
        installOffer = null;
        this.available = false;
        offer.prompt();
        await offer.userChoice.catch(() => null);
    },
});

window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    installOffer = event;
    Alpine.store('install').available = ! window.matchMedia('(display-mode: standalone)').matches;
});

window.addEventListener('appinstalled', () => {
    installOffer = null;
    Alpine.store('install').available = false;
    window.dispatchEvent(new CustomEvent('toast', { detail: { title: 'LiquifyCRM is installed', body: 'Open it from your desktop, taskbar or Start menu.' } }));
});

if (worker) {
    navigator.serviceWorker.register('/sw.js', { scope: '/', updateViaCache: 'none' }).catch(() => {});

    navigator.serviceWorker.addEventListener('message', (event) => {
        // A desktop notification was clicked: open its screen in this window.
        if (event.data?.type === 'lq-open' && typeof event.data.url === 'string') Livewire.navigate(event.data.url);

        // A push arrived (sw.js). One already shown on the desktop is not sounded again here; the bell looks at once.
        if (event.data?.type === 'lq-pushed') {
            if (event.data.shown && event.data.id) claim(String(event.data.id));
            window.dispatchEvent(new CustomEvent('lq-notified'));
        }
    });

    syncPush();
}

// ------------------------------------------------------------ live updates
// The hosting runs no daemons, so no websockets. Instead one small request asks every few seconds
// whether anything of this brand or for this person has changed (GET /api/live, see Live.php).
// Only when a stamp has moved do the screens reload their data: the roots marked `data-live-refresh`,
// and whoever listens for `lq-changed` (the board) or `lq-notified` (the bell).
// A tab in the background keeps asking, more slowly, while its alerts are on (sound or desktop), so a new
// lead is heard while the person works elsewhere; its screens reload only once it is looked at again.
const live = { token: null, brand: null, user: null, timer: null, busy: false, own: false, refreshed: 0, owed: false, input: Date.now() };

const LIVE_EVERY = 4000;
const LIVE_IDLE_EVERY = 12000;
const LIVE_HIDDEN_EVERY = 15000;
const LIVE_IDLE_AFTER = 180000;
const LIVE_REFRESH_GAP = 6000;

const refreshLive = () => {
    live.refreshed = Date.now();
    live.owed = false;
    window.dispatchEvent(new CustomEvent('lq-changed'));
    // The colon of wire:id is escaped for CSS, and the backslash once more for JavaScript: '[wire:id]' is not a selector.
    document.querySelectorAll('[wire\\:id][data-live-refresh]').forEach((root) => Livewire.find(root.getAttribute('wire:id'))?.$refresh());
};

const askLive = async () => {
    if (live.busy) return;

    live.busy = true;
    clearTimeout(live.timer);

    const page = document.querySelector('[data-live]');
    const token = page?.dataset.live ?? null;

    if (token !== null && (! document.hidden || Alpine.store('alerts').listening)) {
        // Another page (another brand, another person): count from the stamp it was drawn with. A page
        // fetched before it was shown (a hovered link waits up to 30 seconds, the back button longer)
        // then reloads its data at once when anything changed in between.
        if (token !== live.token) {
            const drawn = Number(page.dataset.liveAt);

            Object.assign(live, { token, brand: Number.isFinite(drawn) ? drawn : null, user: null, owed: false, refreshed: 0 });
        }

        // What this person just did is on their screen already: that change only moves the starting point.
        const own = live.own;
        live.own = false;

        try {
            const response = await fetch('/api/live?t=' + encodeURIComponent(token), { headers: { Accept: 'application/json' }, cache: 'no-store', credentials: 'omit' });

            if (response.ok && token === live.token) {
                const stamps = await response.json();
                const brandMoved = live.brand !== null && stamps.b !== live.brand;
                const userMoved = live.user !== null && stamps.u !== live.user;

                live.brand = stamps.b;
                live.user = stamps.u;

                if (userMoved) window.dispatchEvent(new CustomEvent('lq-notified'));
                if (brandMoved && ! own) live.owed = true;
            }
        } catch (error) {
            // Offline for a moment: the next round asks again.
        }

        // A screen that fails to reload must never stop the rounds (and the bell with them).
        try {
            if (! document.hidden && live.owed && Date.now() - live.refreshed >= LIVE_REFRESH_GAP) refreshLive();
        } catch (error) {
            console.error(error);
        }
    }

    live.busy = false;
    live.timer = setTimeout(askLive, document.hidden ? LIVE_HIDDEN_EVERY : (Date.now() - live.input > LIVE_IDLE_AFTER ? LIVE_IDLE_EVERY : LIVE_EVERY));

    // Something was done while this round was on its way: ask again, so its change is not taken for somebody else's.
    if (live.own) askLive();
};

// After a request this person caused (anything but a plain reload of data), the stamps are read again at once.
// What happens later because of it (a lead worked on after the answer) then counts as news and reloads the screen.
Livewire.hook('commit', ({ commit, succeed }) => {
    const acted = (commit.calls ?? []).some((call) => ! ['$refresh', 'tick', 'openLead', 'closeLead'].includes(call.method));

    succeed(() => {
        if (! acted) return;

        setTimeout(() => {
            live.own = true;
            askLive();
        }, 150);
    });
});

['pointerdown', 'keydown', 'wheel'].forEach((name) => window.addEventListener(name, () => { live.input = Date.now(); }, { passive: true, capture: true }));

// Back on this tab: look at once instead of waiting for the next round.
document.addEventListener('visibilitychange', () => { if (! document.hidden) askLive(); });
document.addEventListener('livewire:navigated', askLive);
// Keyboard shortcut for search (Ctrl K / ⌘K).
window.addEventListener('keydown', (event) => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        window.dispatchEvent(new CustomEvent('open-search'));
    }
});

Livewire.start();
