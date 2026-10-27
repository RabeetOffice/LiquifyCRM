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

// Every time zone ({value, label, hint, search}), fetched once per page the first time a search needs it
// (the clocks' +, a follow-up's time zone) instead of riding along with every page.
let timeZones = null;
const loadTimeZones = () => {
    timeZones ??= fetch('/time-zones', { headers: { Accept: 'application/json' } })
        .then((response) => (response.ok ? response.json() : []))
        .catch(() => []);

    return timeZones;
};

// Words typed into a time zone search, matched against a city, its country and other names ("uk", "pakistan").
// A whole word ("uk" for the United Kingdom) comes before a part of one (Auckland), then a city that starts with it.
const zoneRank = (zone, first) => (zone.search.split(/\s+/).includes(first) ? 2 : 0) + (zone.label.toLowerCase().startsWith(first) ? 1 : 0);

const searchZones = (zones, query, limit = 30) => {
    const words = query.trim().toLowerCase().split(/\s+/).filter(Boolean);

    return zones.filter((zone) => words.every((word) => zone.search.includes(word))).sort((a, b) => zoneRank(b, words[0] ?? '') - zoneRank(a, words[0] ?? '')).slice(0, limit);
};

// Clocks in the top bar: this computer's time, the brand's, and the time zones this person added (a brand's from
// the list, any other by search). Four clocks at most. The added ones are a choice of this computer, like sound
// and desktop alerts. Times are 12-hour with AM/PM. Ticks on the minute.
Alpine.data('clocks', ({ brand, choices }) => ({
    open: false,
    now: new Date(),
    // Known before the first paint, so your clock never shows UTC for a moment.
    own: (() => {
        try {
            return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
        } catch (error) {
            return 'UTC';
        }
    })(),
    extra: [],
    brand,
    choices,
    query: '',
    zones: [],
    loading: false,
    full: false,
    timer: null,

    init() {
        try {
            const saved = JSON.parse(localStorage.getItem('lq_clocks') || '[]');
            this.extra = Array.isArray(saved) ? saved.filter((zone) => typeof zone === 'string' && this.valid(zone)) : [];
        } catch (error) {
            this.extra = [];
        }

        this.extra = this.extra.filter((zone) => zone !== this.own && zone !== this.brand?.zone).slice(0, this.room);

        const tick = () => {
            this.now = new Date();
            this.timer = setTimeout(tick, 60000 - (Date.now() % 60000) + 50);
        };
        tick();
    },

    destroy() {
        clearTimeout(this.timer);
    },

    // Your clock, the brand's when it is another time zone, then the added ones: four in all.
    get room() {
        return 4 - 1 - (this.brand && this.brand.zone !== this.own ? 1 : 0);
    },

    // Your own and the brand's clock are in the page already (topbar); these are the ones added with +.
    get extras() {
        return this.extra
            .filter((zone) => zone !== this.own && zone !== this.brand?.zone)
            .map((zone) => ({ zone, label: this.city(zone), title: this.choices.find((choice) => choice.zone === zone)?.brands || this.city(zone) }));
    },

    // Nothing typed: the brands' time zones and the clocks already added. Typed: every time zone that matches.
    get results() {
        const brands = (zone) => this.choices.find((choice) => choice.zone === zone)?.brands ?? '';

        if (this.query.trim() === '') {
            const added = this.extra.filter((zone) => ! this.choices.some((choice) => choice.zone === zone)).map((zone) => ({ zone, city: this.city(zone), sub: '' }));

            return [...this.choices.map((choice) => ({ zone: choice.zone, city: choice.city, sub: choice.brands })), ...added];
        }

        return searchZones(this.zones, this.query).map((zone) => ({ zone: zone.value, city: zone.label, sub: [brands(zone.value), zone.hint].filter(Boolean).join(' · ') }));
    },

    async show() {
        this.open = ! this.open;
        this.full = false;

        if (this.open) {
            this.$nextTick(() => this.$refs.search?.focus());

            if (this.zones.length === 0) {
                this.loading = true;
                this.zones = await loadTimeZones();
                this.loading = false;
            }
        }
    },

    valid(zone) {
        try {
            new Intl.DateTimeFormat('en-US', { timeZone: zone });

            return true;
        } catch (error) {
            return false;
        }
    },

    time(zone) {
        try {
            return new Intl.DateTimeFormat('en-US', { hour: 'numeric', minute: '2-digit', hour12: true, timeZone: zone }).format(this.now);
        } catch (error) {
            return '--:--';
        }
    },

    // "Tue" when it is already (or still) another day there.
    day(zone) {
        try {
            const weekday = (where) => new Intl.DateTimeFormat('en-GB', { weekday: 'short', timeZone: where }).format(this.now);

            return weekday(zone) !== weekday(this.own) ? weekday(zone) : '';
        } catch (error) {
            return '';
        }
    },

    city(zone) {
        return String(zone).split('/').pop().replace(/_/g, ' ');
    },

    chosen(zone) {
        return this.extra.includes(zone) || zone === this.own || zone === this.brand?.zone;
    },

    // Your own and the brand's clock are always there; the others come and go, four clocks at most.
    toggle(zone) {
        if (zone === this.own || zone === this.brand?.zone) return;

        if (this.extra.includes(zone)) {
            this.extra = this.extra.filter((item) => item !== zone);
        } else if (this.extra.length >= this.room) {
            this.full = true;

            return;
        } else {
            this.extra = [...this.extra, zone];
        }

        this.full = false;

        try {
            localStorage.setItem('lq_clocks', JSON.stringify(this.extra));
        } catch (error) {
            // Private window: the clocks still change for this page.
        }
    },
}));

