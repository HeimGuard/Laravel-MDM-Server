// A tab the user picked stays active when Livewire re-renders the component. Bootstrap switches tabs in
// the browser and the server only learns about it with the next request, so a live update that was
// already on its way would morph the page back to the tab the server last knew. Components opt in with
// data-keep-tab on a wrapper of their tabs.
const kept = (el) => el instanceof HTMLElement
    && (el.matches('[data-bs-toggle="tab"]') || el.matches('.tab-pane'))
    && el.closest('[data-keep-tab]') !== null;

document.addEventListener('livewire:init', () => {
    window.Livewire.hook('morph.updating', ({ el, toEl }) => {
        if (!kept(el) || !kept(toEl)) {
            return;
        }
        ['active', 'show'].forEach((name) => toEl.classList.toggle(name, el.classList.contains(name)));
        if (el.hasAttribute('aria-selected')) {
            toEl.setAttribute('aria-selected', el.getAttribute('aria-selected'));
        }
    });

    // The shown tab disappeared (its data is gone): fall back to the first one.
    window.Livewire.hook('morphed', ({ el }) => {
        [el, ...el.querySelectorAll('[data-keep-tab]')].filter((root) => root.matches('[data-keep-tab]')).forEach((root) => {
            if (!root.querySelector('.tab-pane.active')) {
                root.querySelector('[data-bs-toggle="tab"]')?.click();
            }
        });
    });
});
