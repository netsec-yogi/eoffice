<script setup lang="ts">
import AlertBox from '@/Components/AlertBox.vue';
import AppButton from '@/Components/AppButton.vue';
import CheckboxInput from '@/Components/CheckboxInput.vue';
import FormField from '@/Components/FormField.vue';
import ModalDialog from '@/Components/ModalDialog.vue';
import MultiSelect from '@/Components/MultiSelect.vue';
import SelectInput from '@/Components/SelectInput.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ask } from '@/lib/confirm';
import AssigneeEditor, { type AssigneeSpec } from '@/Workflow/Designer/AssigneeEditor.vue';
import { HUMAN, NODE_META, outcomesFor, type Catalogue } from '@/Workflow/Designer/catalogue';
import RuleEditor from '@/Workflow/Designer/RuleEditor.vue';
import SlaEditor from '@/Workflow/Designer/SlaEditor.vue';
import StepNode from '@/Workflow/Designer/StepNode.vue';
import type { Rule } from '@/Workflow/rules';
import type { FormSchema } from '@/Workflow/types';
import type { RequestPayload } from '@inertiajs/core';
import { router, useForm } from '@inertiajs/vue3';
import { Background } from '@vue-flow/background';
import { Controls } from '@vue-flow/controls';
import { MarkerType, VueFlow, useVueFlow, type Connection, type Edge, type Node } from '@vue-flow/core';
import '@vue-flow/controls/dist/style.css';
import '@vue-flow/core/dist/style.css';
import '@vue-flow/core/dist/theme-default.css';
import { CircleAlert, CopyPlus, Rocket, Save, Settings2, Trash2, TriangleAlert } from 'lucide-vue-next';
import { computed, ref, watch, type Ref } from 'vue';

interface GraphNode { key: string; type: string; name: string; config: Record<string, unknown>; x: number; y: number }
interface GraphEdge { key: string; from_node: string; to_node: string; outcome: string; condition: Rule | null; priority: number; label: string | null }
interface Problem { node: string | null; message: string }

const props = defineProps<{
    definition: { id: number; key: string; name: string; description: string | null; category: string; department_id: number | null; numbering_pattern: string; is_active: boolean };
    version: { id: number; version: number; status: string; settings: Record<string, unknown>; form_version_id: number | null; change_note: string | null; published_at: string | null; nodes: GraphNode[]; edges: GraphEdge[]; form_schema: FormSchema | null };
    versions: { id: number; version: number; status: string; published_at: string | null; change_note: string | null }[];
    check: { errors: Problem[]; warnings: Problem[] };
    catalogue: Omit<Catalogue, 'departments'>;
    departments: { id: number; code: string; name: string }[];
    categories: string[];
    canPublish: boolean;
}>();

const readonly = computed(() => props.version.status !== 'draft');
const catalogue = computed<Catalogue>(() => ({ ...props.catalogue, departments: props.departments }));

// ---- graph state (Vue Flow ids are stable uuids; keys are editable data) ----
let seq = 0;
const uid = () => `n${Date.now().toString(36)}${(seq++).toString(36)}`;
const keyToId: Record<string, string> = {};
const nodes = ref(
    props.version.nodes.map((n) => {
        const id = uid();
        keyToId[n.key] = id;
        return { id, type: 'step', position: { x: n.x, y: n.y }, data: { key: n.key, type: n.type, name: n.name, config: n.config ?? {} } };
    }),
) as unknown as Ref<Node[]>;
const edgeLabel = (outcome: string, label: string | null, condition: Rule | null) => label || (outcome === 'approve' ? 'approved' : outcome === 'reject' ? 'rejected' : outcome === 'success' ? 'succeeded' : outcome === 'failure' ? 'failed' : condition ? 'if …' : '');
const edgeStyle = (outcome: string) => ({ stroke: outcome === 'reject' || outcome === 'failure' ? '#dc2626' : outcome === 'approve' || outcome === 'success' ? '#059669' : '#64748b', strokeWidth: 2 });
const edges = ref(
    props.version.edges.map((e) => ({
        id: uid(),
        source: keyToId[e.from_node]!,
        target: keyToId[e.to_node]!,
        markerEnd: MarkerType.ArrowClosed,
        label: edgeLabel(e.outcome, e.label, e.condition),
        style: edgeStyle(e.outcome),
        data: { outcome: e.outcome, condition: e.condition, priority: e.priority ?? 100, label: e.label },
    })),
) as unknown as Ref<Edge[]>;
const settings = ref<Record<string, unknown>>({ initiators: {}, initiator_can_cancel: true, ...props.version.settings });
const formVersionId = ref<number | null>(props.version.form_version_id);
const dirty = ref(false);
watch([nodes, edges, settings, formVersionId], () => (dirty.value = true), { deep: true });

