<script setup lang="ts">
import StatusPill from '@/components/studio/StatusPill.vue';
import { useAnimatedNumber } from '@/composables/useAnimatedNumber';
import { useLiveReload } from '@/composables/useLiveReload';
import AppLayout from '@/layouts/AppLayout.vue';
import { exactUsd, formatTokens, formatUsd } from '@/lib/money';
import type { BreadcrumbItem, Generation, Paginated } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ChevronDown, ImageOff, Images, Loader2, ShieldCheck, Wallet, XCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    generations: Paginated<Generation>;
    totals: { total: number; succeeded: number; failed: number; spent_nanos: number };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'History', href: route('generations.index') }];

const spent = useAnimatedNumber(computed(() => props.totals.spent_nanos));
const expanded = ref<string | null>(null);

const anyActive = computed(() => props.generations.data.some((g) => ['submitting', 'queued', 'processing'].includes(g.status)));
useLiveReload(anyActive, ['generations', 'totals', 'auth']);

const stats = computed(() => [
    { label: 'Generations', value: props.totals.total.toLocaleString(), icon: Images, tone: 'text-violet-300 bg-violet-500/10' },
    { label: 'Succeeded', value: props.totals.succeeded.toLocaleString(), icon: ShieldCheck, tone: 'text-emerald-300 bg-emerald-500/10' },
    { label: 'Failed (free)', value: props.totals.failed.toLocaleString(), icon: XCircle, tone: 'text-rose-300 bg-rose-500/10' },
]);

const date = (iso: string) => new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });

const delta = (g: Generation) =>
    g.status === 'succeeded' && g.charged_nanos !== null && g.estimated_cost_nanos
        ? Math.round(((g.charged_nanos - g.estimated_cost_nanos) / g.estimated_cost_nanos) * 100)
        : null;

const tokenRows = (g: Generation) =>
    g.usage
        ? [
              ['Input text', g.usage.input_text_tokens],
              ['Input images', g.usage.input_image_tokens],
              ['Output', g.usage.output_tokens],
              ['Reasoning', g.usage.reasoning_tokens],
          ]
        : [];
</script>

