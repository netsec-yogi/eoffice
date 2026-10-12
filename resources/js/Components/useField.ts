import { inject } from 'vue';

interface FieldContext {
    id: string;
    describedBy: string;
    invalid: () => boolean;
}

/** Wires an input to its surrounding FormField label, hint and error. */
export function useField() {
    const field = inject<FieldContext | null>('field', null);

    return {
        id: field?.id,
        attrs: () =>
            field
                ? { id: field.id, 'aria-describedby': field.describedBy, 'aria-invalid': field.invalid() || undefined }
                : {},
        invalid: () => field?.invalid() ?? false,
    };
}
