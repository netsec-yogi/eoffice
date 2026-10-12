/** Classification badge text and tone; the label always carries the meaning, colour only reinforces it. */
export const classLabel: Record<string, string> = { public: 'Public', internal: 'Internal', confidential: 'Confidential', restricted: 'Restricted' };
export const classTone: Record<string, string> = {
    public: 'bg-surface-sunken text-muted',
    internal: 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-300',
    confidential: 'bg-amber-50 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
    restricted: 'bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-300',
};
