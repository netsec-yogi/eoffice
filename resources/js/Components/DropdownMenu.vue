<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Disclosure menu: trigger slot + panel. Closes on outside click, Escape,
 * and after choosing an item. Items are ordinary links/buttons in the
 * default slot (use the `dropdown-item` class).
 */
withDefaults(defineProps<{ align?: 'left' | 'right'; width?: string; label?: string }>(), { align: 'right', width: 'w-56' });

const open = ref(false);
const root = ref<HTMLElement>();
const onDoc = (e: Event) => open.value && !root.value?.contains(e.target as Node) && (open.value = false);
const onKey = (e: KeyboardEvent) => e.key === 'Escape' && (open.value = false);
onMounted(() => {
    document.addEventListener('click', onDoc);
    document.addEventListener('keydown', onKey);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', onDoc);
    document.removeEventListener('keydown', onKey);
});
</script>

<template>
    <div ref="root" class="relative">
        <slot name="trigger" :open="open" :toggle="() => (open = !open)" />
        <Transition enter-from-class="opacity-0 scale-95" enter-active-class="transition duration-100 ease-out" leave-to-class="opacity-0 scale-95" leave-active-class="transition duration-75">
            <div
                v-if="open"
                :class="['absolute z-40 mt-2 origin-top-right overflow-hidden rounded-2xl bg-surface py-1.5 shadow-pop ring-1 ring-line', width, align === 'right' ? 'right-0' : 'left-0']"
                :aria-label="label"
                @click="open = false"
            >
                <slot />
            </div>
        </Transition>
    </div>
</template>
