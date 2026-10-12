<script setup lang="ts">
import FlashMessages from '@/Components/FlashMessages.vue';
import { Head } from '@inertiajs/vue3';
import { Workflow } from 'lucide-vue-next';

defineProps<{ title: string }>();
</script>

<template>
    <Head :title="title" />
    <FlashMessages />
    <div class="grid min-h-dvh place-items-center bg-canvas px-4 py-10">
        <div class="w-full max-w-md">
            <div class="mb-8 flex flex-col items-center gap-3 text-center">
                <span class="grid size-12 place-items-center rounded-2xl bg-brand-600 text-white shadow-lg shadow-brand-600/25"><Workflow :size="24" aria-hidden="true" /></span>
                <div>
                    <p class="text-xl font-bold text-ink">{{ $page.props.appName }}</p>
                    <p class="text-sm text-muted">Institute e-Office and workflow platform</p>
                </div>
            </div>
            <main class="card p-6 sm:p-8">
                <slot />
            </main>
        </div>
    </div>
</template>
