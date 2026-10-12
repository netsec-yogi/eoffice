<script setup lang="ts">
import { toast } from '@/lib/toast';
import { FileText, LoaderCircle, Paperclip, ShieldAlert, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import type { DocumentSummary } from './types';

/**
 * Uploads go to /documents first (validated, scanned, private to the
 * uploader); the form stores only the document id. The server checks on
 * submit that every id is the submitter's own upload.
 */
const props = defineProps<{ multiple?: boolean; accept?: string[]; maxFiles?: number; readonly?: boolean; display?: unknown }>();
const model = defineModel<unknown>();

const known = ref<Record<string, DocumentSummary>>(Object.fromEntries(((props.display as DocumentSummary[] | undefined) ?? []).map((d) => [d.id, d])));
const ids = computed<string[]>(() => (Array.isArray(model.value) ? (model.value as string[]) : model.value ? [model.value as string] : []));
const busy = ref(false);
const input = ref<HTMLInputElement>();

function xsrf() {
    return decodeURIComponent(document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
}

async function upload(e: Event) {
    const files = [...((e.target as HTMLInputElement).files ?? [])];
    (e.target as HTMLInputElement).value = '';
    for (const file of files) {
        if (props.multiple && ids.value.length >= (props.maxFiles ?? 10)) break;
        busy.value = true;
        const body = new FormData();
        body.append('file', file);
        try {
            const res = await fetch(route('documents.store'), { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() } });
            const json = await res.json();
            if (!res.ok) throw new Error(json.errors?.file?.[0] ?? json.message ?? 'Upload failed');
            known.value[json.id] = json;
            model.value = props.multiple ? [...ids.value, json.id] : json.id;
        } catch (err) {
            toast(`${file.name}: ${(err as Error).message}`, 'error');
        } finally {
            busy.value = false;
        }
    }
}
const remove = (id: string) => (model.value = props.multiple ? ids.value.filter((x) => x !== id) : null);
const kb = (n: number) => (n > 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.ceil(n / 1024)} KB`);
</script>

<template>
    <div class="space-y-2">
        <ul v-if="ids.length" class="space-y-1.5">
            <li v-for="id in ids" :key="id" class="flex items-center gap-2 rounded-xl bg-surface-muted px-3 py-2 text-sm">
                <FileText :size="16" class="shrink-0 text-muted" aria-hidden="true" />
                <a v-if="known[id]" :href="known[id]!.url + (known[id]!.preview ? '?inline=1' : '')" target="_blank" rel="noopener" class="min-w-0 flex-1 truncate font-medium text-brand-700 hover:underline">{{ known[id]!.name }}</a>
                <span v-else class="flex-1 text-muted">Attached file</span>
                <span v-if="known[id]" class="text-xs text-muted">{{ kb(known[id]!.size) }}</span>
                <ShieldAlert v-if="known[id]?.scan_status === 'unscanned'" :size="14" class="text-amber-600" aria-label="Not scanned for malware" />
                <button v-if="!readonly" type="button" class="rounded p-0.5 text-muted hover:text-red-600" :aria-label="`Remove ${known[id]?.name ?? 'file'}`" @click="remove(id)"><X :size="15" /></button>
            </li>
        </ul>
        <p v-else-if="readonly" class="text-sm text-muted">No file.</p>
        <template v-if="!readonly && (multiple ? ids.length < (maxFiles ?? 10) : !ids.length)">
            <input ref="input" type="file" class="sr-only" :multiple="multiple" :accept="accept?.map((a) => '.' + a).join(',')" @change="upload" />
            <button type="button" class="flex w-full items-center justify-center gap-2 rounded-xl border border-dashed border-line-strong px-4 py-3 text-sm text-ink-soft hover:border-brand-400 hover:bg-brand-50/40" :disabled="busy" @click="input?.click()">
                <LoaderCircle v-if="busy" :size="16" class="animate-spin" aria-hidden="true" />
                <Paperclip v-else :size="16" aria-hidden="true" />
                {{ busy ? 'Uploading…' : multiple ? 'Attach files' : 'Attach a file' }}
                <span v-if="accept?.length" class="text-xs text-muted">({{ accept.join(', ') }})</span>
            </button>
        </template>
    </div>
</template>
