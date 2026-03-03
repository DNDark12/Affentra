import { reactive } from 'vue';

const DEFAULT_DURATION = 4200;

const state = reactive({
    toasts: [],
});

let nextId = 1;

function dismiss(id) {
    const index = state.toasts.findIndex((toast) => toast.id === id);
    if (index >= 0) {
        state.toasts.splice(index, 1);
    }
}

function notify({
    type = 'info',
    title = '',
    message = '',
    duration = DEFAULT_DURATION,
    actionLabel = null,
    onAction = null,
} = {}) {
    const normalizedMessage = String(message || '').trim();
    if (!normalizedMessage) {
        return null;
    }

    const id = nextId++;
    const toast = {
        id,
        type,
        title: title ? String(title) : null,
        message: normalizedMessage,
        actionLabel,
        onAction,
    };

    state.toasts.push(toast);

    if (duration > 0) {
        window.setTimeout(() => dismiss(id), duration);
    }

    return id;
}

function success(message, options = {}) {
    return notify({
        type: 'success',
        title: options.title || 'Thành công',
        message,
        ...options,
    });
}

function error(message, options = {}) {
    return notify({
        type: 'error',
        title: options.title || 'Có lỗi xảy ra',
        message,
        duration: options.duration ?? 6000,
        ...options,
    });
}

function warning(message, options = {}) {
    return notify({
        type: 'warning',
        title: options.title || 'Cần chú ý',
        message,
        ...options,
    });
}

function info(message, options = {}) {
    return notify({
        type: 'info',
        title: options.title || 'Thông báo',
        message,
        ...options,
    });
}

function clear() {
    state.toasts.splice(0, state.toasts.length);
}

export function useToast() {
    return {
        toasts: state.toasts,
        notify,
        success,
        error,
        warning,
        info,
        dismiss,
        clear,
    };
}
