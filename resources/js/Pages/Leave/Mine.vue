<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import PageHeader from '@/Components/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { CalendarPlus, CalendarX } from 'lucide-vue-next';
import LeaveSummary, { type Balance, type LedgerRow, type LeaveRow } from './LeaveSummary.vue';

defineProps<{ balances: Balance[]; records: LeaveRow[]; ledger: LedgerRow[] }>();
</script>

<template>
    <AppLayout title="My leave">
        <PageHeader title="My leave" description="Balances are checked when you apply; days are held while an application is pending and debited when it is sanctioned.">
            <AppButton v-if="route().has('requests.create')" variant="outline" :icon="CalendarX" :href="route('requests.create', 'leave-cancellation')">Cancel / return early</AppButton>
            <AppButton :icon="CalendarPlus" :href="route('requests.create', 'leave')">Apply for leave</AppButton>
        </PageHeader>
        <LeaveSummary :balances="balances" :records="records" :ledger="ledger" />
    </AppLayout>
</template>