// Under a follow-up's time zone: the time typed, read in that zone, as it falls in the viewer's own ("5:00 PM in
// London is 9:00 PM your time"). Reads $wire.followUpAt and $wire.followUpZone as they change in the browser.
Alpine.data('zoneHint', ({ own }) => ({
    get text() {
        const typed = String(this.$wire.followUpAt ?? '');
        const zone = String(this.$wire.followUpZone ?? '');
        const match = typed.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/);

        if (! match || zone === '' || zone === own) return 'Your own time. Choose the client\'s if they said a time where they are.';

        try {
            // How far a zone's clock is from UTC at a moment, in milliseconds.
            const offset = (moment, where) => {
                const parts = Object.fromEntries(new Intl.DateTimeFormat('en-US', { timeZone: where, hourCycle: 'h23', year: 'numeric', month: 'numeric', day: 'numeric', hour: 'numeric', minute: 'numeric' })
                    .formatToParts(new Date(moment)).map((part) => [part.type, Number(part.value)]));

                return Date.UTC(parts.year, parts.month - 1, parts.day, parts.hour, parts.minute) - moment;
            };
            const wall = Date.UTC(+match[1], +match[2] - 1, +match[3], +match[4], +match[5]);
            const moment = wall - offset(wall - offset(wall, zone), zone);
            const format = (where, withDay) => new Intl.DateTimeFormat('en-US', { timeZone: where, hour: 'numeric', minute: '2-digit', hour12: true, ...(withDay ? { weekday: 'short', day: 'numeric', month: 'short' } : {}) }).format(new Date(moment));
            const sameDay = new Intl.DateTimeFormat('en-GB', { timeZone: zone, dateStyle: 'short' }).format(new Date(moment)) === new Intl.DateTimeFormat('en-GB', { timeZone: own, dateStyle: 'short' }).format(new Date(moment));
            const city = (where) => where.split('/').pop().replace(/_/g, ' ');

            return `${format(zone, false)} in ${city(zone)} is ${format(own, ! sameDay)} your time. The reminders come 10, 5 and 1 minutes before.`;
        } catch (error) {
            return '';
        }
    },
}));