const { onConnect, fitView } = useVueFlow();
onConnect((c: Connection) => {
    if (readonly.value) return;
    const source = nodes.value.find((n) => n.id === c.source);
    const type = source?.data.type as string;
    const used = edges.value.filter((e) => e.source === c.source).map((e) => e.data.outcome);
    const outcome = HUMAN.includes(type) ? (used.includes('approve') ? 'reject' : 'approve') : type === 'automation' ? (used.includes('success') ? 'failure' : 'success') : 'default';
    edges.value.push({ id: uid(), source: c.source, target: c.target, markerEnd: MarkerType.ArrowClosed, label: edgeLabel(outcome, null, null), style: edgeStyle(outcome), data: { outcome, condition: null, priority: 100, label: null } });
});

// ---- selection ----
const selectedNode = ref<Node | null>(null);
const selectedEdge = ref<Edge | null>(null);
const onNodeClick = ({ node }: { node: Node }) => ((selectedNode.value = node), (selectedEdge.value = null));
const onEdgeClick = ({ edge }: { edge: Edge }) => ((selectedEdge.value = edge), (selectedNode.value = null));
const onPaneClick = () => ((selectedNode.value = null), (selectedEdge.value = null));

function addNode(type: string) {
    const base = type.replace('parallel_', 'par_');
    let n = 1;
    const keys = nodes.value.map((x) => x.data.key);
    while (keys.includes(`${base}_${n}`)) n++;
    const defaults: Record<string, Record<string, unknown>> = {
        approval: { assignees: [{ type: 'reporting_manager', levels: 1 }], actions: ['approve', 'reject', 'return'] },
        review: { assignees: [], actions: ['approve', 'return'], labels: { approve: 'Recommend' } },
        verification: { assignees: [], actions: ['approve', 'reject', 'return'], labels: { approve: 'Verified' } },
        committee: { rule: 'majority' },
        end: { outcome: 'approved' },
        timer: { duration: 1, unit: 'working_days' },
        parallel_join: { wait: 'all' },
    };
    const node: Node = { id: uid(), type: 'step', position: { x: 80 + Math.random() * 200, y: 80 + nodes.value.length * 30 }, data: { key: `${base}_${n}`, type, name: NODE_META[type]?.label ?? type, config: defaults[type] ?? {} } };
    nodes.value.push(node);
    selectedNode.value = node;
}
async function removeSelected() {
    if (selectedNode.value) {
        if (selectedNode.value.data.type === 'start') return;
        if (!(await ask(`Delete step "${selectedNode.value.data.name}"? Its connections are deleted too.`))) return;
        const id = selectedNode.value.id;
        nodes.value = nodes.value.filter((n) => n.id !== id);
        edges.value = edges.value.filter((e) => e.source !== id && e.target !== id);
        selectedNode.value = null;
    } else if (selectedEdge.value) {
        const id = selectedEdge.value.id;
        edges.value = edges.value.filter((e) => e.id !== id);
        selectedEdge.value = null;
    }
}

