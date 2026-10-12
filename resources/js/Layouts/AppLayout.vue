<script setup lang="ts">
import ConfirmHost from '@/Components/ConfirmHost.vue';
import DropdownMenu from '@/Components/DropdownMenu.vue';
import FlashMessages from '@/Components/FlashMessages.vue';
import { buildNavigation, isActive } from '@/lib/navigation';
import { themePref, type ThemePref } from '@/lib/ui';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Bell, LogOut, Menu, Monitor, Moon, Sun, Workflow, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

defineProps<{ title: string }>();

const page = usePage();
const user = computed(() => page.props.auth.user!);
const counts = computed(() => page.props.counts ?? {});
const sections = computed(() => buildNavigation(user.value));

const menuOpen = ref(false);
watch(() => page.url, () => (menuOpen.value = false));

const badge = (key?: string) => (key ? (counts.value[key] ?? 0) : 0);
const fmt = (n: number) => (n > 99 ? '99+' : String(n));
const logout = () => router.post(route('logout'));

const themes: { value: ThemePref; label: string; icon: typeof Sun }[] = [
    { value: 'light', label: 'Light', icon: Sun },
    { value: 'dark', label: 'Dark', icon: Moon },
    { value: 'system', label: 'System', icon: Monitor },
];

const initials = computed(() =>
    user.value.name
        .split(/\s+/)
        .map((p) => p[0])
        .slice(0, 2)
        .join('')
        .toUpperCase(),
);
</script>

<template>
    <Head :title="title" />
    <FlashMessages />
    <ConfirmHost />
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-[70] focus:rounded-full focus:bg-surface focus:px-4 focus:py-2 focus:shadow-pop">Skip to content</a>

    <div class="min-h-dvh lg:pl-64">
        <!-- Sidebar (desktop) / drawer (mobile) -->
        <div v-if="menuOpen" class="fixed inset-0 z-40 bg-black/30 lg:hidden" aria-hidden="true" @click="menuOpen = false" />
        <aside
            :class="[
                'fixed inset-y-0 left-0 z-50 flex w-64 flex-col border-r border-line bg-sidebar transition-transform lg:translate-x-0',
                menuOpen ? 'translate-x-0' : '-translate-x-full',
            ]"
            aria-label="Main navigation"
        >
            <div class="flex h-16 items-center gap-2.5 px-5">
                <span class="grid size-9 place-items-center rounded-xl bg-brand-600 text-white"><Workflow :size="19" aria-hidden="true" /></span>
                <div class="min-w-0">
                    <p class="truncate text-[15px] font-bold text-ink">{{ $page.props.appName }}</p>
                    <p class="text-[11px] text-muted">e-Office &amp; workflows</p>
                </div>
                <button class="ml-auto rounded-lg p-1.5 text-muted hover:bg-surface lg:hidden" aria-label="Close menu" @click="menuOpen = false"><X :size="18" /></button>
            </div>
            <nav class="flex-1 space-y-6 overflow-y-auto px-3 pt-2 pb-6">
                <div v-for="section in sections" :key="section.title">
                    <p class="px-3 pb-1.5 text-[11px] font-semibold tracking-wider text-subtle uppercase">{{ section.title }}</p>
                    <ul class="space-y-0.5">
                        <li v-for="item in section.items" :key="item.route">
                            <Link
                                :href="route(item.route)"
                                :class="[
                                    'flex items-center gap-3 rounded-xl px-3 py-2 text-sm transition-colors',
                                    isActive(item) ? 'bg-surface font-semibold text-ink shadow-card' : 'font-medium text-ink-soft hover:bg-surface/70 hover:text-ink',
                                ]"
                                :aria-current="isActive(item) ? 'page' : undefined"
                            >
                                <component :is="item.icon" :size="18" :class="isActive(item) ? 'text-brand-600' : 'text-muted'" aria-hidden="true" />
                                <span class="flex-1">{{ item.label }}</span>
                                <span v-if="badge(item.badge)" class="rounded-full bg-brand-600 px-1.5 py-0.5 text-[11px] leading-none font-bold text-white">
                                    {{ fmt(badge(item.badge)) }}<span class="sr-only"> pending</span>
                                </span>
                            </Link>
                        </li>
                    </ul>
                </div>
            </nav>
        </aside>

        <!-- Top bar -->
        <header class="sticky top-0 z-30 border-b border-line/70 bg-topbar/85 backdrop-blur-xl">
            <div class="flex h-16 items-center gap-3 px-4 sm:px-6">
                <button class="rounded-lg p-2 text-ink-soft hover:bg-surface-sunken lg:hidden" aria-label="Open menu" @click="menuOpen = true"><Menu :size="20" /></button>
                <div class="flex-1" />
                <Link
                    v-if="route().has('notifications.index')"
                    :href="route('notifications.index')"
                    class="relative rounded-full p-2 text-ink-soft hover:bg-surface-sunken"
                    :aria-label="`Notifications (${badge('notifications')} unread)`"
                >
                    <Bell :size="20" />
                    <span v-if="badge('notifications')" class="absolute top-1 right-1 size-2.5 rounded-full bg-red-500 ring-2 ring-topbar" />
                </Link>
                <DropdownMenu width="w-64" label="Account menu">
                    <template #trigger="{ open, toggle }">
                        <button type="button" class="flex items-center gap-2.5 rounded-full py-1 pr-3 pl-1 hover:bg-surface-sunken" :aria-expanded="open" aria-haspopup="menu" @click="toggle">
                            <span class="grid size-8 place-items-center rounded-full bg-brand-100 text-xs font-bold text-brand-700">{{ initials }}</span>
                            <span class="hidden text-left sm:block">
                                <span class="block text-sm leading-tight font-semibold text-ink">{{ user.name }}</span>
                                <span class="block text-xs leading-tight text-muted">{{ user.designation ?? user.category }}<template v-if="user.department"> · {{ user.department.code }}</template></span>
                            </span>
                        </button>
                    </template>
                    <div class="border-b border-line-soft px-4 py-3">
                        <p class="text-sm font-semibold text-ink">{{ user.name }}</p>
                        <p class="truncate text-xs text-muted">{{ user.username }}<template v-if="user.email"> · {{ user.email }}</template></p>
                    </div>
                    <div class="px-4 py-3">
                        <p class="mb-2 text-xs font-medium text-muted">Theme</p>
                        <div class="grid grid-cols-3 gap-1 rounded-xl bg-surface-sunken p-1" role="radiogroup" aria-label="Theme">
                            <button
                                v-for="t in themes"
                                :key="t.value"
                                role="radio"
                                :aria-checked="themePref === t.value"
                                :class="['flex flex-col items-center gap-1 rounded-lg py-1.5 text-[11px]', themePref === t.value ? 'bg-surface font-semibold text-ink shadow-card' : 'text-muted']"
                                @click="themePref = t.value"
                            >
                                <component :is="t.icon" :size="15" aria-hidden="true" />{{ t.label }}
                            </button>
                        </div>
                    </div>
                    <button class="flex w-full items-center gap-2.5 border-t border-line-soft px-4 py-3 text-sm text-ink-soft hover:bg-surface-muted" @click="logout">
                        <LogOut :size="16" aria-hidden="true" /> Sign out
                    </button>
                </DropdownMenu>
            </div>
        </header>

        <main id="main" class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:py-8">
            <slot />
        </main>
    </div>
</template>
