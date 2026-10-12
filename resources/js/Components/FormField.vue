<script setup lang="ts">
import { CircleAlert } from 'lucide-vue-next';
import { provide, useId } from 'vue';

const props = defineProps<{
    label: string;
    error?: string;
    hint?: string;
    required?: boolean;
    /** Visually hide the label (it stays available to screen readers). */
    hideLabel?: boolean;
}>();

const id = useId();
provide('field', { id, describedBy: `${id}-desc`, invalid: () => !!props.error });
</script>

<template>
    <div>
        <label :for="id" :class="hideLabel ? 'sr-only' : 'mb-1.5 block text-[13px] font-medium text-ink-soft'">
            {{ label }}<span v-if="required" class="ml-0.5 text-red-500" aria-hidden="true">*</span>
        </label>
        <slot :id="id" />
        <p v-if="error" :id="`${id}-desc`" class="mt-1.5 flex items-start gap-1 text-[13px] text-red-600 dark:text-red-400" role="alert">
            <CircleAlert :size="14" class="mt-0.5 shrink-0" aria-hidden="true" />{{ error }}
        </p>
        <p v-else-if="hint" :id="`${id}-desc`" class="mt-1.5 text-[13px] text-muted">{{ hint }}</p>
    </div>
</template>
