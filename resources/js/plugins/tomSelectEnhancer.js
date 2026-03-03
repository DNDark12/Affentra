import TomSelect from 'tom-select';

const INIT_ATTR = 'data-af-tom-select-init';

let observer;

function tomSelectOptions(select) {
    const placeholder = select.getAttribute('placeholder');
    const searchable = select.dataset.tomSearchable === '1';
    const options = {
        create: false,
        allowEmptyOption: true,
        sortField: [{ field: '$order', direction: 'asc' }],
        maxOptions: 5000,
    };

    if (placeholder && !select.multiple) {
        options.placeholder = placeholder;
    }

    if (!searchable) {
        options.searchField = [];
        options.controlInput = '<input type="text" readonly tabindex="-1" aria-hidden="true" style="position:absolute;left:-10000px;opacity:0;pointer-events:none;width:0;height:0;padding:0;border:0;" />';
    }

    if (select.multiple) {
        options.plugins = ['remove_button'];
    }

    return options;
}

function initSelect(select) {
    if (!(select instanceof HTMLSelectElement)) {
        return;
    }

    if (select.getAttribute(INIT_ATTR) === '1' || select.dataset.noTomSelect === '1') {
        return;
    }

    const inlineWidth = (select.style.width || '').trim();
    const copiedClasses = select.className.split(/\s+/).filter(Boolean);
    const instance = new TomSelect(select, tomSelectOptions(select));
    select.setAttribute(INIT_ATTR, '1');

    const preserveClass = (className) => {
        return /^(w-|min-w-|max-w-|basis-)/.test(className)
            || className === 'w-full'
            || className === 'min-w-full'
            || className === 'max-w-full'
            || className === 'flex-1'
            || className === 'grow'
            || /^text-(xs|sm|base|lg|xl)/.test(className);
    };

    const preservedClasses = copiedClasses.filter((className) => preserveClass(className));
    copiedClasses.forEach((className) => instance.wrapper.classList.remove(className));
    preservedClasses.forEach((className) => instance.wrapper.classList.add(className));

    if (inlineWidth.length) {
        instance.wrapper.style.width = inlineWidth;
    }
}

function destroySelect(select) {
    if (!(select instanceof HTMLSelectElement)) {
        return;
    }

    if (select.tomselect) {
        select.tomselect.destroy();
    }

    select.removeAttribute(INIT_ATTR);
}

function initWithin(root) {
    if (!(root instanceof Element || root instanceof Document)) {
        return;
    }

    if (root instanceof HTMLSelectElement) {
        initSelect(root);
        return;
    }

    root.querySelectorAll('select').forEach((select) => initSelect(select));
}

function destroyWithin(root) {
    if (!(root instanceof Element || root instanceof Document)) {
        return;
    }

    if (root instanceof HTMLSelectElement) {
        destroySelect(root);
        return;
    }

    root.querySelectorAll('select').forEach((select) => destroySelect(select));
}

function startObserver() {
    if (observer) {
        return;
    }

    observer = new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            mutation.addedNodes.forEach((node) => initWithin(node));
            mutation.removedNodes.forEach((node) => destroyWithin(node));
        }
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true,
    });
}

export async function bootTomSelectEnhancer() {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return false;
    }

    if (window.__AF_TOM_SELECT_BOOTED === true) {
        return true;
    }

    window.__AF_TOM_SELECT_BOOTED = true;
    initWithin(document);
    startObserver();
    return true;
}
