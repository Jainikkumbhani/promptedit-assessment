<script setup lang="ts">
import { useAnimatedNumber } from '@/composables/useAnimatedNumber';
import AppLayout from '@/layouts/AppLayout.vue';
import { exactUsd, formatUsd } from '@/lib/money';
import type { BreadcrumbItem, Paginated, SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowDownRight, ArrowUpRight, Gift, Loader2, Lock, Sparkles, Unlock, Zap } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Transaction {
    id: number;
    type: 'signup_bonus' | 'purchase' | 'hold' | 'hold_release' | 'charge';
    description: string;
    amount_nanos: number;
    balance_after_nanos: number;
    generation_id: string | null;
    created_at: string;
}

const props = defineProps<{
    packs: number[];
    held_nanos: number;
    transactions: Paginated<Transaction>;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Credits', href: route('credits.index') }];
const page = usePage<SharedData>();

const balance = computed(() => page.props.auth.user.balance_nanos);
const shown = useAnimatedNumber(balance);
const buying = ref<number | null>(null);

const buy = (amount: number) => {
    buying.value = amount;
    router.post(route('credits.store'), { amount }, { preserveScroll: true, onFinish: () => (buying.value = null) });
};

const typeMeta: Record<Transaction['type'], { label: string; icon: typeof Zap; tone: string }> = {
    signup_bonus: { label: 'Welcome', icon: Gift, tone: 'text-fuchsia-300 bg-fuchsia-500/10' },
    purchase: { label: 'Purchase', icon: ArrowUpRight, tone: 'text-emerald-300 bg-emerald-500/10' },
    hold: { label: 'Reserved', icon: Lock, tone: 'text-amber-300 bg-amber-500/10' },
    hold_release: { label: 'Released', icon: Unlock, tone: 'text-sky-300 bg-sky-500/10' },
    charge: { label: 'Charge', icon: ArrowDownRight, tone: 'text-violet-300 bg-violet-500/10' },
};

const packStyle = ['from-violet-500/20 to-transparent', 'from-fuchsia-500/20 to-transparent', 'from-cyan-400/20 to-transparent'];
const date = (iso: string) => new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
</script>

<template>
    <Head title="Credits" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-8 p-4 md:p-8">
            <!-- Balance -->
            <section class="glass relative animate-pop-in overflow-hidden p-6 md:p-8">
                <div
                    class="absolute -right-16 -top-16 size-64 rounded-full bg-gradient-to-br from-violet-500/30 via-fuchsia-500/20 to-cyan-400/10 blur-3xl"
                />
                <div class="relative flex flex-wrap items-end justify-between gap-6">
                    <div>
                        <p class="text-xs uppercase tracking-widest text-muted-foreground">Available credits</p>
                        <p class="mt-2 font-mono text-5xl font-semibold tracking-tight" :title="exactUsd(balance)">
                            {{ formatUsd(shown, { maxDecimals: 4 }) }}
                        </p>
                        <p class="mt-2 text-sm text-muted-foreground">1 credit = 1 US dollar. Every charge is exact to the billionth of a dollar.</p>
                    </div>
                    <div v-if="held_nanos > 0" class="rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm">
                        <div class="flex items-center gap-1.5 text-amber-300"><Lock class="size-4" /> Reserved for running images</div>
                        <div class="mt-1 font-mono" :title="exactUsd(held_nanos)">{{ formatUsd(held_nanos, { maxDecimals: 4 }) }}</div>
                    </div>
                </div>
            </section>

            <!-- Packs -->
            <section>
                <h2 class="mb-1 text-lg font-semibold">Buy credits</h2>
                <p class="mb-4 text-sm text-muted-foreground">Demo purchase: no payment is taken, the credits are simply added.</p>
                <div class="grid gap-4 sm:grid-cols-3">
                    <button
                        v-for="(amount, i) in packs"
                        :key="amount"
                        type="button"
                        class="glass group relative animate-pop-in overflow-hidden p-6 text-left transition duration-300 hover:-translate-y-1 hover:border-violet-400/50"
                        :style="{ animationDelay: `${i * 80}ms` }"
                        :disabled="buying !== null"
                        @click="buy(amount)"
                    >
                        <div
                            class="absolute inset-0 bg-gradient-to-br opacity-0 transition duration-500 group-hover:opacity-100"
                            :class="packStyle[i % 3]"
                        />
                        <div class="relative">
                            <div class="flex items-center justify-between">
                                <Sparkles class="size-5 text-violet-300 transition group-hover:rotate-12 group-hover:scale-110" />
                                <span v-if="i === 1" class="rounded-full bg-fuchsia-500/20 px-2 py-0.5 text-[10px] font-medium text-fuchsia-200"
                                    >Popular</span
                                >
                            </div>
                            <p class="mt-4 font-mono text-3xl font-semibold">${{ amount }}</p>
                            <p class="mt-1 text-xs text-muted-foreground">Added instantly</p>
                            <span class="mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-violet-300">
                                <Loader2 v-if="buying === amount" class="size-4 animate-spin" />
                                <Zap v-else class="size-4" />
                                {{ buying === amount ? 'Adding…' : 'Add credits' }}
                            </span>
                        </div>
                    </button>
                </div>
            </section>

            <!-- Ledger -->
            <section>
                <h2 class="mb-4 text-lg font-semibold">Activity</h2>
                <div class="glass overflow-hidden">
                    <TransitionGroup
                        v-if="transactions.data.length"
                        tag="ul"
                        enter-from-class="opacity-0 -translate-y-2"
                        enter-active-class="transition duration-300"
                    >
                        <li
                            v-for="t in transactions.data"
                            :key="t.id"
                            class="flex items-center gap-4 border-b border-border/40 px-5 py-3 last:border-0"
                        >
                            <span class="rounded-lg p-2" :class="typeMeta[t.type].tone"
                                ><component :is="typeMeta[t.type].icon" class="size-4"
                            /></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm">{{ t.description }}</p>
                                <p class="text-xs text-muted-foreground">{{ typeMeta[t.type].label }} · {{ date(t.created_at) }}</p>
                            </div>
                            <div class="text-right">
                                <p
                                    class="font-mono text-sm font-medium"
                                    :class="t.amount_nanos >= 0 ? 'text-emerald-400' : 'text-foreground'"
                                    :title="exactUsd(t.amount_nanos)"
                                >
                                    {{ t.amount_nanos >= 0 ? '+' : '' }}{{ formatUsd(t.amount_nanos) }}
                                </p>
                                <p class="font-mono text-[11px] text-muted-foreground">
                                    bal. {{ formatUsd(t.balance_after_nanos, { maxDecimals: 4 }) }}
                                </p>
                            </div>
                        </li>
                    </TransitionGroup>
                    <p v-else class="p-8 text-center text-sm text-muted-foreground">No activity yet.</p>
                </div>

                <nav v-if="transactions.last_page > 1" class="mt-4 flex flex-wrap justify-center gap-1">
                    <template v-for="link in transactions.links" :key="link.label">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-scroll
                            class="min-w-9 rounded-lg px-3 py-1.5 text-center text-sm transition"
                            :class="link.active ? 'bg-violet-500/20 text-violet-200' : 'text-muted-foreground hover:bg-muted'"
                            ><span v-html="link.label"
                        /></Link>
                        <span v-else class="min-w-9 px-3 py-1.5 text-center text-sm text-muted-foreground/50" v-html="link.label" />
                    </template>
                </nav>
            </section>
        </div>
    </AppLayout>
</template>