// A choice from a long list with a search box (<x-ui.picker>). The list stays out of Alpine's
// reactive data: four hundred time zones need no watching, only filtering.
Alpine.data('picker', ({ model, options, pinned, remote = false }) => ({
    open: false,
    query: '',
    active: 0,
    // Moves when the whole list of a `remote` picker arrives, so what is shown is worked out again.
    loaded: 0,

    get value() {
        return this.$wire[model];
    },

    get current() {
        this.loaded;

        return options.find((option) => option.value === this.value) ?? null;
    },

    get shown() {
        this.loaded;
        const words = this.query.trim().toLowerCase().split(/\s+/).filter(Boolean);

        // Nothing typed: the usual choices and the current one, not the whole list.
        if (words.length === 0) {
            return options.filter((option) => pinned.includes(option.value) || option.value === this.value);
        }

        return options
            .filter((option) => words.every((word) => option.search.includes(word)))
            .sort((a, b) => zoneRank(b, words[0]) - zoneRank(a, words[0]))
            .slice(0, 40);
    },

    toggle() {
        this.open ? this.close() : this.show();
    },

    show() {
        if (remote && this.loaded === 0) {
            this.loaded = -1;
            loadTimeZones().then((zones) => {
                if (zones.length > 0) options = zones;
                this.loaded = 1;
            });
        }

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
Alpine.data('assignMenu', ({ people, teams = [], assign, send = null, unassign = null }) => ({
    open: false,
    query: '',
    place: '',
    lead: null,
    dept: null,
    owner: null,
    pending: {},
    people,
    teams,
    // Taking a lead off its owner, back to the queue (26 Oct 2026): offered where the screen passes `unassign`.
    canUnassign: unassign !== null,

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

    release() {
        const lead = this.lead;

        this.pending[lead] = 'Unassigned';
        this.close();

        Promise.resolve(unassign(lead)).finally(() => { delete this.pending[lead]; });
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

// ------------------------------------------------------------ drafts
// A note being written is kept on this computer as it is typed (user report, 27 Oct 2026: notes were lost when the
// page loaded again, after an update or a sign-out, or when the slide-over closed). It comes back when the same
// lead's note box is drawn again, and goes once the note is saved or its words are taken out. One entry per person
// and lead, dropped after a week, and all of them when the person signs out.
const DRAFTS = 'lq_draft:';
const DRAFT_DAYS = 7;

const drafts = {
    read(key) {
        try {
            const kept = JSON.parse(localStorage.getItem(DRAFTS + key) ?? 'null');

            return typeof kept?.text === 'string' ? kept.text : '';
        } catch (error) {
            return '';
        }
    },

    write(key, text) {
        try {
            if (String(text ?? '').trim() === '') localStorage.removeItem(DRAFTS + key);
            else localStorage.setItem(DRAFTS + key, JSON.stringify({ text, at: Date.now() }));
        } catch (error) {
            // Storage full or turned off: the box still works, only without the safety net.
        }
    },

    // Every draft, or only those older than a number of days.
    forget(days = 0) {
        try {
            Object.keys(localStorage).filter((name) => name.startsWith(DRAFTS)).forEach((name) => {
                const at = Number(JSON.parse(localStorage.getItem(name) ?? 'null')?.at ?? 0);

                if (days === 0 || Date.now() - at > days * 86400000) localStorage.removeItem(name);
            });
        } catch (error) {
            // Nothing to tidy.
        }
    },
};

drafts.forget(DRAFT_DAYS);

document.addEventListener('submit', (event) => {
    if (event.target instanceof HTMLFormElement && new URL(event.target.action, location.href).pathname === '/logout') drafts.forget();
});

// Around a box bound to a Livewire property: draft({ property: 'note', key: 'note:12:345' }). The box itself carries
// data-draft, so a page with nothing else typed may load again (its words come back).
Alpine.data('draft', ({ property, key }) => ({
    restored: false,

    init() {
        const kept = drafts.read(key);

        if (kept !== '' && String(this.$wire[property] ?? '') === '') {
            this.$wire[property] = kept;
            this.restored = true;
        }

        this.$watch('$wire.' + property, (text) => {
            drafts.write(key, text);

            if (String(text ?? '') === '') this.restored = false;
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
// Chats have their own stamp (20 Oct 2026): a message, or Facebook saying one was delivered or read, reloads only
// the roots marked data-live-refresh="chat" (the Inbox, a chat, a lead's panel) and the menu's counts, not every
// screen of the brand.
const live = { token: null, brand: null, chat: null, user: null, timer: null, busy: false, own: false, refreshed: 0, chatRefreshed: 0, owed: false, owedChat: false, input: Date.now() };

const LIVE_EVERY = 4000;
const LIVE_IDLE_EVERY = 12000;
const LIVE_HIDDEN_EVERY = 15000;
const LIVE_IDLE_AFTER = 180000;
const LIVE_REFRESH_GAP = 6000;
const LIVE_CHAT_GAP = 1500;

// The menu's counts (Leads waiting, unread chats, clients waiting) are drawn with the page; when something
// changed they are read again, so they move without opening a screen (MenuBadgesController).
const badges = { busy: false, again: false };

const refreshBadges = async () => {
    const url = document.querySelector('[data-menu-badges]')?.dataset.menuBadges;

    if (! url) return;
    if (badges.busy) {
        badges.again = true;

        return;
    }

    badges.busy = true;

    try {
        const response = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' });

        if (response.ok) {
            const counts = await response.json();

            document.querySelectorAll('[data-badge-for]').forEach((badge) => {
                if (! (badge.dataset.badgeFor in counts)) return;

                const value = counts[badge.dataset.badgeFor] ?? '';
                const inner = badge.querySelector('.lq-badge');

                if (inner && inner.textContent !== value) {
                    inner.textContent = value;
                    badge.classList.remove('lq-side-badge-moved');
                    void badge.offsetWidth;
                    badge.classList.toggle('lq-side-badge-moved', value !== '');
                }

                badge.classList.toggle('lq-side-badge-none', value === '');
            });
        }
    } catch (error) {
        // Offline for a moment: the next change reads them again.
    }

    badges.busy = false;

    if (badges.again) {
        badges.again = false;
        refreshBadges();
    }
};

// The colon of wire:id is escaped for CSS, and the backslash once more for JavaScript: '[wire:id]' is not a selector.
const refreshRoots = (selector) => document.querySelectorAll(selector).forEach((root) => Livewire.find(root.getAttribute('wire:id'))?.$refresh());

const refreshLive = () => {
    const now = Date.now();

    if (live.owed) {
        live.refreshed = now;
        live.chatRefreshed = now;
        live.owed = false;
        live.owedChat = false;
        window.dispatchEvent(new CustomEvent('lq-changed'));
        refreshRoots('[wire\\:id][data-live-refresh]');
    } else if (live.owedChat) {
        live.chatRefreshed = now;
        live.owedChat = false;
        refreshRoots('[wire\\:id][data-live-refresh="chat"]');
    }

    refreshBadges();
};

// A new version (26 Oct 2026). The site deploys itself when new code is pushed, and a page opened before that
// still runs the old code: its next click could fail (the error's "Try again" then opened Livewire's own address,
// a 405) and new screens could miss their styles, until people refreshed by hand. /api/live names the code on the
// server (Live::build, `v`); when it is not the code this page was drawn with, the page loads again as soon as
// nobody loses work: at once while the tab is in the background and nothing is typed, as a full load on the next
// link instead of wire:navigate, or when a request of the old page fails. Until then a bar offers Reload.
const outdated = { known: false };

// Something typed and not saved yet: text in a field that no longer holds what the page was drawn with, or a
// file chosen. A choice in a list or a tick is quick to make again and does not hold the page back, and a box
// with a draft (a lead's note) gets its words back after the reload.
const typedSomething = () => [...document.querySelectorAll('input, textarea')].some((field) => {
    if (field.hasAttribute('data-draft')) return false;
    if (field.type === 'file') return field.files.length > 0;
    if (['hidden', 'checkbox', 'radio', 'button', 'submit', 'reset', 'range', 'color'].includes(field.type)) return false;

    return field.value !== '' && field.value !== field.defaultValue;
});

const reloadIfUnseen = () => {
    if (outdated.known && document.hidden && ! typedSomething()) location.reload();
};

const markOutdated = () => {
    if (outdated.known) return;

    outdated.known = true;
    document.querySelector('[data-new-version]')?.removeAttribute('hidden');
    reloadIfUnseen();
};

document.addEventListener('visibilitychange', reloadIfUnseen);

document.addEventListener('livewire:navigate', (event) => {
    if (! outdated.known) return;

    event.preventDefault();
    location.href = event.detail.url.href;
});

// A request that only reloads a screen's data: the live updates, wire:poll, a Refresh button.
const onlyRefreshes = (request) => {
    const actions = [...(request?.messages ?? [])].flatMap((message) => [...(message.actions ?? [])]);

    return actions.length > 0 && actions.every((action) => action.name === '$refresh');
};

Livewire.interceptRequest(({ request, onError }) => {
    onError(({ preventDefault }) => {
        // A screen reloaded in the background that fails says nothing (user report, 27 Oct 2026: an error screen came
        // up again and again while people worked). Nobody asked for that reload: a host that turned one request away, a
        // lead that moved to another team, a deploy under way. The next round tries again, and a click of the
        // person's own still shows what went wrong.
        if (onlyRefreshes(request)) {
            preventDefault();

            return;
        }

        if (! outdated.known) return;

        preventDefault();

        // Words typed in a box that keeps no draft would go with the reload: they stay, and the bar says why the
        // click did nothing (27 Oct 2026).
        if (typedSomething()) {
            const bar = document.querySelector('[data-new-version]');

            bar?.querySelector('[data-new-version-text]')?.setAttribute('hidden', '');
            bar?.querySelector('[data-new-version-failed]')?.removeAttribute('hidden');
            bar?.classList.add('lq-updatebar-failed');

            return;
        }

        location.reload();
    });
});

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
            const drawnChat = Number(page.dataset.liveChatAt);

            Object.assign(live, { token, brand: Number.isFinite(drawn) ? drawn : null, chat: Number.isFinite(drawnChat) ? drawnChat : null, user: null, owed: false, owedChat: false, refreshed: 0, chatRefreshed: 0 });
        }

        // What this person just did is on their screen already: that change only moves the starting point.
        const own = live.own;
        live.own = false;

        try {
            const response = await fetch('/api/live?t=' + encodeURIComponent(token), { headers: { Accept: 'application/json' }, cache: 'no-store', credentials: 'omit' });

            if (response.ok && token === live.token) {
                const stamps = await response.json();
                const brandMoved = live.brand !== null && stamps.b !== live.brand;
                const chatMoved = live.chat !== null && stamps.c !== undefined && stamps.c !== live.chat;
                const userMoved = live.user !== null && stamps.u !== live.user;

                live.brand = stamps.b;
                live.chat = stamps.c ?? live.chat;
                live.user = stamps.u;

                if (stamps.v !== undefined && page.dataset.liveBuild && stamps.v !== page.dataset.liveBuild) markOutdated();
                if (userMoved) window.dispatchEvent(new CustomEvent('lq-notified'));
                if (brandMoved && ! own) live.owed = true;
                if (chatMoved && ! own) live.owedChat = true;
            }
        } catch (error) {
            // Offline for a moment: the next round asks again.
        }

        // A screen that fails to reload must never stop the rounds (and the bell with them).
        try {
            const now = Date.now();
            const due = (live.owed && now - live.refreshed >= LIVE_REFRESH_GAP) || (! live.owed && live.owedChat && now - live.chatRefreshed >= LIVE_CHAT_GAP);

            if (! document.hidden && due) refreshLive();
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
            // What this person did may change a count in the menu (a lead taken, a chat read).
            refreshBadges();
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
