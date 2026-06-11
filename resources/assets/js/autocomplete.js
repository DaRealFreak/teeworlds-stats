// Lightweight vanilla autocomplete, replacing jQuery UI's. Every
// <input data-autocomplete="<url>"> gets a debounced suggestion dropdown fed by
// that url's `?term=` parameter (the AjaxSearch endpoints return a JSON array of
// names). Keyboard: ↑/↓ to move, Enter to pick, Esc to close.
(function () {
    'use strict';

    const DEBOUNCE_MS = 200;
    const MIN_LENGTH = 1;

    function debounce(fn, wait) {
        let timer;
        return function (...args) {
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(this, args), wait);
        };
    }

    function attach(input) {
        const url = input.getAttribute('data-autocomplete');
        if (!url) {
            return;
        }

        // wrap the input so the menu can be absolutely positioned under it
        const wrap = document.createElement('div');
        wrap.className = 'autocomplete-wrap';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);

        const menu = document.createElement('ul');
        menu.className = 'autocomplete-menu';
        menu.hidden = true;
        wrap.appendChild(menu);

        let suggestions = [];
        let activeIndex = -1;

        function close() {
            menu.hidden = true;
            menu.innerHTML = '';
            suggestions = [];
            activeIndex = -1;
        }

        function choose(value) {
            input.value = value;
            close();
        }

        function render(values) {
            suggestions = values;
            activeIndex = -1;
            menu.innerHTML = '';

            if (!values.length) {
                close();
                return;
            }

            values.forEach((value) => {
                const item = document.createElement('li');
                item.className = 'autocomplete-item';
                item.textContent = value;
                // mousedown fires before the input's blur, so the pick still lands
                item.addEventListener('mousedown', (event) => {
                    event.preventDefault();
                    choose(value);
                });
                menu.appendChild(item);
            });
            menu.hidden = false;
        }

        function move(offset) {
            const items = menu.querySelectorAll('.autocomplete-item');
            if (!items.length) {
                return;
            }
            if (activeIndex >= 0) {
                items[activeIndex].classList.remove('is-active');
            }
            activeIndex = (activeIndex + offset + items.length) % items.length;
            items[activeIndex].classList.add('is-active');
        }

        const fetchSuggestions = debounce(function () {
            const term = input.value.trim();
            if (term.length < MIN_LENGTH) {
                close();
                return;
            }
            fetch(url + '?term=' + encodeURIComponent(term), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
                .then((response) => (response.ok ? response.json() : []))
                .then((data) => render(Array.isArray(data) ? data : []))
                .catch(() => close());
        }, DEBOUNCE_MS);

        input.setAttribute('autocomplete', 'off');
        input.addEventListener('input', fetchSuggestions);

        input.addEventListener('keydown', function (event) {
            if (menu.hidden) {
                return;
            }
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                move(1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                move(-1);
            } else if (event.key === 'Enter' && activeIndex >= 0) {
                event.preventDefault();
                choose(suggestions[activeIndex]);
            } else if (event.key === 'Escape') {
                close();
            }
        });

        input.addEventListener('blur', close);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('input[data-autocomplete]').forEach(attach);
    });
})();
