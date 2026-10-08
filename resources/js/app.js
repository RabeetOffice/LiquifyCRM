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

        this.chart = new Chart(this.$refs.canvas, config);

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

// Keyboard shortcut for search (Ctrl K / ⌘K).
window.addEventListener('keydown', (event) => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        window.dispatchEvent(new CustomEvent('open-search'));
    }
});

Livewire.start();
