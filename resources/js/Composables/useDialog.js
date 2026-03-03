import { reactive } from 'vue';

const state = reactive({
    isOpen: false,
    mode: 'confirm',
    variant: 'info',
    title: '',
    description: '',
    confirmText: 'Xác nhận',
    cancelText: 'Hủy',
    inputLabel: '',
    inputPlaceholder: '',
    inputMinLength: 0,
    inputValue: '',
    inputError: '',
    _resolver: null,
});

function resetState() {
    state.isOpen = false;
    state.mode = 'confirm';
    state.variant = 'info';
    state.title = '';
    state.description = '';
    state.confirmText = 'Xác nhận';
    state.cancelText = 'Hủy';
    state.inputLabel = '';
    state.inputPlaceholder = '';
    state.inputMinLength = 0;
    state.inputValue = '';
    state.inputError = '';
    state._resolver = null;
}

function openDialog(options = {}) {
    state.isOpen = true;
    state.mode = options.mode || 'confirm';
    state.variant = options.variant || 'info';
    state.title = options.title || 'Xác nhận thao tác';
    state.description = options.description || '';
    state.confirmText = options.confirmText || 'Xác nhận';
    state.cancelText = options.cancelText || 'Hủy';
    state.inputLabel = options.inputLabel || '';
    state.inputPlaceholder = options.inputPlaceholder || '';
    state.inputMinLength = Number(options.inputMinLength || 0);
    state.inputValue = options.defaultValue || '';
    state.inputError = '';
}

function resolveDialog(value) {
    const resolver = state._resolver;
    resetState();
    if (typeof resolver === 'function') {
        resolver(value);
    }
}

function cancelDialog() {
    resolveDialog(state.mode === 'prompt' ? null : false);
}

function submitDialog() {
    if (state.mode === 'prompt') {
        const trimmed = String(state.inputValue || '').trim();
        if (state.inputMinLength > 0 && trimmed.length < state.inputMinLength) {
            state.inputError = `Vui lòng nhập tối thiểu ${state.inputMinLength} ký tự.`;
            return;
        }
        resolveDialog(trimmed);
        return;
    }

    resolveDialog(true);
}

function confirmDialog(options = {}) {
    return new Promise((resolve) => {
        openDialog({ ...options, mode: 'confirm' });
        state._resolver = resolve;
    });
}

function promptDialog(options = {}) {
    return new Promise((resolve) => {
        openDialog({ ...options, mode: 'prompt' });
        state._resolver = resolve;
    });
}

export function useDialog() {
    return {
        dialog: state,
        confirmDialog,
        promptDialog,
        submitDialog,
        cancelDialog,
    };
}
