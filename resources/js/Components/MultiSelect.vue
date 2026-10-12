<script setup lang="ts" generic="V extends string | number">
import { Check, ChevronDown, Search, X } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { controlTone } from './inputClasses';
import { useField } from './useField';

/**
 * Multi-select: selected values as removable chips, options as a checkbox
 * list in a popover (with a filter box when there are many). Keyboard:
 * Enter/Space/ArrowDown open it, Escape closes, Tab moves through options.
 */
defineOptions({ inheritAttrs: false });
const props = withDefaults(defineProps<{ options: { value: V; label: string }[]; placeholder?: string }>(), { placeholder: 'Any' });
const model = defineModel<V[]>({ default: () => [] });
const field = useField();

const open = ref(false);
const query = ref('');
const root = ref<HTMLElement>();
const filterInput = ref<HTMLInputElement>();

const selected = computed(() => props.options.filter((o) => model.value.includes(o.value)));
const visible = computed(() => {
    const q = query.value.trim().toLowerCase();
    return q ? props.options.filter((o) => o.label.toLowerCase().includes(q)) : props.options;
});

function toggle(v: V) {
    model.value = model.value.includes(v) ? model.value.filter((x) => x !== v) : [...model.value, v];
}
async function show() {
    open.value = true;
    await nextTick();
    filterInput.value?.focus();
}
const onDoc = (e: Event) => open.value && !root.value?.contains(e.target as Node) && (open.value = false);
onMounted(() => document.addEventListener('click', onDoc));
onBeforeUnmount(() => document.removeEventListener('click', onDoc));
</script>

<template>
    <div ref="root" class="relative" @keydown.esc.stop="open = false">
        <div
            :class="['flex min-h-9.5 w-full cursor-pointer items-center gap-1.5 rounded-md bg-surface py-1 pr-2 pl-1.5 shadow-xs ring-1 ring-inset', controlTone(field.invalid()), open && 'ring-2 ring-brand-500']"
            @click="open ? (open = false) : show()"
        >
            <div class="flex min-w-0 flex-1 flex-wrap gap-1">
                <span v-for="o in selected" :key="o.value" class="inline-flex items-center gap-1 rounded bg-brand-50 py-0.5 pr-1 pl-2 text-xs font-medium text-brand-700 dark:bg-brand-500/15 dark:text-brand-300">
                    {{ o.label }}
                    <button type="button" class="rounded p-0.5 hover:bg-brand-100 dark:hover:bg-brand-500/25" :aria-label="`Remove ${o.label}`" @click.stop="toggle(o.value)"><X :size="12" /></button>
                </span>
                <button
                    v-bind="{ ...field.attrs(), ...$attrs }"
                    type="button"
                    :aria-expanded="open"
                    aria-haspopup="listbox"
                    :class="['px-1.5 text-left text-sm outline-none', selected.length ? 'sr-only focus:not-sr-only' : 'text-subtle']"
                    @click.stop="open ? (open = false) : show()"
                    @keydown.down.prevent="show"
                >
                    {{ selected.length ? `${selected.length} selected` : placeholder }}
                </button>
            </div>
            <button v-if="selected.length" type="button" class="rounded p-0.5 text-subtle hover:text-ink" aria-label="Clear selection" @click.stop="model = []"><X :size="14" /></button>
            <ChevronDown :size="16" :class="['shrink-0 text-subtle transition-transform', open && 'rotate-180']" aria-hidden="true" />
        </div>

        <div v-if="open" class="absolute z-30 mt-1.5 w-full overflow-hidden rounded-md bg-surface shadow-pop ring-1 ring-line">
            <div v-if="options.length > 8" class="relative border-b border-line-soft p-2">
                <Search :size="14" class="pointer-events-none absolute top-1/2 left-4.5 -translate-y-1/2 text-subtle" aria-hidden="true" />
                <input ref="filterInput" v-model="query" type="search" placeholder="Filter…" aria-label="Filter options" class="h-8 w-full rounded border-0 bg-surface-sunken pl-7 text-sm text-ink focus:ring-2 focus:ring-brand-500" />
            </div>
            <ul class="max-h-60 overflow-y-auto py-1" role="listbox" aria-multiselectable="true">
                <li v-for="o in visible" :key="o.value" role="option" :aria-selected="model.includes(o.value)">
                    <label class="flex cursor-pointer items-center gap-2.5 px-3 py-1.5 text-sm text-ink-soft hover:bg-surface-muted">
                        <input type="checkbox" class="size-4 rounded focus:ring-brand-500" :checked="model.includes(o.value)" @change="toggle(o.value)" />
                        <span class="flex-1">{{ o.label }}</span>
                        <Check v-if="model.includes(o.value)" :size="14" class="text-brand-600" aria-hidden="true" />
                    </label>
                </li>
                <li v-if="visible.length === 0" class="px-3 py-2 text-sm text-muted">No matches.</li>
            </ul>
        </div>
    </div>
</template>
