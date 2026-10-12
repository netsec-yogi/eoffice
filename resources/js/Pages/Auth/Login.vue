<script setup lang="ts">
import AlertBox from '@/Components/AlertBox.vue';
import AppButton from '@/Components/AppButton.vue';
import FormField from '@/Components/FormField.vue';
import TextInput from '@/Components/TextInput.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { useForm } from '@inertiajs/vue3';
import { KeyRound, User } from 'lucide-vue-next';

defineProps<{ directoryEnabled: boolean }>();

const form = useForm({ username: '', password: '' });
const submit = () => form.post(route('login.store'), { onFinish: () => form.reset('password') });
</script>

<template>
    <GuestLayout title="Sign in">
        <h1 class="text-lg font-bold text-ink">Sign in</h1>
        <p class="mt-1 text-sm text-muted">Use your institute username and password (the same as for e-mail and Wi-Fi).</p>

        <AlertBox v-if="!directoryEnabled" tone="warning" class="mt-4">Directory sign-in is switched off. Only local accounts can sign in.</AlertBox>

        <form class="mt-6 space-y-4" novalidate @submit.prevent="submit">
            <FormField label="Username" :error="form.errors.username" required>
                <TextInput v-model="form.username" :icon="User" autocomplete="username" autocapitalize="none" spellcheck="false" autofocus />
            </FormField>
            <FormField label="Password" :error="form.errors.password" required>
                <TextInput v-model="form.password" :icon="KeyRound" type="password" autocomplete="current-password" />
            </FormField>
            <AppButton type="submit" class="w-full" :loading="form.processing">Sign in</AppButton>
        </form>

        <p class="mt-6 text-xs leading-relaxed text-subtle">
            Vendors and external members sign in with the account issued to them. Forgotten your institute password? Reset it through the Computer Centre; this portal never stores it.
        </p>
    </GuestLayout>
</template>
