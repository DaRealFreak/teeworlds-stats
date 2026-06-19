import { loadMapScene } from './viewer';
import { diffPhysics, formatVerdict, overlayState, DIFF_COLORS, type PhysicsDiff, type DiffCategory } from './diff';
import type { MapViewer } from './viewer';

interface RunOptions {
    baseUrl: string; // version A (base) .map file URL
    targetUrl: string; // version B (target) .map file URL — the version the viewer renders
    viewer: MapViewer;
    panelEl: HTMLElement;
    noticeEl?: HTMLElement | null; // viewer-area banner for the no-overlay cases; optional
    labels: { base: string; target: string }; // short content-keys for the panel header
}

/** Small typed element builder to keep the DOM assembly readable. */
function el(tag: string, className: string, text?: string): HTMLElement {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
}

function legend(diff: PhysicsDiff): HTMLElement {
    const wrap = el('div', 'mb-2');
    const items: [DiffCategory, number, string][] = [
        ['added', diff.totals.added, 'Added'],
        ['removed', diff.totals.removed, 'Removed'],
        ['modified', diff.totals.modified, 'Modified'],
    ];
    for (const [cat, n, label] of items) {
        const item = el('span', 'me-3', `${label} (${n})`);
        const sw = el('span', 'map-viewer__swatch', ''); // reuse the legend swatch styling
        sw.style.background = DIFF_COLORS[cat];
        item.prepend(sw);
        wrap.append(item);
    }
    return wrap;
}

function layerTable(diff: PhysicsDiff): HTMLElement {
    const table = el('table', 'table table-sm mb-0');
    const thead = document.createElement('thead');
    thead.innerHTML = '<tr><th>Layer</th><th>In A</th><th>In B</th><th>Added</th><th>Removed</th><th>Modified</th></tr>';
    const tbody = document.createElement('tbody');
    for (const l of diff.layers) {
        if (!l.inA && !l.inB) continue;
        const tr = document.createElement('tr');
        const fields = [l.kind, l.inA ? '✓' : '—', l.inB ? '✓' : '—', String(l.added), String(l.removed), String(l.modified)];
        for (const f of fields) tr.append(el('td', '', f));
        tbody.append(tr);
    }
    table.append(thead, tbody);
    return table;
}

function renderPanel(panelEl: HTMLElement, diff: PhysicsDiff, labels: RunOptions['labels']): void {
    const root = el('div', '');
    root.append(el('h2', 'h5', `Comparing ${labels.base} → ${labels.target}`));
    root.append(el('p', 'mb-2', formatVerdict(diff)));
    if (overlayState(diff) === 'shown') {
        root.append(legend(diff));
    }
    root.append(layerTable(diff));
    panelEl.replaceChildren(root);
}

/** Populate the viewer-area notice for the cases where no overlay is drawn; hide it when cells show. */
function applyNotice(noticeEl: HTMLElement, diff: PhysicsDiff): void {
    noticeEl.classList.remove('alert-warning', 'alert-info');
    const state = overlayState(diff);
    if (state === 'shown') {
        noticeEl.hidden = true;
        noticeEl.textContent = '';
        return;
    }
    if (state === 'misaligned') {
        noticeEl.classList.add('alert-warning');
        noticeEl.textContent =
            "These versions have different dimensions, so the changes can't be shown on the map — they're likely different maps. See the breakdown below.";
    } else {
        noticeEl.classList.add('alert-info');
        noticeEl.textContent = 'No physics-layer differences to highlight.';
    }
    noticeEl.hidden = false;
}

export class MapCompare {
    /** Fetch both versions, diff their physics layers, paint the overlay (when aligned), fill the panel. */
    static async run(opts: RunOptions): Promise<void> {
        const { panelEl, noticeEl } = opts;
        panelEl.hidden = false;
        if (noticeEl) noticeEl.hidden = true;
        panelEl.replaceChildren(el('p', 'mb-0', 'Comparing versions…'));
        try {
            const [base, target] = await Promise.all([loadMapScene(opts.baseUrl), loadMapScene(opts.targetUrl)]);
            const diff = diffPhysics(base, target);
            if (diff.aligned) opts.viewer.setDiffOverlay(diff.cells);
            renderPanel(panelEl, diff, opts.labels);
            if (noticeEl) applyNotice(noticeEl, diff);
        } catch {
            panelEl.replaceChildren(el('p', 'mb-0 text-danger', 'Could not load one of the versions to compare.'));
        }
    }
}
