<script setup lang="ts">
import AlertBox from '@/Components/AlertBox.vue';
import AppButton from '@/Components/AppButton.vue';
import CheckboxInput from '@/Components/CheckboxInput.vue';
import FormField from '@/Components/FormField.vue';
import PageHeader from '@/Components/PageHeader.vue';
import SelectInput from '@/Components/SelectInput.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ask } from '@/lib/confirm';
import { inr } from '@/lib/money';
import { router, useForm } from '@inertiajs/vue3';
import { Plus, Send, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';

interface Band { up_to: number | string | null; method: string; label: string; quotations: number; committee: boolean }
interface RuleSet { id: number; version: number; status: string; title: string; authority: string | null; bands: Band[]; effective_from: string | null; published_at: string | null; published_by: string | null }
const props = defineProps<{ sets: RuleSet[]; methods: { value: string; label: string }[]; canEdit: boolean }>();

const draft = computed(() => props.sets.find((s) => s.status === 'draft') ?? null);
const current = computed(() => props.sets.find((s) => s.status === 'published') ?? null);
const base = draft.value ?? current.value;
const form = useForm({
    title: base?.title ?? '',
    authority: base?.authority ?? '',
    effective_from: base?.effective_from ?? '',
    bands: (base?.bands ?? [{ up_to: null, method: '', label: '', quotations: 0, committee: false }]).map((b) => ({ ...b, up_to: b.up_to ?? '' })),
});
const method = (m: string) => props.methods.find((x) => x.value === m)?.label ?? m;
const addBand = () => form.bands.splice(form.bands.length - 1, 0, { up_to: '', method: '', label: '', quotations: 0, committee: false });
const save = () => form.transform((d) => ({ ...d, bands: d.bands.map((b) => ({ ...b, up_to: b.up_to === '' ? null : b.up_to })) })).post(route('procurement.rules.store'), { preserveScroll: true });
async function publish(set: RuleSet) {
    if (await ask(`Publish version ${set.version}? New indents will use it at once; earlier cases keep the rules they were decided under. Published versions cannot be edited.`, { confirmLabel: 'Publish' })) {
        router.post(route('procurement.rules.publish', set.id), { confirm: true }, { preserveScroll: true });
    }
}
const lower = (i: number, bands: Band[]) => (i === 0 ? 0 : Number(bands[i - 1].up_to));
</script>

<template>
    <AppLayout title="Procurement rules">
        <PageHeader title="Procurement rules" description="Which method applies to which value. Finance enters and publishes these from the orders in force; the system never assumes a limit." :breadcrumbs="[{ label: 'Purchases', href: route('procurement.index') }, { label: 'Rules' }]" />

        <AlertBox v-if="!current" tone="danger" class="mb-6">No rule set is published. Indents cannot be raised until Finance publishes one.</AlertBox>
        <AlertBox v-else-if="/demo/i.test(current.title)" tone="warning" class="mb-6">The rules in force are <strong>demonstration values</strong>, not the institute's. Replace them before go-live.</AlertBox>

        <section v-if="current" class="card mb-6 p-6">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="font-semibold text-ink">In force: version {{ current.version }} — {{ current.title }}</h2>
                <p class="text-xs text-muted">Published {{ current.published_at?.slice(0, 10) }} by {{ current.published_by }}</p>
            </div>
            <p class="mt-1 text-sm text-muted">{{ current.authority }}</p>
            <table class="data-table mt-4">
                <thead><tr><th>Value</th><th>Method</th><th>Quotations</th><th>Committee</th></tr></thead>
                <tbody>
                    <tr v-for="(b, i) in current.bands" :key="i">
                        <td class="tabular-nums">{{ b.up_to === null ? `above ${inr(lower(i, current.bands))}` : `${inr(lower(i, current.bands))} – ${inr(b.up_to)}` }}<p class="text-xs text-muted">{{ b.label }}</p></td>
                        <td>{{ method(b.method) }}</td><td>{{ b.quotations || '—' }}</td><td>{{ b.committee ? 'Yes' : 'No' }}</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section v-if="canEdit" class="card mb-6 space-y-4 p-6">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-semibold text-ink">{{ draft ? `Draft version ${draft.version}` : 'New version' }}</h2>
                <AppButton v-if="draft" :icon="Send" @click="publish(draft)">Publish</AppButton>
            </div>
            <p v-if="form.errors.bands" class="text-sm text-red-700">{{ form.errors.bands }}</p>
            <div class="grid gap-4 sm:grid-cols-3">
                <FormField label="Title" :error="form.errors.title" required class="sm:col-span-2"><TextInput v-model="form.title" /></FormField>
                <FormField label="Effective from" :error="form.errors.effective_from"><TextInput v-model="form.effective_from" type="date" /></FormField>
            </div>
            <FormField label="Authority relied upon (rule, O.M., office order)" :error="form.errors.authority" required><TextArea v-model="form.authority" rows="2" /></FormField>
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead><tr><th>Up to (₹, blank = no limit)</th><th>Method</th><th>Label</th><th>Quotations</th><th>Committee</th><th><span class="sr-only">Remove</span></th></tr></thead>
                    <tbody>
                        <tr v-for="(b, i) in form.bands" :key="i">
                            <td><TextInput v-model="b.up_to" type="number" step="0.01" min="0" :disabled="i === form.bands.length - 1" :placeholder="i === form.bands.length - 1 ? 'no limit' : ''" :aria-label="`Band ${i + 1} limit`" /></td>
                            <td><SelectInput v-model="b.method" :options="methods" placeholder="Choose" :aria-label="`Band ${i + 1} method`" /></td>
                            <td><TextInput v-model="b.label" :aria-label="`Band ${i + 1} label`" /></td>
                            <td><TextInput v-model="b.quotations" type="number" min="0" class="!w-20" :aria-label="`Band ${i + 1} quotations`" /></td>
                            <td><CheckboxInput v-model="b.committee" :label="''" :aria-label="`Band ${i + 1} committee`" /></td>
                            <td><AppButton v-if="form.bands.length > 1 && i < form.bands.length - 1" size="sm" variant="ghost" :icon="Trash2" aria-label="Remove band" @click="form.bands.splice(i, 1)" /></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="flex gap-2">
                <AppButton variant="outline" :icon="Plus" @click="addBand">Add band</AppButton>
                <AppButton :loading="form.processing" @click="save">Save draft</AppButton>
            </div>
        </section>

        <section class="card p-6">
            <h2 class="mb-3 font-semibold text-ink">History</h2>
            <ul class="divide-y divide-border">
                <li v-for="s in sets" :key="s.id" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                    <span>Version {{ s.version }} — {{ s.title }}</span>
                    <span class="flex items-center gap-3 text-xs text-muted">{{ s.published_at?.slice(0, 10) }} <StatusBadge :status="s.status" /></span>
                </li>
            </ul>
        </section>
    </AppLayout>
</template>