<template>
    <Head title="History" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-8">
            <header class="animate-fade-up">
                <h1 class="text-3xl font-semibold tracking-tight">History</h1>
                <p class="mt-1 text-muted-foreground">Every generation, what we estimated, and exactly what it cost.</p>
            </header>

            <!-- Stats -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="glass relative animate-pop-in overflow-hidden p-5 sm:col-span-2 lg:col-span-1">
                    <div class="absolute -right-6 -top-6 size-24 rounded-full bg-fuchsia-500/20 blur-2xl" />
                    <div class="flex items-center gap-2 text-xs uppercase tracking-wider text-muted-foreground">
                        <Wallet class="size-4" /> Total spent
                    </div>
                    <div class="mt-2 font-mono text-2xl font-semibold" :title="exactUsd(totals.spent_nanos)">
                        {{ formatUsd(spent, { maxDecimals: 4 }) }}
                    </div>
                </div>
                <div v-for="(stat, i) in stats" :key="stat.label" class="glass animate-pop-in p-5" :style="{ animationDelay: `${(i + 1) * 70}ms` }">
                    <div class="flex items-center gap-2 text-xs uppercase tracking-wider text-muted-foreground">
                        <span class="rounded-md p-1" :class="stat.tone"><component :is="stat.icon" class="size-3.5" /></span>
                        {{ stat.label }}
                    </div>
                    <div class="mt-2 text-2xl font-semibold">{{ stat.value }}</div>
                </div>
            </div>

            <!-- List -->
            <div v-if="generations.data.length" class="glass overflow-hidden">
                <div
                    class="hidden grid-cols-[64px_minmax(0,1fr)_130px_120px_140px_28px] gap-4 border-b border-border/60 px-5 py-3 text-[11px] uppercase tracking-wider text-muted-foreground md:grid"
                >
                    <span />
                    <span>Prompt</span>
                    <span>Status</span>
                    <span class="text-right">Estimated</span>
                    <span class="text-right">Actual cost</span>
                    <span />
                </div>

                <TransitionGroup tag="ul" enter-from-class="opacity-0 translate-y-2" enter-active-class="transition duration-300">
                    <li v-for="(g, index) in generations.data" :key="g.id" class="border-b border-border/40 last:border-0">
                        <button
                            type="button"
                            class="grid w-full grid-cols-[56px_minmax(0,1fr)] items-center gap-4 px-5 py-3 text-left transition hover:bg-muted/40 md:grid-cols-[64px_minmax(0,1fr)_130px_120px_140px_28px]"
                            :style="{ animationDelay: `${index * 30}ms` }"
                            @click="expanded = expanded === g.id ? null : g.id"
                        >
                            <!-- thumb -->
                            <div class="relative size-14 overflow-hidden rounded-lg bg-muted md:size-16">
                                <img v-if="g.image_url" :src="g.image_url" :alt="g.prompt" loading="lazy" class="size-full object-cover" />
                                <div
                                    v-else-if="['submitting', 'queued', 'processing'].includes(g.status)"
                                    class="shimmer flex size-full items-center justify-center"
                                >
                                    <Loader2 class="size-4 animate-spin text-violet-300" />
                                </div>
                                <div v-else class="flex size-full items-center justify-center text-muted-foreground"><ImageOff class="size-4" /></div>
                            </div>

                            <div class="min-w-0">
                                <p class="truncate text-sm">{{ g.prompt }}</p>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    <span class="font-mono">{{ g.size }}</span> · <span class="capitalize">{{ g.quality }}</span> ·
                                    {{ date(g.created_at) }}
                                </p>
                                <div class="mt-2 flex items-center gap-3 md:hidden">
                                    <StatusPill :status="g.status" :retry-reason="g.retry_reason" />
                                    <span class="font-mono text-xs">{{
                                        g.status === 'succeeded'
                                            ? formatUsd(g.charged_nanos)
                                            : g.status === 'failed'
                                              ? '$0.00'
                                              : '≈ ' + formatUsd(g.estimated_cost_nanos, { maxDecimals: 4 })
                                    }}</span>
                                </div>
                            </div>

                            <div class="hidden md:block"><StatusPill :status="g.status" :retry-reason="g.retry_reason" /></div>

                            <div class="hidden text-right font-mono text-sm text-muted-foreground md:block" :title="exactUsd(g.estimated_cost_nanos)">
                                {{ formatUsd(g.estimated_cost_nanos, { maxDecimals: 4 }) }}
                            </div>

                            <div class="hidden text-right md:block">
                                <template v-if="g.status === 'succeeded'">
                                    <div class="font-mono text-sm font-semibold" :title="exactUsd(g.charged_nanos)">
                                        {{ formatUsd(g.charged_nanos) }}
                                    </div>
                                    <div
                                        v-if="delta(g) !== null"
                                        class="font-mono text-[11px]"
                                        :class="(delta(g) ?? 0) > 0 ? 'text-amber-400' : 'text-emerald-400'"
                                    >
                                        {{ (delta(g) ?? 0) > 0 ? '+' : '' }}{{ delta(g) }}% vs est.
                                    </div>
                                </template>
                                <div v-else-if="g.status === 'failed'" class="font-mono text-sm text-emerald-400">
                                    $0.00 <span class="block text-[11px] text-muted-foreground">not charged</span>
                                </div>
                                <div v-else class="text-xs text-muted-foreground">pending</div>
                            </div>

                            <ChevronDown
                                class="hidden size-4 text-muted-foreground transition md:block"
                                :class="{ 'rotate-180': expanded === g.id }"
                            />
                        </button>

                        <!-- Details -->
                        <Transition
                            enter-from-class="opacity-0 -translate-y-1"
                            enter-active-class="transition duration-200"
                            leave-to-class="opacity-0"
                            leave-active-class="transition duration-100"
                        >
                            <div v-if="expanded === g.id" class="grid gap-4 bg-muted/30 px-5 py-4 text-xs md:grid-cols-3">
                                <div>
                                    <div class="mb-1.5 font-medium">Prompt</div>
                                    <p class="whitespace-pre-wrap text-muted-foreground">{{ g.prompt }}</p>
                                    <div v-if="g.reference_images.length" class="mt-2 space-y-1">
                                        <a
                                            v-for="url in g.reference_images"
                                            :key="url"
                                            :href="url"
                                            target="_blank"
                                            rel="noopener"
                                            class="block truncate text-violet-300 hover:underline"
                                            >{{ url }}</a
                                        >
                                    </div>
                                </div>
                                <div>
                                    <div class="mb-1.5 font-medium">Token usage</div>
                                    <dl v-if="g.usage" class="space-y-1 font-mono">
                                        <div v-for="[label, value] in tokenRows(g)" :key="label" class="flex justify-between">
                                            <dt class="text-muted-foreground">{{ label }}</dt>
                                            <dd>{{ formatTokens(Number(value)) }}</dd>
                                        </div>
                                        <div class="flex justify-between border-t border-border/50 pt-1">
                                            <dt class="text-muted-foreground">Total</dt>
                                            <dd>{{ formatTokens(g.usage.total_tokens) }}</dd>
                                        </div>
                                    </dl>
                                    <p v-else class="text-muted-foreground">Known once the generation succeeds.</p>
                                </div>
                                <div>
                                    <div class="mb-1.5 font-medium">Cost</div>
                                    <dl class="space-y-1 font-mono">
                                        <div class="flex justify-between">
                                            <dt class="text-muted-foreground">Estimated</dt>
                                            <dd>{{ exactUsd(g.estimated_cost_nanos) }}</dd>
                                        </div>
                                        <div class="flex justify-between">
                                            <dt class="text-muted-foreground">Reserved (max)</dt>
                                            <dd>{{ exactUsd(g.max_cost_nanos) }}</dd>
                                        </div>
                                        <div class="flex justify-between">
                                            <dt class="text-muted-foreground">Pixelforge cost</dt>
                                            <dd>{{ exactUsd(g.provider_cost_nanos) }}</dd>
                                        </div>
                                        <div class="flex justify-between border-t border-border/50 pt-1 font-semibold">
                                            <dt>You paid (+25%)</dt>
                                            <dd>{{ exactUsd(g.charged_nanos) }}</dd>
                                        </div>
                                    </dl>
                                    <p v-if="g.error_message" class="mt-2 text-rose-300">
                                        {{ g.error_message }} <span class="font-mono text-muted-foreground">({{ g.error_code }})</span>
                                    </p>
                                    <p v-if="g.pixelforge_id" class="mt-2 truncate font-mono text-[10px] text-muted-foreground">
                                        {{ g.pixelforge_id }}
                                    </p>
                                </div>
                            </div>
                        </Transition>
                    </li>
                </TransitionGroup>
            </div>

            <div v-else class="glass flex flex-col items-center gap-3 py-20 text-center">
                <Images class="size-8 text-muted-foreground" />
                <p class="text-muted-foreground">No generations yet.</p>
                <Link :href="route('studio')" class="btn-glow rounded-xl px-4 py-2 text-sm font-semibold">Create your first image</Link>
            </div>

            <!-- Pagination -->
            <nav v-if="generations.last_page > 1" class="flex flex-wrap justify-center gap-1">
                <template v-for="link in generations.links" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        preserve-scroll
                        class="min-w-9 rounded-lg px-3 py-1.5 text-center text-sm transition"
                        :class="link.active ? 'bg-violet-500/20 text-violet-200 ring-1 ring-violet-400/40' : 'text-muted-foreground hover:bg-muted'"
                        ><span v-html="link.label"
                    /></Link>
                    <span v-else class="min-w-9 px-3 py-1.5 text-center text-sm text-muted-foreground/50" v-html="link.label" />
                </template>
            </nav>
        </div>
    </AppLayout>
</template>
