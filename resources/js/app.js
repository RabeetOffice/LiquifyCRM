import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';
import Chart from 'chart.js/auto';

window.Chart = Chart;

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

Alpine.data('chart', (config) => ({
    chart: null,

    init() {
        const palette = tokens();
        Chart.defaults.color = palette.muted;
        Chart.defaults.borderColor = palette.hairline;
        Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
        Chart.defaults.font.size = 12;
        Chart.defaults.animation.duration = 700;
        Chart.defaults.animation.easing = 'easeOutCubic';

        const canvas = this.$refs.canvas;
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

        this.chart = new Chart(canvas, config);

        window.addEventListener('theme-applied', () => {
            const next = tokens();
            this.chart.options.color = next.muted;
            this.chart.options.scales && Object.values(this.chart.options.scales).forEach((scale) => {
                scale.grid = { ...(scale.grid ?? {}), color: next.hairline };
                scale.ticks = { ...(scale.ticks ?? {}), color: next.muted };
            });
            this.chart.update();
        });
    },

    destroy() {
        this.chart?.destroy();
    },
}));

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
    setTimeout(() => document.body.classList.add('lq-ready'), 900);
};

document.addEventListener('livewire:navigated', () => {
    Alpine.store('theme').apply();
    markReady();
});
// Keyboard shortcut for search (Ctrl K / ⌘K).
window.addEventListener('keydown', (event) => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        window.dispatchEvent(new CustomEvent('open-search'));
    }
});

Livewire.start();
