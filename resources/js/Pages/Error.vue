<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{ status: number; message?: string | null }>();

const copy = computed(
    () =>
        ({
            403: ['Not allowed', props.message || 'You do not have permission to see this page or perform this action.'],
            404: ['Not found', 'This page does not exist, or you cannot see it.'],
            409: ['Already handled', props.message || 'Someone else acted on this first. Reload to see the current state.'],
            500: ['Something went wrong', 'The error has been logged. Please try again in a moment.'],
            503: ['Down for maintenance', 'The platform is being updated. Please try again shortly.'],
        })[props.status] ?? ['Error', 'Something went wrong.'],
);
</script>

<template>
    <Head :title="copy[0]" />
    <div class="grid min-h-dvh place-items-center bg-canvas px-4">
        <div class="max-w-md text-center">
            <p class="text-5xl font-bold text-brand-600">{{ status }}</p>
            <h1 class="mt-3 text-xl font-bold text-ink">{{ copy[0] }}</h1>
            <p class="mt-2 text-sm text-muted">{{ copy[1] }}</p>
            <AppButton class="mt-6" :href="route('dashboard')">Back to dashboard</AppButton>
        </div>
    </div>
</template>
