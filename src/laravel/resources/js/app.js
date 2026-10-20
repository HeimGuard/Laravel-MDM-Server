// Inter served with the app (no request to Google Fonts): Latin and Latin Extended (Czech).
import '@fontsource/inter/latin-400.css';
import '@fontsource/inter/latin-ext-400.css';
import '@fontsource/inter/latin-500.css';
import '@fontsource/inter/latin-ext-500.css';
import '@fontsource/inter/latin-600.css';
import '@fontsource/inter/latin-ext-600.css';
import '@fontsource/inter/latin-700.css';
import '@fontsource/inter/latin-ext-700.css';
import './boilerplate/boilerplate.js';
import './code-viewer';
import './live';
import './details-state';
import './tabs-state';

// The network map (Networks page), loaded only there.
window.mdmNetworkMap = async (root, data) => (await import('./network-map')).createNetworkMap(root, data);
