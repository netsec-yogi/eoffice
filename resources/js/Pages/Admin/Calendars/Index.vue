<script setup lang="ts">
import AlertBox from '@/Components/AlertBox.vue';
import AppButton from '@/Components/AppButton.vue';
import CheckboxInput from '@/Components/CheckboxInput.vue';
import FormField from '@/Components/FormField.vue';
import PageHeader from '@/Components/PageHeader.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ask } from '@/lib/confirm';
import { router, useForm } from '@inertiajs/vue3';
import { Trash2 } from 'lucide-vue-next';

interface Holiday { id: number; date: string; name: string; kind: string }
interface Calendar { id: number; code: string; name: string; working_days: number[]; day_starts_at: string; day_ends_at: string; is_default: boolean; holidays: Holiday[] }

const props = defineProps<{ year: number; calendars: Calendar[] }>();
const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

const forms = Object.fromEntries(
    props.calendars.map((c) => [c.id, useForm({ code: c.code, name: c.name, working_days: c.working_days.map(String), day_starts_at: c.day_starts_at, day_ends_at: c.day_ends_at, is_default: c.is_default })]),
);
const holidayForms = Object.fromEntries(props.calendars.map((c) => [c.id, useForm({ date: '', name: '', kind: 'gazetted' })]));

const save = (c: Calendar) => forms[c.id]!.transform((d) => ({ ...d, working_days: d.working_days.map(Number) })).put(route('admin.calendars.update', c.id), { preserveScroll: true });
const addHoliday = (c: Calendar) => holidayForms[c.id]!.post(route('admin.calendars.holidays.store', c.id), { preserveScroll: true, onSuccess: () => holidayForms[c.id]!.reset() });
async function remove(h: Holiday) {
    if (await ask(`Remove ${h.name}? Deadlines computed from now on will count ${h.date} as a working day.`)) router.delete(route('admin.calendars.holidays.destroy', h.id), { preserveScroll: true });
}
const year = (y: number) => router.get(route('admin.calendars.index'), { year: y }, { preserveScroll: true });
</script>

<template>
    <AppLayout title="Calendars">
        <PageHeader title="Working calendars" description="SLA deadlines in working days or hours skip non-working days and holidays listed here." />

        <AlertBox tone="warning" class="mb-6">Seeded hours are placeholders. Confirm the institute's working days, hours and holiday list before relying on SLA deadlines.</AlertBox>

        <section v-for="c in calendars" :key="c.id" class="card mb-6 p-6">
            <form class="space-y-4" @submit.prevent="save(c)">
                <div class="grid gap-4 sm:grid-cols-4">
                    <FormField label="Code" :error="forms[c.id]!.errors.code"><TextInput v-model="forms[c.id]!.code" /></FormField>
                    <FormField label="Name" :error="forms[c.id]!.errors.name" class="sm:col-span-3"><TextInput v-model="forms[c.id]!.name" /></FormField>
                    <FormField label="Office opens" :error="forms[c.id]!.errors.day_starts_at"><TextInput v-model="forms[c.id]!.day_starts_at" type="time" /></FormField>
                    <FormField label="Office closes" :error="forms[c.id]!.errors.day_ends_at"><TextInput v-model="forms[c.id]!.day_ends_at" type="time" /></FormField>
                    <fieldset class="sm:col-span-2">
                        <legend class="mb-1.5 text-sm font-medium text-ink">Working days</legend>
                        <div class="flex flex-wrap gap-3"><CheckboxInput v-for="(d, i) in days" :key="d" v-model="forms[c.id]!.working_days" :value="String(i + 1)" :label="d" /></div>
                        <p v-if="forms[c.id]!.errors.working_days" class="mt-1 text-sm text-red-600">{{ forms[c.id]!.errors.working_days }}</p>
                    </fieldset>
                </div>
                <div class="flex items-center justify-between">
                    <CheckboxInput v-model="forms[c.id]!.is_default" label="Default calendar" />
                    <AppButton type="submit" size="sm" :loading="forms[c.id]!.processing">Save calendar</AppButton>
                </div>
            </form>

            <div class="mt-6 border-t border-line-soft pt-5">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="font-semibold text-ink">Holidays {{ year }}</h3>
                    <div class="flex gap-1">
                        <AppButton size="sm" variant="ghost" @click="year(props.year - 1)">← {{ props.year - 1 }}</AppButton>
                        <AppButton size="sm" variant="ghost" @click="year(props.year + 1)">{{ props.year + 1 }} →</AppButton>
                    </div>
                </div>
                <ul class="divide-y divide-line-soft">
                    <li v-for="h in c.holidays" :key="h.id" class="flex items-center justify-between py-2 text-sm">
                        <span><span class="font-mono text-xs text-muted">{{ h.date }}</span> <span class="ml-2 font-medium text-ink">{{ h.name }}</span> <span class="text-xs text-muted">({{ h.kind }})</span></span>
                        <AppButton variant="danger-ghost" size="sm" :icon="Trash2" :aria-label="`Remove ${h.name}`" @click="remove(h)" />
                    </li>
                    <li v-if="!c.holidays.length" class="py-2 text-sm text-muted">No holidays entered for {{ year }}.</li>
                </ul>
                <form class="mt-4 grid gap-3 sm:grid-cols-[10rem_1fr_10rem_auto] sm:items-end" @submit.prevent="addHoliday(c)">
                    <FormField label="Date" :error="holidayForms[c.id]!.errors.date"><TextInput v-model="holidayForms[c.id]!.date" type="date" /></FormField>
                    <FormField label="Holiday" :error="holidayForms[c.id]!.errors.name"><TextInput v-model="holidayForms[c.id]!.name" /></FormField>
                    <FormField label="Kind"><SelectInput v-model="holidayForms[c.id]!.kind" :options="['gazetted', 'restricted', 'local'].map((k) => ({ value: k, label: k }))" /></FormField>
                    <AppButton type="submit" variant="outline" :loading="holidayForms[c.id]!.processing">Add</AppButton>
                </form>
            </div>
        </section>
    </AppLayout>
</template>
