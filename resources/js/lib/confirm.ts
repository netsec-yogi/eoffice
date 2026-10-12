import { reactive } from 'vue';

/**
 * Promise-based confirmation, rendered by <ConfirmHost> in the layouts:
 *
 *   ask('Delete this comment?').then((ok) => ok && router.delete(...))
 *
 * The first sentence becomes the title, the rest the explanation. The
 * button is labelled with the action's verb, red for destructive verbs.
 */
export interface ConfirmRequest {
    title: string;
    message?: string;
    confirmLabel: string;
    cancelLabel: string;
    tone: 'danger' | 'primary';
}

export const pending = reactive<{ request: ConfirmRequest | null; resolve: ((ok: boolean) => void) | null }>({ request: null, resolve: null });

const DESTRUCTIVE = /^(delete|remove|block|archive|cancel|leave|withdraw|close|suspend|reject|unlink|revoke|sign out)/i;

export function ask(text: string, options: Partial<ConfirmRequest> = {}): Promise<boolean> {
    const match = text.match(/^(.+?[?.!])\s+(.*)$/s);
    const title = match ? match[1]! : text;
    const verb = title.replace(/[?.!]$/, '').split(' ')[0]!;
    const isVerb = /^[A-Z][a-z]+$/.test(verb) && !/^(Are|Is|Do|Does|Should|Really)$/.test(verb);
    const tone = DESTRUCTIVE.test(title) ? 'danger' : 'primary';

    pending.resolve?.(false); // only one at a time
    return new Promise((resolve) => {
        pending.request = {
            title,
            message: match?.[2],
            confirmLabel: isVerb ? verb : 'Confirm',
            cancelLabel: /^cancel/i.test(title) ? 'Keep it' : 'Cancel',
            tone,
            ...options,
        };
        pending.resolve = resolve;
    });
}

export function settle(ok: boolean) {
    pending.resolve?.(ok);
    pending.request = null;
    pending.resolve = null;
}