// Keep edge labels/colours in sync with their data.
watch(
    edges,
    (list) =>
        list.forEach((e) => {
            // Assign only on change: this watcher observes what it writes.
            const label = edgeLabel(e.data.outcome, e.data.label, e.data.condition);
            if (e.label !== label) e.label = label;
            const style = edgeStyle(e.data.outcome);
            if ((e.style as { stroke?: string } | undefined)?.stroke !== style.stroke) e.style = style;
        }),
    { deep: true },
);

// ---- form fields offered to conditions and pickers ----
const schema = computed<FormSchema | null>(() => props.catalogue.forms.find((f) => f.id === formVersionId.value)?.schema ?? props.version.form_schema);
const formFields = computed(() => (schema.value?.sections ?? []).flatMap((s) => s.fields));
const ruleFields = computed(() => [
    ...validatorFields.value,
    ...formFields.value.filter((f) => !['readonly', 'signature', 'table', 'file', 'files'].includes(f.type)).map((f) => ({ value: f.key, label: f.label })),
    { value: 'initiator.category', label: 'Initiator category' },
    { value: 'initiator.department', label: 'Initiator department code' },
]);
const validatorFields = computed(() => (props.catalogue.validators.find((v) => v.key === settings.value.validator)?.provides ?? []).map((k) => ({ value: k, label: `${k} (computed)` })));
const dateFields = computed(() => [...formFields.value.filter((f) => f.type === 'date').map((f) => ({ value: f.key, label: f.label })), ...validatorFields.value]);
const personFields = computed(() => formFields.value.filter((f) => ['user', 'student'].includes(f.type)).map((f) => ({ value: f.key, label: f.label })));

// ---- problems from the last server check ----
const problemsByKey = computed(() => {
    const out: Record<string, number> = {};
    props.check.errors.forEach((p) => p.node && (out[p.node] = (out[p.node] ?? 0) + 1));
    return out;
});
watch(
    () => [props.check, nodes.value.length],
    () =>
        nodes.value.forEach((n) => {
            const count = problemsByKey.value[n.data.key] ?? 0;
            if (n.data.problems !== count) n.data.problems = count;
        }),
    { immediate: true },
);
const focusProblem = (p: Problem) => {
    const node = nodes.value.find((n) => n.data.key === p.node);
    if (node) onNodeClick({ node });
};

// ---- save / publish ----
const saving = ref(false);
function payload() {
    const idToKey = Object.fromEntries(nodes.value.map((n) => [n.id, n.data.key]));
    return {
        nodes: nodes.value.map((n) => ({ key: n.data.key, type: n.data.type, name: n.data.name, config: n.data.config, x: Math.round(n.position.x), y: Math.round(n.position.y) })),
        edges: edges.value.map((e, i) => ({ key: `t${i + 1}`, from_node: idToKey[e.source], to_node: idToKey[e.target], outcome: e.data.outcome, condition: e.data.condition, priority: e.data.priority, label: e.data.label })),
        settings: settings.value,
        form_version_id: formVersionId.value,
    } as unknown as RequestPayload;
}
const save = (then?: () => void) =>
    router.put(route('admin.workflows.versions.save', props.version.id), payload(), {
        preserveScroll: true,
        preserveState: true,
        onStart: () => (saving.value = true),
        onFinish: () => (saving.value = false),
        onSuccess: () => {
            dirty.value = false;
            then?.();
        },
    });

const publishOpen = ref(false);
const publish = useForm({ note: '' });
const doPublish = () => save(() => publish.post(route('admin.workflows.versions.publish', props.version.id), { onSuccess: () => (publishOpen.value = false) }));
const newDraft = () => router.post(route('admin.workflows.draft', props.definition.id));
const openVersion = (id?: number | string | null) => router.get(route('admin.workflows.edit', props.definition.id), { version: id });

// ---- definition details ----
const detailsOpen = ref(false);
const details = useForm({ ...props.definition });
const saveDetails = () => details.transform(({ id: _id, key: _key, ...rest }) => rest).put(route('admin.workflows.update', props.definition.id), { preserveScroll: true, onSuccess: () => (detailsOpen.value = false) });

