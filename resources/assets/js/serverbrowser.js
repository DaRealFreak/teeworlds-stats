// Server browser page: client-side filtering + a hover popover listing the players
// currently on each server. Vanilla JS (no jQuery), runs only on the browser page.
import { Popover } from 'bootstrap';
import { renderAllTees } from './tee';

document.addEventListener('DOMContentLoaded', function () {
    const table = document.getElementById('server_browser_table');
    if (!table) {
        return; // not the server browser page
    }

    // ---- player-count popovers: each row's hidden .server-players carries the roster + tee canvases ----
    table.querySelectorAll('.server-player-count').forEach((trigger) => {
        const roster = trigger.parentElement.querySelector('.server-players');
        if (!roster) {
            return;
        }
        // eslint-disable-next-line no-new
        new Popover(trigger, {
            html: true,
            // we own this markup (Blade-escaped); disable the sanitizer so the tee <canvas> elements
            // and their data-tee attrs are not stripped
            sanitize: false,
            trigger: 'hover focus',
            container: 'body',
            // clone the live DOM nodes rather than innerHTML: a serialized <canvas> loses its pixels,
            // so we pass the elements and render them once the tip is in the DOM (below). Drop the
            // roster's d-none (it's hidden on the page) so the clone shows inside the popover.
            content: () => {
                const clone = roster.cloneNode(true);
                clone.classList.remove('d-none');
                return clone;
            },
        });
        // draw the cloned roster's tees lazily — only the hovered server's ≤max-clients sprites,
        // never the thousands of hidden ones on the page
        trigger.addEventListener('inserted.bs.popover', () => {
            const tip = document.querySelector('.popover');
            if (tip) {
                renderAllTees(tip, { onlyVisible: false });
            }
        });
    });

    // ---- click/Enter on an address copies ip:port for the in-game connect field ----
    table.querySelectorAll('.server-connect').forEach((el) => {
        const label = el.innerHTML;
        const copy = () => {
            // navigator.clipboard is undefined outside secure (HTTPS) contexts; skip rather than throw
            if (!navigator.clipboard) {
                return;
            }
            navigator.clipboard.writeText(el.dataset.connect || '').then(() => {
                el.textContent = 'Copied!';
                window.setTimeout(() => { el.innerHTML = label; }, 1200);
            }).catch(() => {
                el.innerHTML = label;
            });
        };
        el.addEventListener('click', copy);
        el.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                copy();
            }
        });
    });

    // ---- client-side filtering ----
    const nameInput = document.getElementById('filter_name');
    const modSelect = document.getElementById('filter_mod');
    const mapSelect = document.getElementById('filter_map');
    const typeSelect = document.getElementById('filter_type');
    const hideEmpty = document.getElementById('filter_hide_empty');

    if (!nameInput || !modSelect || !mapSelect || !hideEmpty || !typeSelect) {
        return;
    }

    const rows = Array.from(table.querySelectorAll('tbody tr'));

    function applyFilters() {
        const name = nameInput.value.trim().toLowerCase();
        const mod = modSelect.value;
        const map = mapSelect.value;
        const type = typeSelect.value;
        const empty = hideEmpty.checked;

        rows.forEach((row) => {
            const serverMatches = !name || (row.dataset.name || '').includes(name);
            const playerMatches = !!name && (row.dataset.playerNames || '').includes(name);
            const matchesMod = !mod || row.dataset.mod === mod;
            const matchesMap = !map || row.dataset.map === map;
            const matchesType = !type || row.dataset.flavor === type;
            const matchesEmpty = !empty || row.dataset.players !== '0';

            row.hidden = !((serverMatches || playerMatches) && matchesMod && matchesMap && matchesEmpty && matchesType);

            // light up the players column whenever the term matched at least one player name
            // (independent of whether the server name also matched)
            const playersCell = row.querySelector('.players-cell');
            if (playersCell) {
                playersCell.classList.toggle('players-cell--match', playerMatches);
            }
        });
    }

    [nameInput, modSelect, mapSelect, typeSelect].forEach((el) => el.addEventListener('input', applyFilters));
    hideEmpty.addEventListener('change', applyFilters);
});
