<script setup lang="ts">
import { controlBase, controlTone } from './inputClasses';
import { useField } from './useField';

defineOptions({ inheritAttrs: false });
const model = defineModel<string | null>();
const field = useField();
</script>

<template>
    <textarea v-model="model" v-bind="{ ...field.attrs(), ...$attrs }" rows="4" :class="[controlBase, controlTone(field.invalid()), 'py-2 leading-relaxed']" />
</template>