// ---- helpers for the property panel ----
const cfg = computed(() => (selectedNode.value?.data.config ?? {}) as Record<string, unknown>);
const setCfg = (patch: Record<string, unknown>) => selectedNode.value && (selectedNode.value.data.config = { ...cfg.value, ...patch });
const actionOptions = ['approve', 'reject', 'return', 'forward', 'hold'];
const initiators = computed(() => (settings.value.initiators ?? {}) as Record<string, string[]>);
const setInitiators = (patch: Record<string, string[]>) => (settings.value = { ...settings.value, initiators: { ...initiators.value, ...patch } });
const sourceType = computed(() => nodes.value.find((n) => n.id === selectedEdge.value?.source)?.data.type as string | undefined);
</script>

<template>
    <AppLayout :title="`Design: ${definition.name}`">
        <header class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <p class="font-mono text-xs text-muted">{{ definition.key }} · {{ definition.category.replace(/_/g, ' ') }}</p>
                <h1 class="flex items-center gap-2 text-xl font-bold text-ink">
                    {{ definition.name }}
                    <StatusBadge :status="version.status" :label="`v${version.version} ${version.status}`" />
                    <span v-if="dirty && !readonly" class="text-xs font-normal text-amber-700">unsaved changes</span>
                </h1>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <SelectInput :model-value="version.id" :options="versions.map((v) => ({ value: v.id, label: `v${v.version} · ${v.status}` }))" class="!w-40" aria-label="Version" @update:model-value="openVersion" />
                <AppButton variant="outline" size="sm" :icon="Settings2" @click="detailsOpen = true">Details</AppButton>
                <template v-if="readonly">
                    <AppButton size="sm" :icon="CopyPlus" @click="newDraft">Open new draft</AppButton>
                </template>
                <template v-else>
                    <AppButton variant="outline" size="sm" :icon="Save" :loading="saving" @click="save()">Save & check</AppButton>
                    <AppButton v-if="canPublish" size="sm" :icon="Rocket" :disabled="check.errors.length > 0 && !dirty" @click="publishOpen = true">Publish</AppButton>
                </template>
            </div>
        </header>

        <AlertBox v-if="readonly" tone="info" class="mb-4">
            Version {{ version.version }} is {{ version.status }} and cannot be changed. Requests started on it keep using it. Open a new draft to make changes.
        </AlertBox>

        <div class="grid gap-4 xl:grid-cols-[11rem_1fr_22rem]">
            <!-- Palette -->
            <aside v-if="!readonly" class="card h-fit p-3" aria-label="Add a step">
                <p class="mb-2 px-1 text-xs font-semibold tracking-wider text-subtle uppercase">Add step</p>
                <div class="grid grid-cols-2 gap-1.5 xl:grid-cols-1">
                    <button v-for="t in catalogue.node_types.filter((t) => t !== 'start')" :key="t" class="flex items-center gap-2 rounded-xl px-2 py-1.5 text-left text-sm text-ink-soft hover:bg-surface-sunken" :title="NODE_META[t]?.hint" @click="addNode(t)">
                        <span :class="['size-2.5 shrink-0 rounded-full', NODE_META[t]?.tone]" aria-hidden="true" />{{ NODE_META[t]?.label ?? t }}
                    </button>
                </div>
            </aside>
            <div v-else class="hidden xl:block" />

            <!-- Canvas -->
            <div class="card h-[70vh] min-h-[480px] overflow-hidden p-0">
                <VueFlow
                    v-model:nodes="nodes"
                    v-model:edges="edges"
                    :nodes-draggable="!readonly"
                    :nodes-connectable="!readonly"
                    :elements-selectable="true"
                    :default-edge-options="{ type: 'smoothstep' }"
                    fit-view-on-init
                    @node-click="onNodeClick"
                    @edge-click="onEdgeClick"
                    @pane-click="onPaneClick"
                    @nodes-initialized="fitView()"
                >
                    <template #node-step="nodeProps"><StepNode :data="nodeProps.data" :selected="nodeProps.selected" /></template>
                    <Background :gap="18" pattern-color="#cbd5e1" />
                    <Controls :show-interactive="false" />
                </VueFlow>
            </div>

            <!-- Properties -->
            <aside class="card h-fit max-h-[70vh] space-y-4 overflow-y-auto p-4" aria-label="Properties">
                <template v-if="selectedNode">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-semibold tracking-wider text-subtle uppercase">{{ NODE_META[selectedNode.data.type]?.label }} step</p>
                        <AppButton v-if="!readonly && selectedNode.data.type !== 'start'" variant="danger-ghost" size="sm" :icon="Trash2" aria-label="Delete step" @click="removeSelected" />
                    </div>
                    <fieldset :disabled="readonly" class="space-y-3">
                        <FormField label="Name"><TextInput v-model="selectedNode.data.name" /></FormField>
                        <FormField label="Key" hint="Stable identifier, lower_snake_case."><TextInput v-model="selectedNode.data.key" /></FormField>

                        <template v-if="['approval', 'review', 'verification', 'signature'].includes(selectedNode.data.type)">
                            <AlertBox v-if="selectedNode.data.type === 'signature' && !catalogue.signature_provider" tone="warning">No signature provider is configured; this step cannot be published yet.</AlertBox>
                            <FormField label="Who acts" hint="Resolved when the step is reached; delegation and separation of duties apply.">
                                <AssigneeEditor :model-value="(cfg.assignees as AssigneeSpec[]) ?? []" :catalogue="catalogue" :person-fields="personFields" :disabled="readonly" @update:model-value="setCfg({ assignees: $event })" />
                            </FormField>
                            <FormField label="If several people are found">
                                <SelectInput :model-value="(cfg.mode as string) ?? 'any'" :options="[{ value: 'any', label: 'Any one of them decides' }, { value: 'all', label: 'All must approve' }]" @update:model-value="setCfg({ mode: $event })" />
                            </FormField>
                            <fieldset>
                                <legend class="mb-1 text-sm font-medium text-ink">Allowed actions</legend>
                                <div class="grid grid-cols-2 gap-1"><CheckboxInput v-for="a in actionOptions" :key="a" :model-value="(cfg.actions as string[]) ?? ['approve', 'reject', 'return']" :value="a" :label="a" @update:model-value="setCfg({ actions: $event })" /></div>
                            </fieldset>
                            <FormField label="Label for approve" hint="e.g. Recommend, Sanction, Verified"><TextInput :model-value="((cfg.labels as Record<string, string>) ?? {}).approve ?? ''" @update:model-value="setCfg({ labels: { ...((cfg.labels as object) ?? {}), approve: $event } })" /></FormField>
                            <FormField label="Instructions to the approver"><TextArea :model-value="(cfg.instructions as string) ?? ''" rows="2" @update:model-value="setCfg({ instructions: $event })" /></FormField>
                            <CheckboxInput :model-value="!!cfg.allow_self" label="Initiator may act on their own request (normally off)" @update:model-value="setCfg({ allow_self: $event })" />
                            <FormField label="Time limit"><SlaEditor :model-value="cfg.sla as never" :catalogue="catalogue" :person-fields="personFields" :date-fields="dateFields" :disabled="readonly" @update:model-value="setCfg({ sla: $event })" /></FormField>
                        </template>

                        <template v-else-if="selectedNode.data.type === 'committee'">
                            <FormField label="Committee"><SelectInput :model-value="(cfg.committee as string) ?? ''" :options="catalogue.committees.map((c) => ({ value: c.code, label: c.name }))" @update:model-value="setCfg({ committee: $event })" /></FormField>
                            <FormField label="Decision rule" hint="Stated explicitly; nothing is assumed.">
                                <SelectInput :model-value="(cfg.rule as string) ?? ''" :options="[{ value: 'any', label: 'Any one approval' }, { value: 'all', label: 'Unanimous' }, { value: 'majority', label: 'Majority of members' }, { value: 'quorum', label: 'At least N approvals' }, { value: 'weighted', label: 'Weighted votes ≥ threshold' }]" @update:model-value="setCfg({ rule: $event })" />
                            </FormField>
                            <FormField v-if="cfg.rule === 'quorum'" label="Approvals required (N)"><TextInput :model-value="(cfg.quorum as number) ?? ''" type="number" min="1" @update:model-value="setCfg({ quorum: Number($event) })" /></FormField>
                            <FormField v-if="cfg.rule === 'weighted'" label="Threshold (share of total weight)" hint="e.g. 0.66 for two-thirds"><TextInput :model-value="(cfg.threshold as number) ?? ''" type="number" min="0.01" max="1" step="0.01" @update:model-value="setCfg({ threshold: Number($event) })" /></FormField>
                            <FormField label="Time limit"><SlaEditor :model-value="cfg.sla as never" :catalogue="catalogue" :person-fields="personFields" :disabled="readonly" @update:model-value="setCfg({ sla: $event })" /></FormField>
                        </template>

                        <p v-else-if="selectedNode.data.type === 'decision'" class="text-sm text-muted">Select each outgoing connection to set its condition. The connection without a condition is taken when none of the others match.</p>

                        <FormField v-else-if="selectedNode.data.type === 'parallel_join'" label="Continue when">
                            <SelectInput :model-value="(cfg.wait as string) ?? 'all'" :options="[{ value: 'all', label: 'All branches are done' }, { value: 'any', label: 'The first branch is done (others are withdrawn)' }]" @update:model-value="setCfg({ wait: $event })" />
                        </FormField>

                        <template v-else-if="selectedNode.data.type === 'automation'">
                            <FormField label="Action"><SelectInput :model-value="(cfg.action as string) ?? ''" :options="Object.entries(catalogue.actions).map(([k, a]) => ({ value: k, label: a.label }))" @update:model-value="setCfg({ action: $event, settings: {} })" /></FormField>
                            <template v-for="(s, k) in catalogue.actions[cfg.action as string]?.settings ?? {}" :key="k">
                                <FormField :label="s.label" :required="s.required">
                                    <TextArea v-if="s.type === 'map'" :model-value="JSON.stringify(((cfg.settings as Record<string, unknown>) ?? {})[k] ?? {}, null, 1)" rows="3" class="font-mono text-xs" @update:model-value="(v) => { try { setCfg({ settings: { ...((cfg.settings as object) ?? {}), [k]: JSON.parse(v ?? '{}') } }); } catch {} }" />
                                    <TextInput v-else :model-value="(((cfg.settings as Record<string, unknown>) ?? {})[k] as string) ?? ''" @update:model-value="setCfg({ settings: { ...((cfg.settings as object) ?? {}), [k]: $event } })" />
                                </FormField>
                            </template>
                            <FormField label="If it cannot run, ask" hint="This person completes the step by hand.">
                                <AssigneeEditor :model-value="cfg.on_blocked ? [cfg.on_blocked as AssigneeSpec] : []" single :catalogue="catalogue" :person-fields="personFields" :disabled="readonly" @update:model-value="setCfg({ on_blocked: $event[0] ?? null })" />
                            </FormField>
                        </template>

                        <template v-else-if="selectedNode.data.type === 'notification'">
                            <FormField label="Template"><SelectInput :model-value="(cfg.template as string) ?? ''" :options="catalogue.templates.map((t) => ({ value: t, label: t }))" @update:model-value="setCfg({ template: $event })" /></FormField>
                            <FormField label="Send to"><AssigneeEditor :model-value="(cfg.to as AssigneeSpec[]) ?? [{ type: 'initiator' }]" :catalogue="catalogue" :person-fields="personFields" :disabled="readonly" @update:model-value="setCfg({ to: $event })" /></FormField>
                        </template>

                        <template v-else-if="selectedNode.data.type === 'timer'">
                            <div class="grid grid-cols-2 gap-2">
                                <FormField label="Wait"><TextInput :model-value="(cfg.duration as number) ?? 1" type="number" min="0.5" step="0.5" @update:model-value="setCfg({ duration: Number($event) })" /></FormField>
                                <FormField label="Unit"><SelectInput :model-value="(cfg.unit as string) ?? 'working_days'" :options="catalogue.units.map((u) => ({ value: u, label: u.replace('_', ' ') }))" @update:model-value="setCfg({ unit: $event })" /></FormField>
                            </div>
                        </template>

                        <FormField v-else-if="selectedNode.data.type === 'end'" label="Outcome">
                            <SelectInput :model-value="(cfg.outcome as string) ?? 'approved'" :options="[{ value: 'approved', label: 'Approved' }, { value: 'rejected', label: 'Not approved' }, { value: 'cancelled', label: 'Cancelled' }]" @update:model-value="setCfg({ outcome: $event })" />
                        </FormField>
                    </fieldset>
                </template>

                <template v-else-if="selectedEdge">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-semibold tracking-wider text-subtle uppercase">Connection</p>
                        <AppButton v-if="!readonly" variant="danger-ghost" size="sm" :icon="Trash2" aria-label="Delete connection" @click="removeSelected" />
                    </div>
                    <fieldset :disabled="readonly" class="space-y-3">
                        <FormField label="Taken when"><SelectInput v-model="selectedEdge.data.outcome" :options="[...outcomesFor(sourceType ?? ''), ...(outcomesFor(sourceType ?? '').some((o) => o.value === 'default') ? [] : [{ value: 'default', label: 'Next (any positive outcome)' }])]" /></FormField>
                        <FormField label="Label"><TextInput v-model="selectedEdge.data.label" placeholder="optional" /></FormField>
                        <FormField label="Only if" hint="Leave empty for the default route.">
                            <RuleEditor v-model="selectedEdge.data.condition" :fields="ruleFields" :disabled="readonly" empty-label="No condition: always (or when no other route matches)." />
                        </FormField>
                        <FormField label="Order" hint="Lower numbers are checked first."><TextInput v-model.number="selectedEdge.data.priority" type="number" min="0" /></FormField>
                    </fieldset>
                </template>

                <template v-else>
                    <p class="text-xs font-semibold tracking-wider text-subtle uppercase">Workflow settings</p>
                    <fieldset :disabled="readonly" class="space-y-3">
                        <FormField label="Form" hint="A published form version. New form versions must be attached here.">
                            <SelectInput v-model="formVersionId" :options="catalogue.forms.map((f) => ({ value: f.id, label: f.label }))" placeholder="No form" />
                        </FormField>
                        <FormField label="Who may start it" hint="Empty = every signed-in user.">
                            <div class="space-y-1.5">
                                <MultiSelect :model-value="initiators.categories ?? []" :options="['faculty', 'staff', 'student', 'external', 'vendor'].map((c) => ({ value: c, label: c }))" placeholder="Any category" @update:model-value="setInitiators({ categories: $event })" />
                                <MultiSelect :model-value="initiators.roles ?? []" :options="catalogue.roles.map((r) => ({ value: r, label: r.replace(/_/g, ' ') }))" placeholder="Any role" @update:model-value="setInitiators({ roles: $event })" />
                                <MultiSelect :model-value="initiators.departments ?? []" :options="departments.map((d) => ({ value: d.code, label: d.name }))" placeholder="Any department" @update:model-value="setInitiators({ departments: $event })" />
                            </div>
                        </FormField>
                        <FormField label="Business checks on submission" hint="Module rules run on the server after the form's own checks (e.g. leave balance).">
                            <SelectInput :model-value="(settings.validator as string) ?? ''" :options="catalogue.validators.map((v) => ({ value: v.key, label: v.label }))" placeholder="None" @update:model-value="settings = { ...settings, validator: $event || null }" />
                        </FormField>
                        <FormField label="Fallback approver" hint="Receives a step nobody else can take.">
                            <AssigneeEditor :model-value="settings.fallback ? [settings.fallback as AssigneeSpec] : []" single :catalogue="catalogue" :person-fields="personFields" :disabled="readonly" @update:model-value="settings = { ...settings, fallback: $event[0] ?? null }" />
                        </FormField>
                        <FormField label="Request title" hint="e.g. Leave {{ from_date }} – {{ to_date }}. Uses form field keys."><TextInput :model-value="(settings.title_template as string) ?? ''" @update:model-value="settings = { ...settings, title_template: $event }" /></FormField>
                        <CheckboxInput :model-value="settings.initiator_can_cancel !== false" label="Initiator may cancel while open" @update:model-value="settings = { ...settings, initiator_can_cancel: $event }" />
                    </fieldset>

                    <div v-if="check.errors.length || check.warnings.length" class="space-y-2 border-t border-line-soft pt-3">
                        <p class="text-xs font-semibold tracking-wider text-subtle uppercase">Last check</p>
                        <button v-for="(p, i) in check.errors" :key="`e${i}`" class="flex w-full gap-2 rounded-lg p-1.5 text-left text-sm text-red-700 hover:bg-red-50" @click="focusProblem(p)">
                            <CircleAlert :size="15" class="mt-0.5 shrink-0" aria-hidden="true" />{{ p.message }}
                        </button>
                        <button v-for="(p, i) in check.warnings" :key="`w${i}`" class="flex w-full gap-2 rounded-lg p-1.5 text-left text-sm text-amber-800 hover:bg-amber-50" @click="focusProblem(p)">
                            <TriangleAlert :size="15" class="mt-0.5 shrink-0" aria-hidden="true" />{{ p.message }}
                        </button>
                    </div>
                    <p v-else-if="!readonly" class="border-t border-line-soft pt-3 text-sm text-emerald-700">No problems found in the last check.</p>
                    <p class="text-xs text-muted">Click a step or connection to edit it. Drag from a step's bottom handle to another step to connect them.</p>
                </template>
            </aside>
        </div>

        <ModalDialog :show="publishOpen" title="Publish this version?" description="New requests will use it immediately. Requests already running keep the version they started on." @close="publishOpen = false">
            <form class="space-y-4" @submit.prevent="doPublish">
                <AlertBox v-if="check.warnings.length" tone="warning">{{ check.warnings.length }} warning(s) from the last check. Review them before publishing.</AlertBox>
                <FormField label="What changed" hint="Kept with the version and in the audit log." :error="publish.errors.note ?? (publish.errors as Record<string, string>).graph ?? (publish.errors as Record<string, string>).workflow" required><TextArea v-model="publish.note" rows="3" /></FormField>
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="publishOpen = false">Cancel</AppButton><AppButton type="submit" :icon="Rocket" :loading="publish.processing">Publish</AppButton></div>
            </form>
        </ModalDialog>

        <ModalDialog :show="detailsOpen" title="Workflow details" @close="detailsOpen = false">
            <form class="space-y-4" @submit.prevent="saveDetails">
                <FormField label="Name" :error="details.errors.name" required><TextInput v-model="details.name" /></FormField>
                <FormField label="Description" hint="Shown to users when they choose a service." :error="details.errors.description"><TextArea v-model="details.description" rows="2" /></FormField>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Category" :error="details.errors.category"><SelectInput v-model="details.category" :options="categories.map((c) => ({ value: c, label: c.replace(/_/g, ' ') }))" /></FormField>
                    <FormField label="Owning department" :error="details.errors.department_id"><SelectInput v-model="details.department_id" :options="departments.map((d) => ({ value: d.id, label: d.name }))" placeholder="Institute" /></FormField>
                </div>
                <FormField label="Reference format" hint="{KEY}, {YYYY}, {YY}, {SEQ:5}" :error="details.errors.numbering_pattern"><TextInput v-model="details.numbering_pattern" /></FormField>
                <CheckboxInput v-model="details.is_active" label="Available to start new requests" />
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="detailsOpen = false">Cancel</AppButton><AppButton type="submit" :loading="details.processing">Save</AppButton></div>
            </form>
        </ModalDialog>
    </AppLayout>
</template>
