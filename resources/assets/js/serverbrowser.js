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
    // Manually controlled (not Bootstrap's 'hover focus'): the roster holds tee links the user opens in
    // new tabs, and the built-in 'focus' trigger fired focusout on ctrl/middle-click and slammed the
    // popover shut before the tab opened. A click toggles a roster open (pinning it); it closes only on an
    // outside click or Esc, so modifier-clicking a link inside it leaves it open.
    let openPopover = null;

    function closeOpenPopover() {
        if (openPopover) {
            openPopover.hide();
            openPopover = null;
        }
    }

    table.querySelectorAll('.server-player-count').forEach((trigger) => {
        const roster = trigger.parentElement.querySelector('.server-players');
        if (!roster) {
            return;
        }
        const popover = new Popover(trigger, {
            html: true,
            // we own this markup (Blade-escaped); disable the sanitizer so the tee <canvas> elements
            // and their data-tee attrs are not stripped
            sanitize: false,
            trigger: 'manual',
            container: 'body',
            customClass: 'server-roster-popover', // wider + multi-column (see app.scss)
            content: () => {
                // clone the live DOM nodes rather than innerHTML — a serialized <canvas> loses its
                // pixels. Drop the roster's d-none so the clone shows. Draw the tees onto the clone
                // right here: canvas bitmaps survive being moved into the tip, and because tee.js
                // caches the composed result this is instant on repeat opens and never leaves a
                // permanently-blank canvas. Only the opened server's sprites draw, never the page's rest.
                const clone = roster.cloneNode(true);
                clone.classList.remove('d-none');
                // flow big rosters into columns so they don't scroll forever. A multicol box is
                // shrink-to-fit, so it needs an explicit width to actually form columns.
                const count = clone.childElementCount;
                const cols = count > 24 ? 3 : (count > 8 ? 2 : 1);
                if (cols > 1) {
                    clone.style.columnCount = String(cols);
                    clone.style.columnGap = '16px';
                    clone.style.width = (cols * 176) + 'px';
                }
                renderAllTees(clone, { onlyVisible: false });
                return clone;
            },
        });

        // click toggles this roster; stopPropagation keeps the document handler below from
        // immediately treating the same click as an "outside" click
        trigger.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            if (openPopover === popover) {
                closeOpenPopover();
                return;
            }
            closeOpenPopover();
            popover.show();
            openPopover = popover;
        });
    });

    // close the open roster on an outside click; a click inside it (including a ctrl/middle-click on a
    // tee link that opens a new tab) is left alone, so the roster stays pinned
    document.addEventListener('click', (event) => {
        if (!openPopover) {
            return;
        }
        if (event.target.closest('.server-roster-popover')) {
            return;
        }
        closeOpenPopover();
    });

    // Esc closes any open roster
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeOpenPopover();
        }
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
