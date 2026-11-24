// The network map of the Networks page (App\Support\NetworkMap): HTML nodes laid out top down with
// dagre (internet, public addresses, gateways, networks, devices), SVG links between them, panned
// by dragging and zoomed with the wheel, two fingers or the buttons. update() takes new data from a
// live update and keeps the view where it is.
import dagre from '@dagrejs/dagre';
import { toPng } from 'html-to-image';

// How many ranks a link spans: networks line up whether or not a gateway is known, VPNs (over the
// internet) at the level of the networks.
const SPAN = { tunnel: 3 };

const esc = (text) => String(text ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

// The ports of a switch whose ports are read (agents 1.37.0+): a button each, by the speed of its link.
function portsHtml(card) {
    if (!card.ports.length) {
        return `<span class="nm-sub">${esc(card.empty)}</span>`;
    }
    const speed = (link) => (link === 6 ? 'is-gig' : link >= 4 ? 'is-fast' : link >= 2 ? 'is-slow' : 'is-down');

    return '<span class="nm-ports">' + card.ports.map((port) => `<button type="button" class="nm-port ${speed(port.link)}${port.bad > 0 ? ' has-errors' : ''}${port.devices.length ? ' has-devices' : ''}"`
        + ` data-switch="${esc(card.id)}" data-port="${esc(port.port)}" data-heading="${esc(port.heading)}" title="${esc(port.title)}" aria-label="${esc(port.title)}">${esc(port.port)}</button>`).join('') + '</span>';
}

function nodeHtml(node) {
    const badges = (node.badges || []).map((badge) => `<span class="nm-badge${badge === 'isolated' ? ' is-warning' : ''}">${esc(badge)}</span>`).join('');
    const dot = node.kind === 'device' || (node.kind === 'unknown' && !node.more) ? `<span class="nm-dot is-${esc(node.state)}"></span>` : '';
    const head = `<span class="nm-icon"><i class="${esc(node.icon)}"></i></span>`
        + `<span class="nm-text"><span class="nm-label">${esc(node.label)}${badges}</span>`
        + (node.sub ? `<span class="nm-sub">${dot}${esc(node.sub)}</span>` : '')
        + '</span>';
    // A switch is not a link as a whole (its ports are buttons): the head is.
    if (node.switch) {
        return `<a class="nm-head" href="${esc(node.url)}">${head}</a>${portsHtml(node.switch)}`;
    }

    return head;
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
        + '<button class="btn btn-light btn-sm" type="button" data-zoom="export" title="Download the map as an image (full size, everything open)"><i class="fas fa-download"></i></button>'
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
        layer.classList.remove('is-focus');
        svg.classList.remove('is-focus');
        layer.appendChild(tip);
        const elements = new Map();
        for (const node of map.nodes) {
            const el = document.createElement(node.url && !node.switch ? 'a' : 'div');
            el.className = `nm-node is-${node.kind} tone-${node.tone} state-${node.state}${node.isolated ? ' is-isolated' : ''}${node.switch ? ' is-switch' : ''}${node.kind === 'network' && node.offline > 0 ? ' has-offline' : ''}`;
            el.dataset.id = node.id;
            if (node.url && !node.switch) {
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
        // A network whose gateway is a router with its ports on the card (SNMP): while it is open the gateway stands for it (no node of its
        // own, the devices hang from the gateway in a frame that has what the node had and closes the network like the node), closed it is
        // the node again.
        const standIn = new Map();
        for (const edge of map.edges) {
            if (nodes.get(edge.from)?.kind === 'gateway' && nodes.get(edge.from).switch && nodes.get(edge.to)?.kind === 'network') {
                standIn.set(edge.to, edge.from);
            }
        }
        // A network with a switch of its own (the ports are read) is open (its devices hang under the switch), the others when they are small.
        const hasSwitch = (network) => (members.get(network) || []).some((id) => nodes.get(id)?.switch);
        const isOpen = (network) => (chosen.has(network) ? chosen.get(network) : standIn.has(network) || (members.get(network) || []).length <= OPEN_UP_TO || hasSwitch(network));
        const collapsed = new Map([...standIn].filter(([network]) => isOpen(network)));
        const shared = new Set(map.edges.filter((edge) => main.has(edge.to) && edge.from !== main.get(edge.to)).map((edge) => edge.to));

        // Devices plugged into a port of a switch of their own network hang under the card of the switch (a link from
        // the port), not in the grid of the network.
        const plugged = new Map();
        const pluggedIds = new Set();
        for (const node of map.nodes) {
            if (node.plug && nodes.get(node.plug.switch)?.switch && main.has(node.id) && main.get(node.id) === main.get(node.plug.switch)) {
                plugged.set(node.plug.switch, [...(plugged.get(node.plug.switch) || []), { id: node.id, port: Number(node.plug.port) }]);
                pluggedIds.add(node.id);
            }
        }
        // A device with a cable from a port of a switch that is not in its grid (the gateway) has no link of the network: its cable is its link, it
        // goes after the others in its column and the bar of the column does not reach it.
        const cabled = new Set();
        for (const node of map.nodes) {
            if (node.plug && nodes.get(node.plug.switch)?.switch && main.has(node.id) && !pluggedIds.has(node.id)) {
                cabled.add(node.id);
            }
        }
        // The cell of a switch in the grid: its card and the row of its devices under it (room for the links between).
        const LEVEL = 8;
        const SPEED = (link) => (link === 6 ? 'is-gig' : link >= 4 ? 'is-fast' : link >= 2 ? 'is-slow' : 'is-linkdown');
        // The devices under a switch in a row, each linked from its port: right-angled with rounded corners like the other links, the
        // bars of the links that run over other ports (the ones that go farthest) the lowest, so none crosses another.
        const portLinks = (id, el, elLeft, elTop, rowLeft) => {
            const kids = routesOf(id).slice().sort((p, q) => p.port - q.port);
            const card = nodes.get(id).switch;
            const top = elTop + el.offsetHeight + gapBelow(id);
            let left = rowLeft;
            const routes = kids.map((kid) => {
                const kidEl = elements.get(kid.id);
                kidEl.style.left = `${left}px`;
                kidEl.style.top = `${top}px`;
                box.set(kid.id, { x: left + kidEl.offsetWidth / 2, top, bottom: top + kidEl.offsetHeight, left, right: left + kidEl.offsetWidth });
                left += kidEl.offsetWidth + 14;
                const button = el.querySelector(`.nm-port[data-port="${kid.port}"]`);
                const port = card.ports.find((p) => p.port === kid.port);
                const start = button
                    ? { x: elLeft + el.clientLeft + button.offsetLeft + button.offsetWidth / 2, y: elTop + el.clientTop + button.offsetTop + button.offsetHeight }
                    : { x: elLeft + el.offsetWidth / 2, y: elTop + el.offsetHeight };

                return { kid, start, x: box.get(kid.id).x, port, up: nodes.get(kid.id).state === 'up' };
            });
            const right = routes.filter((route) => route.x - route.start.x >= 0.5).sort((p, q) => p.start.x - q.start.x || p.kid.port - q.kid.port);
            const leftGoing = routes.filter((route) => route.start.x - route.x >= 0.5).sort((p, q) => q.start.x - p.start.x || q.kid.port - p.kid.port);
            const levels = new Map();
            right.forEach((route, i) => levels.set(route, right.length - 1 - i));
            leftGoing.forEach((route, i) => levels.set(route, leftGoing.length - 1 - i));
            const base = elTop + el.offsetHeight + 10;
            for (const route of routes) {
                const to = box.get(route.kid.id);
                const points = levels.has(route)
                    ? [[route.start.x, route.start.y], [route.start.x, base + LEVEL * levels.get(route)], [route.x, base + LEVEL * levels.get(route)], [route.x, to.top]]
                    : [[route.start.x, route.start.y], [route.x, to.top]];
                bars.push(`<path class="nm-link is-port ${SPEED(route.port?.link ?? 0)}${route.up ? '' : ' is-down'}" data-owner="${esc(id)}" data-port="${route.kid.port}" d="${curve(points)}"></path><path class="nm-link-hit" data-owner="${esc(id)}" data-port="${route.kid.port}" d="${curve(points)}"></path>`);
            }
        };
        const routesOf = (id) => (plugged.get(id) || []);
        const gapBelow = (id) => 20 + LEVEL * routesOf(id).length;
        const cellSize = (id) => {
            const el = elements.get(id);
            const kids = routesOf(id).map((kid) => elements.get(kid.id));
            if (kids.length === 0) {
                return { width: el.offsetWidth, height: el.offsetHeight, rowWidth: 0 };
            }
            const rowWidth = kids.reduce((sum, kid) => sum + kid.offsetWidth, 0) + 14 * (kids.length - 1);

            return { width: Math.max(el.offsetWidth, rowWidth), height: el.offsetHeight + gapBelow(id) + Math.max(...kids.map((kid) => kid.offsetHeight)), rowWidth };
        };

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
        for (const [network, every] of members) {
            if (!isOpen(network)) {
                every.forEach((id) => hidden.add(id));
                continue;
            }
            const all = every.filter((id) => !pluggedIds.has(id));
            every.filter((id) => pluggedIds.has(id)).forEach((id) => inGrid.add(id));
            // A switch with devices under it is a block of its own (the card and its devices in a row under it) next to the grid, on a link of
            // the network like the columns; the others fill the grid.
            const composites = all.filter((id) => routesOf(id).length > 0);
            // (a switch with devices of its own under it that is itself plugged into the gateway stays a block of its own, without the link of the network)
            const cabledAll = all.filter((id) => cabled.has(id) && !composites.includes(id));
            const plain = all.filter((id) => !composites.includes(id) && !cabled.has(id));
            const first = plain.filter((id) => shared.has(id));
            const rest = plain.filter((id) => !shared.has(id));
            const cols = plain.length ? Math.min(plain.length, 6, Math.max(3, Math.round(Math.sqrt(plain.length * 1.8)))) : 0;
            const lefts = first.filter((id) => sides.get(id) !== 'right');
            const rights = first.filter((id) => sides.get(id) === 'right');
            const rows = cols ? Math.max(Math.ceil(plain.length / cols), lefts.length, rights.length) : 0;
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
            // The devices with a cable from a port: a column of their own each at the edge, on the side of their port (the left half of the ports
            // left), in the top row, so their cable comes straight down into them and passes behind no other card.
            const portOf = (id) => Number(nodes.get(id).plug.port);
            const leftHalf = (id) => {
                const ports = nodes.get(nodes.get(id).plug.switch).switch.ports;

                return ports.findIndex((port) => port.port === portOf(id)) < ports.length / 2;
            };
            const cabledLeft = cabledAll.filter(leftHalf).sort((p, q) => portOf(p) - portOf(q));
            const cabledRight = cabledAll.filter((id) => !leftHalf(id)).sort((p, q) => portOf(p) - portOf(q));
            for (const place of cell.values()) {
                place.col += cabledLeft.length;
            }
            cabledLeft.forEach((id, i) => cell.set(id, { row: 0, col: i }));
            cabledRight.forEach((id, i) => cell.set(id, { row: 0, col: cabledLeft.length + cols + i }));
            const allCols = cabledLeft.length + cols + cabledRight.length;
            const usedRows = cell.size ? Math.max(...[...cell.values()].map((c) => c.row)) + 1 : 0;
            const ids = [...cell.keys()];
            const colWidth = Array.from({ length: allCols }, (_, c) => Math.max(0, ...ids.filter((id) => cell.get(id).col === c).map((id) => elements.get(id).offsetWidth)));
            const rowHeight = Array.from({ length: usedRows }, (_, r) => Math.max(0, ...ids.filter((id) => cell.get(id).row === r).map((id) => elements.get(id).offsetHeight)));
            const columns = allCols ? colWidth.reduce((a, b) => a + b, 0) + GAP.x * (allCols - 1) : 0;
            const plainHeight = usedRows ? rowHeight.reduce((a, b) => a + b, 0) + GAP.y * (usedRows - 1) : 0;
            const blocks = composites.map((id) => cellSize(id));
            const width = columns + (columns > 0 && blocks.length ? GAP.x * 2 : 0) + blocks.reduce((sum, block) => sum + block.width, 0) + GAP.x * Math.max(0, blocks.length - 1);
            const height = Math.max(plainHeight, ...blocks.map((block) => block.height));
            grids.set(network, { id: `grid:${network}`, ids, cell, cols: allCols, rows: usedRows, colWidth, rowHeight, width, height, columns, wing: composites });
            composites.forEach((id) => inGrid.add(id));
            ids.forEach((id) => inGrid.add(id));
        }
        // The node of a network the gateway stands for is not drawn.
        collapsed.forEach((gateway, network) => hidden.add(network));
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
            graph.setEdge(collapsed.get(network) || network, grid.id, { minlen: 1, weight: 2 });
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
        // The links of a network the gateway stands for start at the bottom of the gateway.
        for (const [network, gateway] of collapsed) {
            const at = box.get(gateway);
            if (at) {
                box.set(network, { x: at.x, top: at.bottom, bottom: at.bottom, left: at.x, right: at.x });
            }
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
                const linked = column.filter((id) => !cabled.has(id));
                if (linked.length === 0) {
                    continue;
                }
                const head = box.get(linked[0]);
                routes.push({ bundle: network, a: parent, b: head, cls, title: '' });
                if (linked.length > 1) {
                    bars.push(`<path class="${cls} is-spine" d="M${head.x} ${head.bottom} V${box.get(linked[linked.length - 1]).top}"></path>`);
                }
            }
            // The blocks of the switches beside the columns, each on a link of the network (the card at the top, its devices in a row under it).
            let blockLeft = left + grid.columns + (grid.columns > 0 ? GAP.x * 2 : 0);
            for (const id of grid.wing) {
                const el = elements.get(id);
                const block = cellSize(id);
                const elLeft = blockLeft + (block.width - el.offsetWidth) / 2;
                el.style.left = `${elLeft}px`;
                el.style.top = `${top}px`;
                box.set(id, { x: elLeft + el.offsetWidth / 2, top, bottom: top + el.offsetHeight, left: elLeft, right: elLeft + el.offsetWidth });
                portLinks(id, el, elLeft, top, blockLeft + (block.width - block.rowWidth) / 2);
                if (!cabled.has(id)) {
                    routes.push({ bundle: network, a: parent, b: box.get(id), cls, title: '' });
                }
                blockLeft += block.width + GAP.x;
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
        // The devices of a network the gateway stands for are in a frame with its name (at the bottom, where no link comes in).
        const frames = [];
        const FRAME = 12;
        for (const network of collapsed.keys()) {
            const grid = grids.get(network);
            const at = gridBox.get(network);
            if (!grid || !at) {
                continue;
            }
            const kind = ['wifi', 'cellular', 'vpn'].includes(nodes.get(network).network) ? nodes.get(network).network : 'lan';
            const offline = nodes.get(network).offline > 0 ? ' has-offline' : '';
            frames.push(`<rect class="nm-frame is-${kind}${offline}" x="${at.left - FRAME}" y="${at.top - FRAME}" width="${grid.width + FRAME * 2}" height="${grid.height + FRAME * 2}" rx="14"></rect>`);
            const label = document.createElement('div');
            label.className = `nm-frame-label is-toggle is-open is-${kind}${nodes.get(network).offline > 0 ? ' has-offline' : ''}`;
            label.dataset.id = network;
            label.title = 'Hide the devices';
            label.style.left = `${at.left - FRAME + 14}px`;
            label.style.top = `${at.top + grid.height + FRAME}px`;
            label.innerHTML = `<i class="fas fa-chevron-down nm-chev"></i><b>${esc(nodes.get(network).label)}</b>${nodes.get(network).sub ? ` · ${esc(nodes.get(network).sub)}` : ''}`;
            layer.appendChild(label);
        }
        // A device plugged into a port of a switch that is not in its grid (the gateway of the network: its card hangs above the node of the
        // network): a link from the port, down and across above the node of the network, round it and into the top of the device.
        const remote = [];
        for (const node of map.nodes) {
            const owner = node.plug ? nodes.get(node.plug.switch) : null;
            const grid = gridBox.get(main.get(node.id));
            if (owner?.switch && grid && box.has(node.id) && box.has(owner.id) && !pluggedIds.has(node.id)) {
                const card = owner.switch;
                const el = elements.get(owner.id);
                const at = box.get(owner.id);
                const button = el.querySelector(`.nm-port[data-port="${Number(node.plug.port)}"]`);
                const start = button
                    ? { x: at.left + el.clientLeft + button.offsetLeft + button.offsetWidth / 2, y: at.top + el.clientTop + button.offsetTop + button.offsetHeight }
                    : { x: at.x, y: at.bottom };
                const dev = box.get(node.id);
                const net = box.get(main.get(node.id));
                // Into the top of the device beside its middle (where the link of the network comes in), round the node of the network, not through it
                // (a network the gateway stands for has no node).
                const entryX = dev.x + 14;
                const lane = net && net.right > net.left && entryX > net.left - 16 && entryX < net.right + 16 ? (dev.x < net.x ? net.left - 16 : net.right + 16) : entryX;
                remote.push({ node, at: { ...at, owner: owner.id }, net, start, dev, entryX, lane, grid, port: card.ports.find((p) => p.port === Number(node.plug.port)) });
            }
        }
        // The bars across run at their own heights (the ones that go farthest the lowest), so none crosses another.
        const goingRight = remote.filter((r) => r.lane - r.start.x >= 0.5).sort((p, q) => p.start.x - q.start.x || p.node.plug.port - q.node.plug.port);
        const goingLeft = remote.filter((r) => r.start.x - r.lane >= 0.5).sort((p, q) => q.start.x - p.start.x || q.node.plug.port - p.node.plug.port);
        goingRight.forEach((r, i) => { r.level = goingRight.length - 1 - i; });
        goingLeft.forEach((r, i) => { r.level = goingLeft.length - 1 - i; });
        for (const r of remote) {
            // Above the bar of the network (32 under the node), or above the node of the network.
            const across = Math.min(r.at.bottom + 10 + LEVEL * (r.level || 0), r.net && r.net.right > r.net.left ? r.net.top - 12 : r.at.bottom + 26);
            const entry = r.dev.top - r.grid.top < 1 ? r.grid.top - 20 : r.dev.top - GAP.y / 2;
            const raw = [[r.start.x, r.start.y], [r.start.x, across], [r.lane, across], [r.lane, entry], [r.entryX, entry], [r.entryX, r.dev.top]];
            // No point twice in a row (the corner of a link of no length would not be drawn).
            const points = raw.filter((point, i) => i === 0 || point[0] !== raw[i - 1][0] || point[1] !== raw[i - 1][1]);
            bars.push(`<path class="nm-link is-port ${SPEED(r.port?.link ?? 0)}${nodes.get(r.node.id).state === 'up' ? '' : ' is-down'}" data-owner="${esc(r.at.owner)}" data-port="${Number(r.node.plug.port)}" d="${curve(points)}"></path><path class="nm-link-hit" data-owner="${esc(r.at.owner)}" data-port="${Number(r.node.plug.port)}" d="${curve(points)}"></path>`);
        }
        svg.innerHTML = `<g>${frames.join('')}${bars.join('')}${drawn.join('')}</g><g class="nm-side"></g>`;
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
    // The dropdown of a port (on hover): the link, the packets and the devices plugged into it.
    const tip = document.createElement('div');
    tip.className = 'nm-port-tip';
    tip.hidden = true;
    layer.appendChild(tip);
    // The pointer on a port: its cable and the devices it leads to are lit, the rest of the map is dimmed.
    const light = (button) => {
        const owner = current.nodes.find((node) => node.switch && String(node.switch.id) === button.dataset.switch)?.id;
        const port = Number(button.dataset.port);
        const lit = new Set([owner]);
        for (const node of current.nodes) {
            if (node.plug && node.plug.switch === owner && Number(node.plug.port) === port) {
                lit.add(node.id);
            }
        }
        svg.querySelectorAll('path[data-owner]').forEach((path) => path.classList.toggle('is-lit', path.dataset.owner === owner && Number(path.dataset.port) === port));
        layer.querySelectorAll('.nm-node').forEach((el) => el.classList.toggle('is-lit', lit.has(el.dataset.id)));
        layer.classList.add('is-focus');
        svg.classList.add('is-focus');
    };
    const unlight = () => {
        svg.querySelectorAll('.is-lit').forEach((path) => path.classList.remove('is-lit'));
        layer.querySelectorAll('.nm-node.is-lit').forEach((el) => el.classList.remove('is-lit'));
        layer.classList.remove('is-focus');
        svg.classList.remove('is-focus');
    };
    const showTip = (button) => {
        light(button);
        const card = current.nodes.find((node) => node.switch && String(node.switch.id) === button.dataset.switch)?.switch;
        const port = card?.ports.find((p) => String(p.port) === button.dataset.port);
        if (!port) {
            return;
        }
        const row = (label, value) => `<span>${esc(label)}</span><span class="nm-tip-value">${esc(value)}</span>`;
        const fmt = (value) => (value === null || value === undefined ? '—' : Number(value).toLocaleString('en').replace(/,/g, ' '));
        tip.innerHTML = `<div class="nm-tip-head"><span class="nm-tip-title">${esc(port.heading)}</span><span class="nm-tip-link">${esc(port.link_name)}</span></div>`
            + `<div class="nm-tip-grid">${row(card.labels.bad, fmt(port.bad))}${row(card.labels.tx, fmt(port.tx))}${row(card.labels.rx, fmt(port.rx))}</div>`
            + `<div class="nm-tip-sub">${esc(card.labels.devices)}</div>`
            + (port.devices.length ? port.devices.map((device) => `<a class="nm-tip-device" href="${esc(device.url)}">${esc(device.name)}</a>`).join('') : `<div class="nm-tip-none">${esc(card.labels.none)}</div>`)
            + `<button type="button" class="nm-tip-action" data-switch="${esc(card.id)}" data-port="${esc(port.port)}" data-heading="${esc(port.heading)}">${esc(card.labels.hint)}</button>`;
        const box = button.getBoundingClientRect();
        const stageBox = stage.getBoundingClientRect();
        tip.style.left = `${(box.left - stageBox.left) / view.scale + box.width / 2 / view.scale - 24}px`;
        tip.style.top = `${(box.bottom - stageBox.top) / view.scale + 4}px`;
        tip.hidden = false;
    };
    // It stays while the pointer is on the port or on the dropdown (the way from one to the other takes a moment): the devices in it are links.
    let hideTimer = null;
    const cancelHide = () => { clearTimeout(hideTimer); hideTimer = null; };
    const hideSoon = () => { cancelHide(); hideTimer = setTimeout(() => { tip.hidden = true; unlight(); }, 250); };
    tip.addEventListener('pointerenter', cancelHide);
    tip.addEventListener('pointerleave', hideSoon);
    tip.addEventListener('pointerdown', (event) => event.stopPropagation());
    layer.addEventListener('pointerover', (event) => {
        const button = event.target.closest('.nm-port');
        if (button) {
            cancelHide();
            showTip(button);
        } else if (!event.target.closest('.nm-port-tip')) {
            hideSoon();
        }
    });
    layer.addEventListener('pointerleave', hideSoon);
    // The pointer on a cable: the same dropdown as on its port (the link, the packets, the devices) and the same highlight.
    svg.addEventListener('pointerover', (event) => {
        const cable = event.target.closest('.nm-link-hit');
        const card = cable ? current.nodes.find((node) => node.id === cable.dataset.owner)?.switch : null;
        const button = card ? layer.querySelector(`.nm-port[data-switch="${card.id}"][data-port="${cable.dataset.port}"]`) : null;
        if (button) {
            cancelHide();
            showTip(button);
        }
    });
    svg.addEventListener('pointerout', (event) => {
        if (event.target.closest('.nm-link-hit')) {
            hideSoon();
        }
    });
    const openPort = (source) => window.Livewire?.dispatch('openModal', { livewireComponents: 'networks.switch-port', title: source.dataset.heading, parameters: { deviceId: Number(source.dataset.switch), port: Number(source.dataset.port) } });
    tip.addEventListener('click', (event) => {
        const action = event.target.closest('.nm-tip-action');
        if (action) {
            tip.hidden = true;
            unlight();
            openPort(action);
        }
    });

    // The map as a picture (PNG) of its real size, not of what the window shows: every network open, the view as it is for the picture and as it
    // was before it (the networks the user closed are closed again). At most 16000 px a side (more is more than a browser draws).
    let exporting = false;
    const exportImage = async () => {
        if (exporting || !current) {
            return;
        }
        exporting = true;
        const button = root.querySelector('[data-zoom="export"]');
        button?.setAttribute('disabled', '');
        const before = new Map(chosen);
        tip.hidden = true;
        unlight();
        try {
            for (const node of current.nodes) {
                if (node.kind === 'network') {
                    chosen.set(node.id, true);
                }
            }
            render(current);
            // The links and frames are SVG: the picture does not take their styles from the stylesheet, so they are set on the elements (the dashes
            // are still, no animation).
            svg.querySelectorAll('.nm-link-hit').forEach((element) => element.remove());
            svg.querySelectorAll('path, rect').forEach((element) => {
                const computed = getComputedStyle(element);
                for (const property of ['fill', 'fill-opacity', 'stroke', 'stroke-opacity', 'stroke-width', 'stroke-dasharray', 'stroke-linecap', 'opacity']) {
                    element.style.setProperty(property, computed.getPropertyValue(property));
                }
                element.style.animation = 'none';
                element.style.filter = 'none';
            });
            const ratio = Math.min(2, 16000 / Math.max(size.width, size.height));
            const url = await toPng(stage, {
                width: size.width, height: size.height, pixelRatio: ratio,
                backgroundColor: getComputedStyle(document.body).backgroundColor,
                style: { transform: 'none', left: '0px', top: '0px' },
                filter: (node) => !node.classList?.contains('nm-port-tip'),
            });
            const link = document.createElement('a');
            link.download = `network-map-${new Date().toISOString().slice(0, 10)}.png`;
            link.href = url;
            link.click();
        } catch (error) {
            console.error('The picture of the map was not made', error);
        } finally {
            chosen.clear();
            before.forEach((open, network) => chosen.set(network, open));
            render(current);
            button?.removeAttribute('disabled');
            exporting = false;
        }
    };

    // A port of a switch: the devices plugged into it (a modal of the page, a multiselect).
    viewport.addEventListener('click', (event) => {
        const port = event.target.closest('.nm-port');
        if (port && !moved) {
            openPort(port);
        }
    });
    viewport.addEventListener('click', (event) => {
        const network = event.target.closest('.nm-node.is-toggle, .nm-frame-label.is-toggle');
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
        if (action === 'export') exportImage();
    });

    render(data);

    return { update: render, fit };
}
