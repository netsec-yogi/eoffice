/** Shared look for text-like controls, so every input, select and textarea match. */
export const controlBase =
    'block w-full rounded-xl border-0 !bg-surface-muted text-sm text-ink ring-1 ring-inset placeholder:text-subtle transition-[box-shadow,background-color] focus:!bg-surface focus:ring-2 focus:ring-inset disabled:cursor-not-allowed disabled:text-muted';

export const controlTone = (invalid: boolean) => (invalid ? 'ring-red-400 focus:ring-red-500' : 'ring-line focus:ring-brand-500');
