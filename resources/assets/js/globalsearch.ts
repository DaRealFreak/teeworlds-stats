// Global search: a navbar-wide search box backed by /search/global. As the user types, a
// debounced fetch returns matches grouped by entity type (players/clans/servers/maps/mods);
// each renders as a direct link to its detail page. Keyboard: ↑/↓ across the flattened result
// list, Enter follows the active (or first) hit, Esc closes. '/' and Ctrl/Cmd+K focus the box
// from anywhere (ignored while typing in another field). Names are user-controlled (from
// Teeworlds), so every label is set via textContent — never innerHTML — to avoid XSS.
import { renderTee } from './tee';

interface SearchResult {
    url: string;
    name: string;
    // present only for players: the App\Utility\TeeSkin descriptor (null for a skinless sighting)
    tee?: Record<string, unknown> | null;
}

// what leads each result row: a rendered tee sprite (players), an avatar image (clans), or a
// Font Awesome icon (servers/maps/mods)
type Leading = 'tee' | 'avatar' | 'icon';

interface SearchGroup {
    key: string;
    label: string;
    leading: Leading;
    icon?: string;
}

const DEBOUNCE_MS = 200;
const MIN_LENGTH = 2;

// display order + label + leading visual per result group (Font Awesome 4.7 icons for the icon ones)
const GROUPS: SearchGroup[] = [
    { key: 'players', label: 'Players', leading: 'tee' },
    { key: 'clans', label: 'Clans', leading: 'avatar' },
    { key: 'servers', label: 'Servers', leading: 'icon', icon: 'fa-server' },
    { key: 'maps', label: 'Maps', leading: 'icon', icon: 'fa-map-o' },
    { key: 'mods', label: 'Mods', leading: 'icon', icon: 'fa-gamepad' },
];

// build the leading visual for a result row: a tee canvas for players (the generic avatar stands in
// for a skinless sighting), the clan avatar image for clans, or a Font Awesome icon otherwise.
// `avatars` maps a group key to its image URL (resolved from data-* attributes so asset() base paths
// are honoured).
function buildLeadingVisual(group: SearchGroup, result: SearchResult, avatars: Record<string, string>): HTMLElement {
    if (group.leading === 'tee' && result.tee) {
        const canvas = document.createElement('canvas');
        canvas.className = 'global-search__item-tee';
        canvas.width = 24;
        canvas.height = 24;
        canvas.setAttribute('data-tee', JSON.stringify(result.tee));
        renderTee(canvas);
        return canvas;
    }
    if (group.leading === 'tee' || group.leading === 'avatar') {
        const img = document.createElement('img');
        img.className = 'global-search__item-avatar';
        img.src = avatars[group.key] ?? '';
        img.alt = '';
        return img;
    }
    const icon = document.createElement('i');
    icon.className = 'fa ' + (group.icon ?? '') + ' global-search__item-icon';
    icon.setAttribute('aria-hidden', 'true');
    return icon;
}

function debounce<Args extends unknown[]>(fn: (...args: Args) => void, wait: number): (...args: Args) => void {
    let timer: ReturnType<typeof setTimeout>;
    return function (this: unknown, ...args: Args) {
        clearTimeout(timer);
        timer = setTimeout(() => fn.apply(this, args), wait);
    };
}

