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

    const tick = (now) => {
        const progress = Math.min(1, (now - started) / duration);
        const eased = 1 - Math.pow(1 - progress, 3);
        el.textContent = progress < 1 ? format(target * eased) : text;
        if (progress < 1) requestAnimationFrame(tick);
    };

    requestAnimationFrame(tick);
});

// Copy a value and show "Copied" for a moment.
Alpine.data('copy', (value) => ({
    copied: false,
    copy() {
        navigator.clipboard?.writeText(value).then(() => {
            this.copied = true;
            setTimeout(() => { this.copied = false; }, 1600);
        });
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

// ------------------------------------------------------------ live updates
// The hosting runs no daemons, so no websockets. Instead one small request asks every few seconds
// whether anything of this brand or for this person has changed (GET /api/live, see Live.php).
// Only when a stamp has moved do the screens reload their data: the roots marked `data-live-refresh`,
// and whoever listens for `lq-changed` (the board) or `lq-notified` (the bell).
const live = { token: null, brand: null, user: null, timer: null, asked: 0, refreshed: 0, owed: false, own: 0, input: Date.now() };

const LIVE_EVERY = 4000;
const LIVE_IDLE_EVERY = 12000;
const LIVE_IDLE_AFTER = 180000;
const LIVE_REFRESH_GAP = 6000;

const refreshLive = () => {
    live.refreshed = Date.now();
    live.owed = false;
    window.dispatchEvent(new CustomEvent('lq-changed'));
    document.querySelectorAll('[wire\\:id][data-live-refresh]').forEach((root) => Livewire.find(root.getAttribute('wire:id'))?.$refresh());
};

const askLive = async () => {
    clearTimeout(live.timer);

    const token = document.querySelector('[data-live]')?.dataset.live ?? null;

    if (token !== null && ! document.hidden) {
        // Another page (another brand, another person): start counting from what it shows now.
        if (token !== live.token) {
            Object.assign(live, { token, brand: null, user: null, owed: false });
        }

        const askedBefore = live.asked;
        live.asked = Date.now();

        try {
            const response = await fetch('/api/live?t=' + encodeURIComponent(token), { headers: { Accept: 'application/json' }, cache: 'no-store', credentials: 'omit' });

            if (response.ok && token === live.token) {
                const stamps = await response.json();
                const brandMoved = live.brand !== null && stamps.b !== live.brand;
                const userMoved = live.user !== null && stamps.u !== live.user;

                live.brand = stamps.b;
                live.user = stamps.u;

                if (userMoved) window.dispatchEvent(new CustomEvent('lq-notified'));

                // What this person just did themselves is on their screen already.
                if (brandMoved && live.own < askedBefore) live.owed = true;
            }
        } catch (error) {
            // Offline for a moment: the next round asks again.
        }

        if (live.owed && Date.now() - live.refreshed >= LIVE_REFRESH_GAP) refreshLive();
    }

    live.timer = setTimeout(askLive, Date.now() - live.input > LIVE_IDLE_AFTER ? LIVE_IDLE_EVERY : LIVE_EVERY);
};

// A request this person caused (anything but a plain reload of data) marks the next change as their own.
Livewire.hook('commit', ({ commit, succeed }) => {
    const acted = (commit.calls ?? []).some((call) => ! ['$refresh', 'tick', 'openLead', 'closeLead'].includes(call.method));

    succeed(() => {
        if (acted) live.own = Date.now();
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
