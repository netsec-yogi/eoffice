<script setup lang="ts" generic="V extends string | number">
import { controlBase, controlTone } from './inputClasses';
import { useField } from './useField';

defineOptions({ inheritAttrs: false });
defineProps<{
    options: { value: V; label: string }[];
    placeholder?: string;
}>();
const model = defineModel<V | '' | null>();
const field = useField();
</script>

<template>
    <select v-model="model" v-bind="{ ...field.attrs(), ...$attrs }" :class="[controlBase, controlTone(field.invalid()), 'h-11 py-2 pr-9']">
        <option v-if="placeholder !== undefined" value="">{{ placeholder }}</option>
        <option v-for="o in options" :key="o.value" :value="o.value">{{ o.label }}</option>
    </select>
</template>