document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('global_search_input') as HTMLInputElement | null;
    const menu = document.getElementById('global_search_menu');
    if (!input || !menu) {
        return;
    }
    const form = input.closest('.global-search');
    const url = input.getAttribute('data-global-search') ?? '';
    // per-group fallback/avatar images, resolved from the input's data-* attributes so the app's
    // asset() base path is honoured (clans show the tee-hut; skinless players the generic avatar)
    const avatars: Record<string, string> = {
        players: input.getAttribute('data-player-fallback') ?? '',
        clans: input.getAttribute('data-clan-avatar') ?? '',
    };

    // flattened list of the currently-rendered result anchors, for ↑/↓ navigation
    let items: HTMLAnchorElement[] = [];
    let activeIndex = -1;

    function close() {
        menu!.hidden = true;
        menu!.innerHTML = '';
        items = [];
        activeIndex = -1;
        // collapse the mobile-expanded box back to just the magnifier
        if (form) {
            form.classList.remove('global-search--open');
        }
    }

    function setActive(index: number) {
        const prev = items[activeIndex];
        if (prev) {
            prev.classList.remove('is-active');
        }
        activeIndex = index;
        const next = items[activeIndex];
        if (next) {
            next.classList.add('is-active');
            next.scrollIntoView({ block: 'nearest' });
        }
    }

    function render(data: Record<string, SearchResult[] | undefined>) {
        menu!.innerHTML = '';
        items = [];
        activeIndex = -1;

        GROUPS.forEach((group) => {
            const raw = data[group.key];
            const results = Array.isArray(raw) ? raw : [];
            if (!results.length) {
                return;
            }

            const heading = document.createElement('li');
            heading.className = 'global-search__group';
            heading.textContent = group.label;
            menu!.appendChild(heading);

            results.forEach((result) => {
                const li = document.createElement('li');
                const a = document.createElement('a');
                a.className = 'global-search__item';
                a.href = result.url;

                a.appendChild(buildLeadingVisual(group, result, avatars));

                const label = document.createElement('span');
                label.className = 'global-search__item-name';
                // user-controlled name → textContent, never innerHTML
                label.textContent = result.name;
                a.appendChild(label);

                li.appendChild(a);
                menu!.appendChild(li);
                items.push(a);
            });
        });

        if (!items.length) {
            const empty = document.createElement('li');
            empty.className = 'global-search__empty';
            empty.textContent = 'No matches';
            menu!.appendChild(empty);
        }

        menu!.hidden = false;
    }

    const fetchResults = debounce(function () {
        const term = input.value.trim();
        if (term.length < MIN_LENGTH) {
            close();
            return;
        }
        fetch(url + '?term=' + encodeURIComponent(term), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((data) => (data ? render(data) : close()))
            .catch(() => close());
    }, DEBOUNCE_MS);

    input.addEventListener('input', fetchResults);

    input.addEventListener('keydown', function (event) {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            if (items.length) {
                setActive((activeIndex + 1) % items.length);
            }
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            if (items.length) {
                setActive((activeIndex - 1 + items.length) % items.length);
            }
        } else if (event.key === 'Enter') {
            // follow the highlighted hit, or the first one if none is highlighted
            const target = activeIndex >= 0 ? items[activeIndex] : items[0];
            if (target) {
                event.preventDefault();
                window.location.href = target.href;
            }
        } else if (event.key === 'Escape') {
            close();
            input.blur();
        }
    });

    // click-away closes the dropdown
    document.addEventListener('click', function (event) {
        if (form && !form.contains(event.target as Node)) {
            close();
        }
    });

    // '/' and Ctrl/Cmd+K focus the box from anywhere, unless already typing in a field
    document.addEventListener('keydown', function (event) {
        const target = event.target;
        const typing =
            target instanceof HTMLElement && (target.matches('input, textarea, select') || target.isContentEditable);

        const slash = event.key === '/' && !typing;
        const cmdK = (event.metaKey || event.ctrlKey) && (event.key === 'k' || event.key === 'K');
        if (slash || cmdK) {
            event.preventDefault();
            if (form) {
                form.classList.add('global-search--open');
            }
            input.focus();
            input.select();
        }
    });

    // mobile: the magnifier toggles the collapsed box open (harmless on desktop — just focuses)
    const icon = form ? form.querySelector('.global-search__icon') : null;
    if (icon) {
        icon.addEventListener('click', function () {
            form?.classList.add('global-search--open');
            input.focus();
        });
    }
});

export {};
