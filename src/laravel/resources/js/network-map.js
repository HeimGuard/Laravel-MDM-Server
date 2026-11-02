// The network map of the Networks page (App\Support\NetworkMap): HTML nodes laid out top down with
// dagre (internet, public addresses, gateways, networks, devices), SVG links between them, panned
// by dragging and zoomed with the wheel, two fingers or the buttons. update() takes new data from a
// live update and keeps the view where it is.
import dagre from '@dagrejs/dagre';

// How many ranks a link spans: networks line up whether or not a gateway is known, VPNs (over the
// internet) at the level of the networks.
const SPAN = { tunnel: 3 };

const esc = (text) => String(text ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

function nodeHtml(node) {
    const badges = (node.badges || []).map((badge) => `<span class="nm-badge${badge === 'isolated' ? ' is-warning' : ''}">${esc(badge)}</span>`).join('');
    const dot = node.kind === 'device' || (node.kind === 'unknown' && !node.more) ? `<span class="nm-dot is-${esc(node.state)}"></span>` : '';
    return `<span class="nm-icon"><i class="${esc(node.icon)}"></i></span>`
        + `<span class="nm-text"><span class="nm-label">${esc(node.label)}${badges}</span>`
        + (node.sub ? `<span class="nm-sub">${dot}${esc(node.sub)}</span>` : '')
        + '</span>';
}

function rankSpan(edge, nodes) {
    if (SPAN[edge.type]) {
        return SPAN[edge.type];
    }
    const from = nodes.get(edge.from)?.kind;
    const to = nodes.get(edge.to)?.kind;
    // A site without a known gateway: its networks still at the level of the networks.
    if (from === 'site' && to === 'network') {
        return 2;
    }
    // A device or this server without a network of its own: at the level of the devices.
    if (from === 'site' && (to === 'device' || to === 'server')) {
        return 3;
    }
    return 1;
}

// A polyline with rounded corners (all links are right-angled, the same everywhere).
function curve(points, radius = 12) {
    let d = `M${points[0][0]} ${points[0][1]}`;
    for (let i = 1; i < points.length - 1; i++) {
        const [px, py] = points[i - 1];
        const [x, y] = points[i];
        const [nx, ny] = points[i + 1];
        const before = Math.hypot(x - px, y - py);
        const after = Math.hypot(nx - x, ny - y);
        const k = Math.min(radius, before / 2, after / 2);
        if (k < 0.5) {
            d += ` L${x} ${y}`;
            continue;
        }
        d += ` L${x - ((x - px) / before) * k} ${y - ((y - py) / before) * k} Q${x} ${y} ${x + ((nx - x) / after) * k} ${y + ((ny - y) / after) * k}`;
    }
    const [lx, ly] = points[points.length - 1];

    return `${d} L${lx} ${ly}`;
}

// Down from the parent, across at the height `mid`, down to the child.
const elbow = (a, b, mid) => (Math.abs(a.x - b.x) < 0.5 ? [[a.x, a.bottom], [b.x, b.top]] : [[a.x, a.bottom], [a.x, mid], [b.x, mid], [b.x, b.top]]);

const NETWORK_NAMES = { vpn: 'VPN', wifi: 'Wi-Fi', mixed: 'LAN', cellular: 'Mobile', lan: 'LAN' };
const networkName = (node) => NETWORK_NAMES[node.network] || 'LAN';

export function createNetworkMap(root, data) {
    root.innerHTML = '<div class="nm-viewport"><div class="nm-stage"><svg class="nm-links"></svg><div class="nm-nodes"></div></div></div>'
        + '<div class="nm-tools">'
        + '<button class="btn btn-light btn-sm" type="button" data-zoom="in" title="Zoom in"><i class="fas fa-plus"></i></button>'
        + '<button class="btn btn-light btn-sm" type="button" data-zoom="out" title="Zoom out"><i class="fas fa-minus"></i></button>'
        + '<button class="btn btn-light btn-sm" type="button" data-zoom="fit" title="Fit"><i class="fas fa-expand"></i></button>'
        + '</div>';
    const viewport = root.querySelector('.nm-viewport');
    const stage = root.querySelector('.nm-stage');
    const svg = root.querySelector('.nm-links');
    const layer = root.querySelector('.nm-nodes');
    const view = { x: 0, y: 0, scale: 1 };
    let size = { width: 0, height: 0 };
    let fitted = false;

    const apply = () => {
        stage.style.transform = `translate(${view.x}px, ${view.y}px) scale(${view.scale})`;
    };
    // On load readable (at least 75 %, on phones 45 %); the button shows all of it.
    const fit = (all = false) => {
        const box = viewport.getBoundingClientRect();
        if (!size.width || !box.width) {
            return;
        }
        const whole = Math.min(1, (box.width - 32) / size.width, (box.height - 32) / size.height);
        view.scale = all ? Math.max(0.2, whole) : Math.max(box.width < 700 ? 0.45 : 0.75, whole);
        view.x = (box.width - size.width * view.scale) / 2;
        view.y = Math.max(16, (box.height - size.height * view.scale) / 2);
        apply();
    };
    const zoomAt = (factor, cx, cy) => {
        const scale = Math.min(2.5, Math.max(0.2, view.scale * factor));
        view.x = cx - ((cx - view.x) * scale) / view.scale;
        view.y = cy - ((cy - view.y) * scale) / view.scale;
        view.scale = scale;
        apply();
    };

    // Which networks show their devices: what the user chose, else small ones (a click toggles).
    const OPEN_UP_TO = 6;
    const chosen = new Map();
    let current = null;
    let hover = new Set();

    // sides: 'left' | 'right' of its grid for each device of several networks (where its other network is).
    const render = (map, sides = new Map(), pass = 0) => {
        current = map;
        const nodes = new Map(map.nodes.map((node) => [node.id, node]));
        layer.innerHTML = '';
        const elements = new Map();
        for (const node of map.nodes) {
            const el = document.createElement(node.url ? 'a' : 'div');
            el.className = `nm-node is-${node.kind} tone-${node.tone} state-${node.state}${node.isolated ? ' is-isolated' : ''}`;
            el.dataset.id = node.id;
            if (node.url) {
                el.href = node.url;
            }
            el.innerHTML = nodeHtml(node);
            if (node.title) {
                el.title = node.title;
            }
            layer.appendChild(el);
            elements.set(node.id, el);
        }

        // The devices of a network sit in a grid under it (shown when the network is open); a device
        // of several networks is in the grid of its main one (a wired LAN before Wi-Fi before a VPN),
        // in the first column; its other networks link to it on hover (not all devices of a LAN are
        // in the VPN).
        const parents = new Map();
        for (const edge of map.edges) {
            if (nodes.get(edge.from)?.kind === 'network') {
                parents.set(edge.to, [...(parents.get(edge.to) || []), edge.from]);
            }
        }
        const priority = { lan: 0, mixed: 0, cellular: 1, wifi: 2, vpn: 3 };
        const mainOf = (owners) => [...owners].sort((a, b) => (priority[nodes.get(a).network] ?? 1) - (priority[nodes.get(b).network] ?? 1) || (nodes.get(b).devices || 0) - (nodes.get(a).devices || 0))[0];
        const members = new Map();
        const main = new Map();
        for (const node of map.nodes) {
            const owners = parents.get(node.id) || [];
            if (owners.length > 0 && ['device', 'unknown', 'server'].includes(node.kind)) {
                main.set(node.id, mainOf(owners));
                members.set(main.get(node.id), [...(members.get(main.get(node.id)) || []), node.id]);
            }
        }
        const isOpen = (network) => (chosen.has(network) ? chosen.get(network) : (members.get(network) || []).length <= OPEN_UP_TO);
        const shared = new Set(map.edges.filter((edge) => main.has(edge.to) && edge.from !== main.get(edge.to)).map((edge) => edge.to));

        for (const [id, el] of elements) {
            const node = nodes.get(id);
            if (node.kind === 'network' && (members.get(id) || []).length > 0) {
                const open = isOpen(id);
                el.classList.add('is-toggle', open ? 'is-open' : 'is-closed');
                el.querySelector('.nm-label').insertAdjacentHTML('beforeend', `<i class="fas fa-chevron-${open ? 'down' : 'right'} nm-chev"></i>`);
                el.title = open ? 'Hide the devices' : 'Show the devices';
            }
            // The other networks of a device: tags on its card.
            const others = (parents.get(id) || []).filter((owner) => owner !== main.get(id));
            if (main.has(id) && others.length > 0) {
                el.querySelector('.nm-label').insertAdjacentHTML('beforeend', others.map((owner) => `<span class="nm-tag is-${esc(nodes.get(owner).network)}">${esc(networkName(nodes.get(owner)))}</span>`).join(''));
            }
        }

        const grids = new Map();
        const inGrid = new Set();
        const hidden = new Set();
        const GAP = { x: 14, y: 12 };
        for (const [network, all] of members) {
            if (!isOpen(network)) {
                all.forEach((id) => hidden.add(id));
                continue;
            }
            const first = all.filter((id) => shared.has(id));
            const rest = all.filter((id) => !shared.has(id));
            const cols = Math.min(all.length, 6, Math.max(3, Math.round(Math.sqrt(all.length * 1.8))));
            const lefts = first.filter((id) => sides.get(id) !== 'right');
            const rights = first.filter((id) => sides.get(id) === 'right');
            const rows = Math.max(Math.ceil(all.length / cols), lefts.length, rights.length);
            // Devices of several networks down the outer column on the side of their other network
            // (so its link does not cross the others), the rest row by row.
            const cell = new Map([...lefts.map((id, row) => [id, { row, col: 0 }]), ...rights.map((id, row) => [id, { row, col: cols - 1 }])]);
            const taken = (row, col) => (col === 0 && row < lefts.length) || (col === cols - 1 && cols > 1 && row < rights.length);
            let k = 0;
            for (let row = 0; row < rows && k < rest.length; row++) {
                for (let col = 0; col < cols && k < rest.length; col++) {
                    if (!taken(row, col)) {
                        cell.set(rest[k++], { row, col });
                    }
                }
            }
            const ids = [...cell.keys()];
            const usedRows = Math.max(...[...cell.values()].map((c) => c.row)) + 1;
            const colWidth = Array.from({ length: cols }, (_, c) => Math.max(0, ...ids.filter((id) => cell.get(id).col === c).map((id) => elements.get(id).offsetWidth)));
            const rowHeight = Array.from({ length: usedRows }, (_, r) => Math.max(0, ...ids.filter((id) => cell.get(id).row === r).map((id) => elements.get(id).offsetHeight)));
            const width = colWidth.reduce((a, b) => a + b, 0) + GAP.x * (cols - 1);
            const height = rowHeight.reduce((a, b) => a + b, 0) + GAP.y * (usedRows - 1);
            grids.set(network, { id: `grid:${network}`, ids, cell, cols, rows: usedRows, colWidth, rowHeight, width, height });
            ids.forEach((id) => inGrid.add(id));
        }
        // The other networks of the devices that are shown in a grid.
        const sideLinks = map.edges.filter((edge) => inGrid.has(edge.to) && edge.from !== main.get(edge.to));
        for (const id of hidden) {
            elements.get(id).style.display = 'none';
        }

        const graph = new dagre.graphlib.Graph({ compound: true });
        graph.setGraph({ rankdir: 'TB', nodesep: 32, ranksep: 64, edgesep: 12, marginx: 0, marginy: 24 });
        graph.setDefaultEdgeLabel(() => ({}));
        const clusterOf = (node) => {
            if (node.group && node.kind !== 'internet') {
                const cluster = `cluster:${node.group}`;
                if (!graph.hasNode(cluster)) {
                    graph.setNode(cluster, {});
                }
                graph.setParent(node.id, cluster);
            }
        };
        const placed = (id) => !inGrid.has(id) && !hidden.has(id);
        for (const [id, el] of elements) {
            if (placed(id)) {
                graph.setNode(id, { width: el.offsetWidth, height: el.offsetHeight });
            }
        }
        for (const node of map.nodes) {
            if (placed(node.id)) {
                clusterOf(node);
            }
        }
        // A grid is one block of its real size under its network: dagre centers the network over it.
        for (const [network, grid] of grids) {
            graph.setNode(grid.id, { width: grid.width, height: grid.height });
            clusterOf({ id: grid.id, group: nodes.get(network).group, kind: 'grid' });
            graph.setEdge(network, grid.id, { minlen: 1, weight: 2 });
        }
        for (const edge of map.edges) {
            if (nodes.has(edge.from) && nodes.has(edge.to) && placed(edge.to) && placed(edge.from)) {
                graph.setEdge(edge.from, edge.to, { minlen: rankSpan(edge, nodes), weight: edge.type === 'tunnel' ? 0 : 1 });
            }
        }
        dagre.layout(graph);

        // A parent in the middle of its children (the VPN tunnels count as a split); only where nothing
        // stands in its way.
        const kids = new Map();
        for (const edge of map.edges) {
            if (placed(edge.to) && placed(edge.from) && graph.hasNode(edge.from) && graph.hasNode(edge.to)) {
                kids.set(edge.from, [...(kids.get(edge.from) || []), edge.to]);
            }
        }
        for (const [id, list] of [...kids].sort((p, q) => graph.node(p[0]).y - graph.node(q[0]).y)) {
            const n = graph.node(id);
            const xs = list.map((child) => graph.node(child).x).sort((p, q) => p - q);
            // Over the middle one of an odd number of children (as many splits on each side), else between the two in the middle.
            const x = xs.length % 2 === 1 ? xs[(xs.length - 1) / 2] : (xs[xs.length / 2 - 1] + xs[xs.length / 2]) / 2;
            const clear = graph.nodes().every((other) => {
                const o = graph.node(other);
                return other === id || other.startsWith('cluster:') || o.x === undefined || Math.abs(o.y - n.y) > (o.height + n.height) / 2 || Math.abs(o.x - x) >= (o.width + n.width) / 2 + 16;
            });
            if (clear) {
                n.x = x;
            }
        }

        // The view as big as what is drawn (room on the left for a link running beside a grid).
        let minX = Infinity;
        let maxX = -Infinity;
        for (const id of graph.nodes()) {
            const n = graph.node(id);
            if (!id.startsWith('cluster:') && n.x !== undefined) {
                minX = Math.min(minX, n.x - n.width / 2);
                maxX = Math.max(maxX, n.x + n.width / 2);
            }
        }
        const margin = 28;
        const room = sideLinks.length > 0 ? 24 : 0;
        const shift = margin + room - minX;
        for (const id of graph.nodes()) {
            const n = graph.node(id);
            if (n.x !== undefined) {
                n.x += shift;
            }
        }
        size = { width: maxX - minX + margin * 2 + room * 2, height: graph.graph().height };
        stage.style.width = `${size.width}px`;
        stage.style.height = `${size.height}px`;
        svg.setAttribute('width', size.width);
        svg.setAttribute('height', size.height);

        const box = new Map();
        for (const [id, el] of elements) {
            if (!placed(id)) {
                continue;
            }
            const { x, y, width, height } = graph.node(id);
            if (x === undefined) {
                continue;
            }
            el.style.left = `${x - width / 2}px`;
            el.style.top = `${y - height / 2}px`;
            box.set(id, { x, top: y - height / 2, bottom: y + height / 2, left: x - width / 2, right: x + width / 2 });
        }
        const bars = [];
        const routes = [];
        const gridBox = new Map();
        for (const [network, grid] of grids) {
            const { x, y } = graph.node(grid.id);
            const left = x - grid.width / 2;
            const top = y - grid.height / 2;
            gridBox.set(network, { left, top, right: left + grid.width, x });
            const colLeft = grid.colWidth.map((_, c) => left + grid.colWidth.slice(0, c).reduce((a, b) => a + b + GAP.x, 0));
            const rowTop = grid.rowHeight.map((_, r) => top + grid.rowHeight.slice(0, r).reduce((a, b) => a + b + GAP.y, 0));
            for (const id of grid.ids) {
                const el = elements.get(id);
                const { row, col } = grid.cell.get(id);
                const elTop = rowTop[row] + (grid.rowHeight[row] - el.offsetHeight) / 2;
                el.style.left = `${colLeft[col]}px`;
                el.style.top = `${elTop}px`;
                box.set(id, { x: colLeft[col] + el.offsetWidth / 2, top: elTop, bottom: elTop + el.offsetHeight, left: colLeft[col], right: colLeft[col] + el.offsetWidth });
            }
            // One link from the network to the top device of every column, the column goes on behind its cards.
            const kind = ['wifi', 'cellular', 'vpn'].includes(nodes.get(network).network) ? nodes.get(network).network : 'lan';
            const cls = `nm-link is-${kind}${nodes.get(network).state === 'up' ? '' : ' is-down'}`;
            const parent = box.get(network);
            for (let c = 0; c < grid.cols; c++) {
                const column = grid.ids.filter((id) => grid.cell.get(id).col === c).sort((p, q) => grid.cell.get(p).row - grid.cell.get(q).row);
                if (column.length === 0) {
                    continue;
                }
                const head = box.get(column[0]);
                routes.push({ bundle: network, a: parent, b: head, cls, title: '' });
                if (column.length > 1) {
                    bars.push(`<path class="${cls} is-spine" d="M${head.x} ${head.bottom} V${box.get(column[column.length - 1]).top}"></path>`);
                }
            }
        }

        // Links from the bottom of the upper node to the top of the lower one; down ones faint.
        for (const edge of map.edges) {
            if (placed(edge.to) && placed(edge.from) && box.has(edge.from) && box.has(edge.to)) {
                routes.push({ bundle: edge.from + (edge.type === 'tunnel' ? ':tunnel' : ''), a: box.get(edge.from), b: box.get(edge.to),
                    cls: `nm-link is-${esc(edge.type)}${edge.up ? '' : ' is-down'}`, title: edge.label ? `<title>${esc(edge.label)}</title>` : '' });
            }
        }
        // The links of one parent share a bar (the tree); the bars of different parents (and tunnels)
        // that would lie on each other run side by side in lanes.
        const LANE = 9;
        const bars_ = new Map();
        for (const route of routes) {
            route.base = route.a.bottom + 32;
            if (Math.abs(route.a.x - route.b.x) >= 0.5) {
                const key = Math.round(route.base);
                const group = bars_.get(key) || new Map();
                const range = group.get(route.bundle) || { from: Infinity, to: -Infinity, lane: 0 };
                range.from = Math.min(range.from, route.a.x, route.b.x);
                range.to = Math.max(range.to, route.a.x, route.b.x);
                group.set(route.bundle, range);
                bars_.set(key, group);
            }
        }
        for (const group of bars_.values()) {
            const taken = [];
            for (const range of [...group.values()].sort((p, q) => p.from - q.from)) {
                let lane = 0;
                while ((taken[lane] || []).some((other) => other.from < range.to && range.from < other.to)) {
                    lane++;
                }
                (taken[lane] ||= []).push(range);
                range.lane = lane;
            }
        }
        const drawn = routes.map((route) => {
            const lane = bars_.get(Math.round(route.base))?.get(route.bundle)?.lane || 0;

            return `<path class="${route.cls}" d="${curve(elbow(route.a, route.b, route.base + lane * LANE))}">${route.title}</path>`;
        });

        // The other networks of a device: faint, clear while the device or the network is pointed at.
        // Where the other networks are: a device goes to the outer column on that side (second pass).
        const wanted = new Map();
        for (const edge of sideLinks) {
            const grid = gridBox.get(main.get(edge.to));
            const net = box.get(edge.from);
            if (grid && net) {
                const side = net.x > grid.x ? 'right' : 'left';
                wanted.set(edge.to, wanted.get(edge.to) && wanted.get(edge.to) !== side ? 'left' : side);
            }
        }
        if (pass < 1 && [...wanted].some(([id, side]) => (sides.get(id) || 'left') !== side)) {
            render(map, wanted, pass + 1);

            return;
        }
        const side = (edge, active) => {
            const net = box.get(edge.from);
            const dev = box.get(edge.to);
            const grid = gridBox.get(main.get(edge.to));
            if (!net || !dev || !grid) {
                return '';
            }
            // Into the middle of the device's top, where its other links come in: across over the grid
            // for its first row, else across the gap above it.
            const y = dev.top - grid.top < 1 ? grid.top - 20 : dev.top - GAP.y / 2;
            const right = sides.get(edge.to) === 'right';
            const entryX = dev.x;
            const channel = right ? grid.right + 14 : grid.left - 14;
            const beside = right ? net.x > grid.right + 24 : net.x < grid.left - 24;
            const points = beside
                ? [[net.x, net.bottom], [net.x, y], [entryX, y], [entryX, dev.top]]
                : [[net.x, net.bottom], [net.x, net.bottom + 18], [channel, net.bottom + 18], [channel, y], [entryX, y], [entryX, dev.top]];
            return `<path class="nm-link is-side is-${esc(edge.type)}${active ? ' is-active' : ''}" d="${curve(points)}"></path>`;
        };
        const drawSide = () => {
            sideLayer.innerHTML = sideLinks.map((edge) => side(edge, hover.has(edge.from) || hover.has(edge.to))).join('');
        };
        svg.innerHTML = `<g>${bars.join('')}${drawn.join('')}</g><g class="nm-side"></g>`;
        const sideLayer = svg.querySelector('.nm-side');
        drawSide();
        layer.onpointerover = (event) => {
            const id = event.target.closest('.nm-node')?.dataset.id;
            hover = new Set(id ? [id] : []);
            drawSide();
        };
        layer.onpointerleave = () => {
            hover = new Set();
            drawSide();
        };

        if (!fitted) {
            fit();
            fitted = true;
        }
    };

    const toggle = (network) => {
        const open = layer.querySelector(`[data-id="${CSS.escape(network)}"]`)?.classList.contains('is-open');
        chosen.set(network, !open);
        render(current);
    };

    // Dragging pans, two fingers pinch; a click on a node opens it (not after a drag).
    const pointers = new Map();
    let moved = false;
    let start = null;
    let pinch = null;
    viewport.addEventListener('pointerdown', (event) => {
        pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
        if (pointers.size === 1) {
            moved = false;
            start = { x: event.clientX, y: event.clientY };
        }
        if (pointers.size === 2) {
            const [p, q] = [...pointers.values()];
            pinch = { distance: Math.hypot(p.x - q.x, p.y - q.y) };
        }
    });
    viewport.addEventListener('pointermove', (event) => {
        const last = pointers.get(event.pointerId);
        if (!last) {
            return;
        }
        const current = { x: event.clientX, y: event.clientY };
        pointers.set(event.pointerId, current);
        if (pointers.size === 2 && pinch) {
            const [p, q] = [...pointers.values()];
            const distance = Math.hypot(p.x - q.x, p.y - q.y);
            const rect = viewport.getBoundingClientRect();
            zoomAt(distance / pinch.distance, (p.x + q.x) / 2 - rect.left, (p.y + q.y) / 2 - rect.top);
            pinch.distance = distance;
            moved = true;
            return;
        }
        // A few pixels are still a click.
        if (!moved && Math.hypot(current.x - start.x, current.y - start.y) < 4) {
            return;
        }
        moved = true;
        view.x += current.x - last.x;
        view.y += current.y - last.y;
        apply();
    });
    const release = (event) => {
        pointers.delete(event.pointerId);
        if (pointers.size < 2) {
            pinch = null;
        }
    };
    viewport.addEventListener('pointerup', release);
    viewport.addEventListener('pointercancel', release);
    viewport.addEventListener('click', (event) => {
        if (moved) {
            event.preventDefault();
            event.stopPropagation();
        }
    }, true);
    viewport.addEventListener('click', (event) => {
        const network = event.target.closest('.nm-node.is-toggle');
        if (network && !moved) {
            toggle(network.dataset.id);
        }
    });
    viewport.addEventListener('wheel', (event) => {
        event.preventDefault();
        const rect = viewport.getBoundingClientRect();
        zoomAt(event.deltaY < 0 ? 1.12 : 1 / 1.12, event.clientX - rect.left, event.clientY - rect.top);
    }, { passive: false });
    root.querySelector('.nm-tools').addEventListener('click', (event) => {
        const action = event.target.closest('[data-zoom]')?.dataset.zoom;
        const rect = viewport.getBoundingClientRect();
        if (action === 'in') zoomAt(1.25, rect.width / 2, rect.height / 2);
        if (action === 'out') zoomAt(0.8, rect.width / 2, rect.height / 2);
        if (action === 'fit') fit(true);
    });

    render(data);

    return { update: render, fit };
}
