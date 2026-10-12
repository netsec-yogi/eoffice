<script setup lang="ts">
import type { UserRef } from '@/types';
import { Search, X } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import { controlBase, controlTone } from './inputClasses';
import { useField } from './useField';

/**
 * Typeahead over active users (lookup.users). v-model is the user id;
 * `selected` seeds the label when editing an existing value.
 */
const props = defineProps<{ selected?: UserRef | null; category?: string; placeholder?: string }>();
const model = defineModel<number | null>();
const field = useField();

const current = ref<UserRef | null>(props.selected ?? null);
const query = ref('');
const results = ref<UserRef[]>([]);
const open = ref(false);
const active = ref(-1);
let timer: ReturnType<typeof setTimeout> | undefined;
let seq = 0;

watch(query, (q) => {
    clearTimeout(timer);
    if (q.trim().length < 2) {
        results.value = [];
        return;
    }
    timer = setTimeout(async () => {
        const mine = ++seq;
        const url = route('lookup.users', { q: q.trim(), category: props.category });
        const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (mine === seq && res.ok) {
            results.value = await res.json();
            open.value = true;
            active.value = results.value.length ? 0 : -1;
        }
    }, 200);
});

function choose(user: UserRef) {
    current.value = user;
    model.value = user.id;
    query.value = '';
    open.value = false;
}
const closeSoon = () => setTimeout(() => (open.value = false), 150);
function clear() {
    current.value = null;
    model.value = null;
}
function onKey(e: KeyboardEvent) {
    if (!open.value || !results.value.length) return;
    if (e.key === 'ArrowDown') active.value = (active.value + 1) % results.value.length;
    else if (e.key === 'ArrowUp') active.value = (active.value - 1 + results.value.length) % results.value.length;
    else if (e.key === 'Enter' && active.value >= 0) choose(results.value[active.value]!);
    else if (e.key === 'Escape') open.value = false;
    else return;
    e.preventDefault();
}
</script>

<template>
    <div class="relative">
        <div v-if="current && model" :class="[controlBase, controlTone(false), 'flex h-10 items-center justify-between gap-2 px-3']">
            <span class="truncate">
                <span class="font-medium text-ink">{{ current.name }}</span>
                <span class="text-muted"> · {{ current.username }}<template v-if="current.department"> · {{ current.department }}</template></span>
            </span>
            <button type="button" class="rounded p-1 text-muted hover:text-ink" :aria-label="`Clear ${current.name}`" @click="clear"><X :size="15" /></button>
        </div>
        <template v-else>
            <Search :size="16" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-subtle" aria-hidden="true" />
            <input
                v-bind="field.attrs()"
                v-model="query"
                type="search"
                role="combobox"
                :aria-expanded="open"
                aria-autocomplete="list"
                :placeholder="placeholder ?? 'Search by name, username or ID'"
                :class="[controlBase, controlTone(field.invalid()), 'h-10 pl-9']"
                @keydown="onKey"
                @blur="closeSoon"
            />
        </template>
        <ul v-if="open && !(current && model)" class="absolute z-40 mt-1 max-h-72 w-full overflow-auto rounded-xl bg-surface py-1 shadow-pop ring-1 ring-line" role="listbox">
            <li v-if="!results.length" class="px-3 py-2 text-sm text-muted">No matching active users.</li>
            <li
                v-for="(u, i) in results"
                :key="u.id"
                role="option"
                :aria-selected="i === active"
                :class="['cursor-pointer px-3 py-2 text-sm', i === active ? 'bg-brand-50 dark:bg-brand-500/15' : '']"
                @mousedown.prevent="choose(u)"
            >
                <span class="font-medium text-ink">{{ u.name }}</span>
                <span class="text-muted"> · {{ u.username }}</span>
                <span class="block text-xs text-subtle">{{ [u.designation, u.department].filter(Boolean).join(' · ') }}</span>
            </li>
        </ul>
    </div>
</template>
