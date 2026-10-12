import { reactive } from 'vue';

export type ToastTone = 'success' | 'info' | 'warning' | 'error';

export interface Toast {
    id: number;
    tone: ToastTone;
    text: string;
}

/** App-wide toast queue. Server flash messages feed it too (FlashMessages.vue). */
export const toasts = reactive<Toast[]>([]);
let next = 0;

export function dismiss(id: number) {
    const i = toasts.findIndex((t) => t.id === id);
    if (i !== -1) toasts.splice(i, 1);
}

export function toast(text: string, tone: ToastTone = 'success') {
    const id = ++next;
    toasts.push({ id, tone, text });
    setTimeout(() => dismiss(id), tone === 'success' || tone === 'info' ? 5000 : 9000);
}
