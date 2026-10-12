<script setup lang="ts">
defineProps<{ label?: string; value?: string; description?: string }>();
const model = defineModel<boolean | string[]>();
</script>

<template>
    <label class="flex cursor-pointer items-start gap-2.5 text-sm text-ink-soft">
        <input v-model="model" :value="value" type="checkbox" class="mt-0.5 size-4 rounded focus:ring-brand-500" />
        <span>
            <slot>{{ label }}</slot>
            <span v-if="description" class="block text-[13px] text-muted">{{ description }}</span>
        </span>
    </label>
</template>
