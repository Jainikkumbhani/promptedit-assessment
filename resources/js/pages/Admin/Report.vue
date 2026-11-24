<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { exactUsd, formatUsd } from '@/lib/money';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2, CircleDashed, TrendingUp, XCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Sums {
    generations: number;
    charged_nanos: number;
    provider_cost_nanos: number;
    profit_nanos: number;
}

interface Row extends Sums {
    date: string;
    user: { id: number; name: string; email: string };
}

interface Day extends Sums {
    date: string;
    pixelforge_cost_nanos: number | null;
    pixelforge_generations: number | null;
    matches: boolean | null;
}

const props = defineProps<{
    from: string;
    to: string;
    rows: Row[];
    days: Day[];
    totals: Sums;
    usage_error: string | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Operator report', href: route('admin.report') }];

const from = ref(props.from);
const to = ref(props.to);
const apply = () => router.get(route('admin.report'), { from: from.value, to: to.value }, { preserveState: true, preserveScroll: true });

const margin = computed(() => (props.totals.charged_nanos ? Math.round((props.totals.profit_nanos / props.totals.charged_nanos) * 1000) / 10 : 0));
const reconciled = computed(() => props.days.filter((d) => d.matches !== null));
const allMatch = computed(() => reconciled.value.length > 0 && reconciled.value.every((d) => d.matches));

const tiles = computed(() => [
    { label: 'Credit spend', hint: 'charged to users', value: props.totals.charged_nanos, tone: 'from-violet-500/25' },
    { label: 'Provider cost', hint: 'billed by Pixelforge', value: props.totals.provider_cost_nanos, tone: 'from-cyan-400/25' },
    { label: 'Profit', hint: `${margin.value}% margin`, value: props.totals.profit_nanos, tone: 'from-emerald-400/25' },
]);
</script>

<template>
    <Head title="Operator report" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-8">
            <header class="flex animate-fade-up flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-3xl font-semibold tracking-tight">Operator report</h1>
                    <p class="mt-1 text-muted-foreground">
                        Spend, provider cost and profit per UTC day and user, checked against Pixelforge's
                        <code class="font-mono text-xs">GET /v1/usage</code>.
                    </p>
                </div>
                <form class="flex flex-wrap items-end gap-2" @submit.prevent="apply">
                    <label class="text-xs text-muted-foreground"
                        >From
                        <input
                            v-model="from"
                            type="date"
                            class="mt-1 block rounded-lg border border-input bg-background/60 px-3 py-1.5 text-sm text-foreground"
                        />
                    </label>
                    <label class="text-xs text-muted-foreground"
                        >To
                        <input
                            v-model="to"
                            type="date"
                            class="mt-1 block rounded-lg border border-input bg-background/60 px-3 py-1.5 text-sm text-foreground"
                        />
                    </label>
                    <button class="btn-glow rounded-lg px-4 py-2 text-sm font-semibold">Apply</button>
                </form>
            </header>

            <!-- Totals -->
            <div class="grid gap-4 md:grid-cols-4">
                <div
                    v-for="(tile, i) in tiles"
                    :key="tile.label"
                    class="glass relative animate-pop-in overflow-hidden p-5"
                    :style="{ animationDelay: `${i * 70}ms` }"
                >
                    <div class="absolute inset-0 bg-gradient-to-br to-transparent opacity-60" :class="tile.tone" />
                    <div class="relative">
                        <div class="text-xs uppercase tracking-wider text-muted-foreground">{{ tile.label }}</div>
                        <div class="mt-2 font-mono text-2xl font-semibold" :title="exactUsd(tile.value)">
                            {{ formatUsd(tile.value, { maxDecimals: 6 }) }}
                        </div>
                        <div class="mt-1 text-xs text-muted-foreground">{{ tile.hint }}</div>
                    </div>
                </div>
                <div class="glass flex animate-pop-in flex-col justify-between p-5" style="animation-delay: 210ms">
                    <div class="text-xs uppercase tracking-wider text-muted-foreground">Reconciliation</div>
                    <div v-if="usage_error" class="mt-2 flex items-center gap-2 text-sm text-amber-300">
                        <AlertTriangle class="size-5" /> Unavailable
                    </div>
                    <div v-else-if="allMatch" class="mt-2 flex items-center gap-2 text-lg font-semibold text-emerald-400">
                        <CheckCircle2 class="size-6" /> All days match
                    </div>
                    <div v-else class="mt-2 flex items-center gap-2 text-lg font-semibold text-rose-400">
                        <XCircle class="size-6" /> Mismatch found
                    </div>
                    <div class="mt-1 text-xs text-muted-foreground">{{ totals.generations }} billed generations</div>
                </div>
            </div>

            <div v-if="usage_error" class="glass flex items-center gap-3 border-amber-500/40 p-4 text-sm text-amber-300">
                <AlertTriangle class="size-5" /> {{ usage_error }}
            </div>

            <!-- Per day -->
            <section class="glass overflow-x-auto">
                <h2 class="border-b border-border/60 px-5 py-4 font-semibold">
                    By day <span class="text-xs font-normal text-muted-foreground">(a generation counts on the UTC day it completed)</span>
                </h2>
                <table class="w-full min-w-[760px] text-sm">
                    <thead class="text-left text-[11px] uppercase tracking-wider text-muted-foreground">
                        <tr class="border-b border-border/60">
                            <th class="px-5 py-3 font-medium">Date</th>
                            <th class="px-5 py-3 text-right font-medium">Images</th>
                            <th class="px-5 py-3 text-right font-medium">Credit spend</th>
                            <th class="px-5 py-3 text-right font-medium">Provider cost (ours)</th>
                            <th class="px-5 py-3 text-right font-medium">Pixelforge says</th>
                            <th class="px-5 py-3 text-right font-medium">Profit</th>
                            <th class="px-5 py-3 text-center font-medium">Match</th>
                        </tr>
                    </thead>
                    <tbody class="font-mono">
                        <tr
                            v-for="day in days"
                            :key="day.date"
                            class="border-b border-border/30 transition last:border-0 hover:bg-muted/30"
                            :class="{ 'opacity-50': !day.generations && !day.pixelforge_generations }"
                        >
                            <td class="px-5 py-2.5 font-sans">{{ day.date }}</td>
                            <td class="px-5 py-2.5 text-right">
                                {{ day.generations
                                }}<span v-if="day.pixelforge_generations !== null" class="text-muted-foreground">
                                    / {{ day.pixelforge_generations }}</span
                                >
                            </td>
                            <td class="px-5 py-2.5 text-right" :title="exactUsd(day.charged_nanos)">{{ exactUsd(day.charged_nanos) }}</td>
                            <td class="px-5 py-2.5 text-right">{{ exactUsd(day.provider_cost_nanos) }}</td>
                            <td class="px-5 py-2.5 text-right text-muted-foreground">{{ exactUsd(day.pixelforge_cost_nanos) }}</td>
                            <td class="px-5 py-2.5 text-right text-emerald-400">{{ exactUsd(day.profit_nanos) }}</td>
                            <td class="px-5 py-2.5 text-center">
                                <CheckCircle2 v-if="day.matches === true" class="mx-auto size-4 text-emerald-400" />
                                <XCircle v-else-if="day.matches === false" class="mx-auto size-4 text-rose-400" />
                                <CircleDashed v-else class="mx-auto size-4 text-muted-foreground" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <!-- Per user per day -->
            <section class="glass overflow-x-auto">
                <h2 class="flex items-center gap-2 border-b border-border/60 px-5 py-4 font-semibold">
                    <TrendingUp class="size-4 text-violet-300" /> By day and user
                </h2>
                <table v-if="rows.length" class="w-full min-w-[720px] text-sm">
                    <thead class="text-left text-[11px] uppercase tracking-wider text-muted-foreground">
                        <tr class="border-b border-border/60">
                            <th class="px-5 py-3 font-medium">Date</th>
                            <th class="px-5 py-3 font-medium">User</th>
                            <th class="px-5 py-3 text-right font-medium">Images</th>
                            <th class="px-5 py-3 text-right font-medium">Credit spend</th>
                            <th class="px-5 py-3 text-right font-medium">Provider cost</th>
                            <th class="px-5 py-3 text-right font-medium">Profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in rows"
                            :key="`${row.date}-${row.user.id}`"
                            class="border-b border-border/30 transition last:border-0 hover:bg-muted/30"
                        >
                            <td class="px-5 py-2.5">{{ row.date }}</td>
                            <td class="px-5 py-2.5">
                                <div>{{ row.user.name }}</div>
                                <div class="text-xs text-muted-foreground">{{ row.user.email }}</div>
                            </td>
                            <td class="px-5 py-2.5 text-right font-mono">{{ row.generations }}</td>
                            <td class="px-5 py-2.5 text-right font-mono">{{ exactUsd(row.charged_nanos) }}</td>
                            <td class="px-5 py-2.5 text-right font-mono">{{ exactUsd(row.provider_cost_nanos) }}</td>
                            <td class="px-5 py-2.5 text-right font-mono text-emerald-400">{{ exactUsd(row.profit_nanos) }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="p-8 text-center text-sm text-muted-foreground">No billed generations in this range.</p>
            </section>
        </div>
    </AppLayout>
</template>
