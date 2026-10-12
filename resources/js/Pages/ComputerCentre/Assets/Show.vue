<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import FormField from '@/Components/FormField.vue';
import ModalDialog from '@/Components/ModalDialog.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextArea from '@/Components/TextArea.vue';
import UserPicker from '@/Components/UserPicker.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { date } from '@/Workflow/format';
import { Link, useForm } from '@inertiajs/vue3';
import { Pencil } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import AssetForm, { type AssetFields } from './AssetForm.vue';

interface Movement { id: number; action: string; from: string | null; to: string | null; by: string; remarks: string | null; workflow_instance_id: number | null; at: string }
const props = defineProps<{ asset: AssetFields & { id: number; status: string; custodian: { name: string; username: string } | null; movements: Movement[] }; categories: string[] }>();

const actions = computed(() => ({ in_stock: ['issue', 'repair', 'dispose'], issued: ['return', 'transfer', 'repair'], repair: ['repaired', 'dispose'], disposed: [] })[props.asset.status as 'in_stock'] ?? []);
const labels: Record<string, string> = { issue: 'Issue', return: 'Receive back', transfer: 'Transfer', repair: 'Send for repair', repaired: 'Back from repair', dispose: 'Dispose', register: 'Registered' };
const doing = ref<string | null>(null);
const move = useForm({ action: '', to: null as number | null, remarks: '' });
const start = (a: string) => ((doing.value = a), move.reset(), (move.action = a));
const submit = () => move.post(route('cc.assets.move', props.asset.id), { preserveScroll: true, onSuccess: () => (doing.value = null) });

const editing = ref(false);
const edit = useForm<AssetFields>({ ...props.asset });
const save = () => edit.put(route('cc.assets.update', props.asset.id), { preserveScroll: true, onSuccess: () => (editing.value = false) });
</script>

<template>
    <AppLayout :title="asset.asset_number">
        <PageHeader :title="asset.asset_number" :description="[asset.category, asset.manufacturer, asset.model].filter(Boolean).join(' · ')">
            <StatusBadge :status="asset.status" :label="asset.status.replace('_', ' ')" />
            <AppButton variant="outline" size="sm" :icon="Pencil" @click="editing = true">Edit</AppButton>
        </PageHeader>
        <div class="grid gap-6 lg:grid-cols-3">
            <section class="card p-6 lg:col-span-2">
                <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-muted">Custodian</dt><dd class="font-medium text-ink">{{ asset.custodian?.name ?? 'In store' }}</dd></div>
                    <div><dt class="text-muted">Location</dt><dd class="text-ink">{{ asset.location ?? '—' }}</dd></div>
                    <div><dt class="text-muted">Serial number</dt><dd class="font-mono text-ink">{{ asset.serial_number ?? '—' }}</dd></div>
                    <div><dt class="text-muted">Warranty until</dt><dd class="text-ink">{{ asset.warranty_until ?? '—' }}</dd></div>
                    <div><dt class="text-muted">Purchased</dt><dd class="text-ink">{{ asset.purchase_date ?? '—' }} <span v-if="asset.purchase_reference" class="text-muted">({{ asset.purchase_reference }})</span></dd></div>
                    <div><dt class="text-muted">Cost</dt><dd class="text-ink">{{ asset.cost ? `₹ ${Number(asset.cost).toLocaleString('en-IN')}` : '—' }}</dd></div>
                    <div v-if="asset.notes" class="sm:col-span-2"><dt class="text-muted">Notes</dt><dd class="whitespace-pre-line text-ink">{{ asset.notes }}</dd></div>
                </dl>
                <div v-if="actions.length" class="mt-6 flex flex-wrap gap-2 border-t border-line-soft pt-4">
                    <AppButton v-for="a in actions" :key="a" size="sm" :variant="a === 'dispose' ? 'danger-ghost' : 'outline'" @click="start(a)">{{ labels[a] }}</AppButton>
                </div>
            </section>
            <section class="card p-5">
                <h2 class="mb-3 font-semibold text-ink">History</h2>
                <ol class="space-y-3 text-sm">
                    <li v-for="m in asset.movements" :key="m.id">
                        <p class="font-medium text-ink">{{ labels[m.action] ?? m.action }}<template v-if="m.to"> → {{ m.to }}</template><template v-else-if="m.from"> from {{ m.from }}</template></p>
                        <p class="text-xs text-muted">{{ m.by }} · {{ date(m.at) }}<template v-if="m.workflow_instance_id"> · <Link :href="route('requests.show', m.workflow_instance_id)" class="text-brand-700 hover:underline">request</Link></template></p>
                        <p v-if="m.remarks" class="text-xs text-ink-soft">{{ m.remarks }}</p>
                    </li>
                </ol>
            </section>
        </div>
        <ModalDialog :show="!!doing" :title="`${labels[doing ?? ''] ?? ''}: ${asset.asset_number}`" @close="doing = null">
            <form class="space-y-4" @submit.prevent="submit">
                <FormField v-if="doing === 'issue' || doing === 'transfer'" label="To" :error="move.errors.to" required><UserPicker v-model="move.to" /></FormField>
                <FormField label="Remarks" :error="move.errors.remarks ?? move.errors.action" :required="doing === 'dispose' || doing === 'repair'"><TextArea v-model="move.remarks" rows="2" /></FormField>
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="doing = null">Cancel</AppButton><AppButton type="submit" :variant="doing === 'dispose' ? 'danger' : 'primary'" :loading="move.processing">{{ labels[doing ?? ''] }}</AppButton></div>
            </form>
        </ModalDialog>
        <ModalDialog :show="editing" title="Edit asset" size="lg" @close="editing = false">
            <form class="space-y-4" @submit.prevent="save">
                <AssetForm :form="edit" :categories="categories" />
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="editing = false">Cancel</AppButton><AppButton type="submit" :loading="edit.processing">Save</AppButton></div>
            </form>
        </ModalDialog>
    </AppLayout>
</template>
