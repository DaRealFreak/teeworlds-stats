import { loadMapScene } from './viewer';
import { diffPhysics, formatVerdict, DIFF_COLORS, type PhysicsDiff, type DiffCategory } from './diff';
import type { MapViewer } from './viewer';

interface RunOptions {
    baseUrl: string; // version A (base) .map file URL
    targetUrl: string; // version B (target) .map file URL — the version the viewer renders
    viewer: MapViewer;
    panelEl: HTMLElement;
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
    const table = el('table', 'table table-sm mb-0') as HTMLTableElement;
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
    if (diff.aligned && diff.totals.added + diff.totals.removed + diff.totals.modified > 0) {
        root.append(legend(diff));
    }
    root.append(layerTable(diff));
    panelEl.replaceChildren(root);
}

export class MapCompare {
    /** Fetch both versions, diff their physics layers, paint the overlay (when aligned), fill the panel. */
    static async run(opts: RunOptions): Promise<void> {
        const { panelEl } = opts;
        panelEl.hidden = false;
        panelEl.replaceChildren(el('p', 'mb-0', 'Comparing versions…'));
        try {
            const [base, target] = await Promise.all([loadMapScene(opts.baseUrl), loadMapScene(opts.targetUrl)]);
            const diff = diffPhysics(base, target);
            if (diff.aligned) opts.viewer.setDiffOverlay(diff.cells);
            renderPanel(panelEl, diff, opts.labels);
        } catch {
            panelEl.replaceChildren(el('p', 'mb-0 text-danger', 'Could not load one of the versions to compare.'));
        }
    }
}
