<script setup lang="ts">
import { CloudUpload, FileText, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useField } from './useField';

/**
 * Drop zone + picker for a single file (v-model is the File). Shows the
 * chosen file, an optional upload progress bar (`progress`, 0-100, e.g.
 * from useForm), and lets the user clear it. Server-side checks still
 * decide what is accepted; `accept`/`maxMb` only guide the user.
 */
const props = defineProps<{ accept?: string; maxMb?: number; hint?: string; progress?: number | null; disabled?: boolean }>();
const model = defineModel<File | null>();
const field = useField();
const dragging = ref(false);
const input = ref<HTMLInputElement>();
const localError = ref('');

function pick(file: File | undefined | null) {
    localError.value = '';
    if (!file) return;
    if (props.maxMb && file.size > props.maxMb * 1024 * 1024) {
        localError.value = `That file is larger than ${props.maxMb} MB.`;
        return;
    }
    model.value = file;
}
const size = computed(() => (model.value ? (model.value.size > 1048576 ? `${(model.value.size / 1048576).toFixed(1)} MB` : `${Math.ceil(model.value.size / 1024)} KB`) : ''));
// Image preview (object URL, revoked when replaced or unmounted).
const preview = ref<string | null>(null);
watch(
    () => model.value,
    (file) => {
        if (preview.value) URL.revokeObjectURL(preview.value);
        preview.value = file && file.type.startsWith('image/') ? URL.createObjectURL(file) : null;
    },
    { immediate: true },
);
onBeforeUnmount(() => preview.value && URL.revokeObjectURL(preview.value));

function clear() {
    model.value = null;
    if (input.value) input.value.value = '';
}
</script>

<template>
    <div>
        <div v-if="model" class="flex items-center gap-3 rounded-2xl bg-surface-muted p-2.5 ring-1 ring-line ring-inset">
            <img v-if="preview" :src="preview" alt="Preview of the chosen image" class="size-14 shrink-0 rounded-xl object-cover" />
            <span v-else class="grid size-14 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-600"><FileText :size="22" aria-hidden="true" /></span>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-ink">{{ model.name }}</p>
                <p class="text-xs text-muted">{{ size }}<template v-if="progress != null"> · uploading {{ progress }}%</template></p>
                <div v-if="progress != null" class="mt-1.5 h-1 overflow-hidden rounded-full bg-line">
                    <div class="h-full rounded-full bg-brand-500 transition-[width]" :style="{ width: `${progress}%` }" />
                </div>
            </div>
            <button type="button" class="rounded p-1 text-subtle hover:bg-surface-sunken hover:text-ink" :disabled="disabled" aria-label="Remove file" @click="clear"><X :size="16" /></button>
        </div>
        <label
            v-else
            :class="[
                'flex cursor-pointer flex-col items-center justify-center gap-1.5 rounded-2xl border-2 border-dashed px-4 py-7 text-center transition-colors',
                dragging ? 'border-brand-500 bg-brand-50/50 dark:bg-brand-500/10' : field.invalid() ? 'border-red-300' : 'border-line-strong hover:border-brand-400 hover:bg-surface-muted',
                disabled && 'pointer-events-none opacity-60',
            ]"
            @dragover.prevent="dragging = true"
            @dragleave.prevent="dragging = false"
            @drop.prevent="(dragging = false), pick($event.dataTransfer?.files?.[0])"
        >
            <span class="grid size-11 place-items-center rounded-2xl bg-brand-50 text-brand-600"><CloudUpload :size="22" aria-hidden="true" /></span>
            <span class="text-sm text-ink-soft"><span class="font-medium text-brand-600">Choose a file</span> or drag it here</span>
            <span v-if="hint" class="text-xs text-muted">{{ hint }}</span>
            <input ref="input" type="file" class="sr-only" :accept="accept" :disabled="disabled" v-bind="field.attrs()" @change="pick(($event.target as HTMLInputElement).files?.[0])" />
        </label>
        <p v-if="localError" class="mt-1.5 text-[13px] text-red-600" role="alert">{{ localError }}</p>
    </div>
</template>
