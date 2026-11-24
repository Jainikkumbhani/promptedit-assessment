<script setup lang="ts">
import StatusPill from '@/components/studio/StatusPill.vue';
import { formatDuration, secondsSince, useNow } from '@/composables/useNow';
import { exactUsd, formatUsd } from '@/lib/money';
import type { Generation } from '@/types';
import { router } from '@inertiajs/vue3';
import { Download, ExternalLink, ImageOff, RefreshCw, ShieldCheck, Sparkles } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{ generation: Generation }>();

const now = useNow();
const loaded = ref(false);
const checking = ref(false);

const g = computed(() => props.generation);
const active = computed(() => ['submitting', 'queued', 'processing'].includes(g.value.status));

const aspect = computed(() => {
    const [w, h] = g.value.size.split('x').map(Number);
    return `${w} / ${h}`;
});

const elapsed = computed(() => formatDuration(secondsSince(g.value.created_at, now.value)));

const steps = ['submitting', 'queued', 'processing', 'succeeded'] as const;
const stepIndex = computed(() => steps.indexOf(g.value.status as (typeof steps)[number]));

const activityText = computed(() => {
    if (g.value.status === 'submitting' && g.value.retry_reason) {
        const reasons: Record<string, string> = {
            concurrency_limit_exceeded: 'Pixelforge is busy with 3 images; yours is next in line',
            rate_limit_exceeded: 'Pacing requests to respect Pixelforge rate limits',
            overloaded: 'Pixelforge is overloaded, retrying shortly',
        };
        return reasons[g.value.retry_reason] ?? 'Retrying shortly';
    }

    return { submitting: 'Sending to Pixelforge', queued: 'Waiting in the queue', processing: 'Painting your image' }[g.value.status as string] ?? '';
});

/** Actual vs estimate, as a signed percentage. */
const delta = computed(() => {
    if (g.value.charged_nanos === null || g.value.status !== 'succeeded' || !g.value.estimated_cost_nanos) return null;
    return Math.round(((g.value.charged_nanos - g.value.estimated_cost_nanos) / g.value.estimated_cost_nanos) * 100);
});

const checkNow = () => {
    checking.value = true;
    router.post(route('generations.sync', { generation: g.value.id }), {}, { preserveScroll: true, onFinish: () => (checking.value = false) });
};
</script>

<template>
    <article class="glass group/card flex flex-col overflow-hidden transition duration-300 hover:-translate-y-0.5 hover:border-violet-400/40">
        <!-- Media -->
        <div class="relative overflow-hidden bg-muted" :class="{ 'min-h-60': g.status !== 'succeeded' }" :style="{ aspectRatio: aspect }">
            <!-- Finished image: blur-up fade in -->
            <template v-if="g.status === 'succeeded' && g.image_url">
                <img
                    :src="g.image_url"
                    :alt="g.prompt"
                    loading="lazy"
                    class="size-full object-cover transition duration-700 ease-out group-hover/card:scale-[1.03]"
                    :class="loaded ? 'scale-100 opacity-100 blur-0' : 'scale-105 opacity-0 blur-lg'"
                    @load="loaded = true"
                />
                <div
                    class="absolute inset-0 flex items-end justify-end gap-2 bg-gradient-to-t from-black/60 via-transparent p-3 opacity-0 transition group-hover/card:opacity-100"
                >
                    <a
                        :href="g.image_url"
                        target="_blank"
                        rel="noopener"
                        class="rounded-lg bg-white/15 p-2 text-white backdrop-blur transition hover:bg-white/25"
                        title="Open full size"
                    >
                        <ExternalLink class="size-4" />
                    </a>
                    <a
                        :href="g.image_url"
                        download
                        class="rounded-lg bg-white/15 p-2 text-white backdrop-blur transition hover:bg-white/25"
                        title="Download"
                    >
                        <Download class="size-4" />
                    </a>
                </div>
            </template>

            <!-- Expired link -->
            <div
                v-else-if="g.status === 'succeeded'"
                class="flex size-full flex-col items-center justify-center gap-2 p-6 text-center text-muted-foreground"
            >
                <ImageOff class="size-7" />
                <p class="text-xs">Pixelforge image links expire 24 hours after completion.</p>
            </div>

            <!-- In progress -->
            <div v-else-if="active" class="shimmer flex size-full flex-col items-center justify-center gap-3 px-6 pb-4 pt-12 text-center">
                <div class="relative size-12 shrink-0">
                    <div
                        class="absolute inset-0 animate-spin-slow rounded-full bg-[conic-gradient(from_0deg,transparent,theme(colors.violet.500),theme(colors.fuchsia.400),theme(colors.cyan.300),transparent)] p-[2px]"
                    >
                        <div class="size-full rounded-full bg-card/90" />
                    </div>
                    <Sparkles class="absolute inset-0 m-auto size-5 animate-pulse text-violet-300" />
                </div>
                <div>
                    <p class="text-sm font-medium">{{ activityText }}</p>
                    <p class="mt-1 font-mono text-xs text-muted-foreground">{{ elapsed }}</p>
                </div>
                <!-- lifecycle steps -->
                <div class="flex items-center gap-1.5">
                    <span
                        v-for="(step, i) in steps"
                        :key="step"
                        class="h-1 rounded-full transition-all duration-700"
                        :class="i <= stepIndex ? 'w-8 bg-gradient-to-r from-violet-500 to-fuchsia-400' : 'w-4 bg-foreground/15'"
                    />
                </div>
            </div>

            <!-- Failed -->
            <div v-else class="flex size-full flex-col items-center justify-center gap-2 bg-rose-500/5 px-6 pb-4 pt-12 text-center">
                <div class="rounded-full bg-rose-500/10 p-2.5 text-rose-400">
                    <ImageOff class="size-5" />
                </div>
                <p class="text-sm font-medium text-rose-300">{{ g.error_message || 'The generation failed.' }}</p>
                <p v-if="g.error_code" class="font-mono text-[11px] text-muted-foreground">{{ g.error_code }}</p>
            </div>

            <div class="absolute left-3 top-3">
                <StatusPill :status="g.status" :retry-reason="g.retry_reason" />
            </div>
        </div>

        <!-- Details -->
        <div class="flex flex-1 flex-col gap-3 p-4">
            <p class="line-clamp-2 text-sm leading-relaxed" :title="g.prompt">{{ g.prompt }}</p>

            <div class="flex flex-wrap gap-1.5 text-[11px] text-muted-foreground">
                <span class="rounded-md bg-muted px-2 py-0.5 font-mono">{{ g.size }}</span>
                <span class="rounded-md bg-muted px-2 py-0.5 capitalize">{{ g.quality }}</span>
                <span v-if="g.reference_images.length" class="rounded-md bg-muted px-2 py-0.5">{{ g.reference_images.length }} ref</span>
            </div>

            <div class="mt-auto flex items-end justify-between gap-3 border-t border-border/60 pt-3">
                <!-- Cost -->
                <div v-if="g.status === 'succeeded'" class="min-w-0">
                    <div class="text-[11px] uppercase tracking-wider text-muted-foreground">Charged</div>
                    <div class="font-mono text-sm font-semibold" :title="exactUsd(g.charged_nanos)">{{ formatUsd(g.charged_nanos) }}</div>
                    <div class="font-mono text-[11px] text-muted-foreground">
                        est. {{ formatUsd(g.estimated_cost_nanos, { maxDecimals: 4 }) }}
                        <span v-if="delta !== null" :class="delta > 0 ? 'text-amber-400' : 'text-emerald-400'"
                            >{{ delta > 0 ? '+' : '' }}{{ delta }}%</span
                        >
                    </div>
                </div>
                <div v-else-if="g.status === 'failed'" class="min-w-0">
                    <div class="text-[11px] uppercase tracking-wider text-muted-foreground">Charged</div>
                    <div class="flex items-center gap-1 font-mono text-sm font-semibold text-emerald-400"><ShieldCheck class="size-3.5" /> $0.00</div>
                    <div class="text-[11px] text-muted-foreground">Failed generations are free</div>
                </div>
                <div v-else class="min-w-0">
                    <div class="text-[11px] uppercase tracking-wider text-muted-foreground">Estimated</div>
                    <div class="font-mono text-sm font-semibold" :title="exactUsd(g.estimated_cost_nanos)">
                        ≈ {{ formatUsd(g.estimated_cost_nanos, { maxDecimals: 4 }) }}
                    </div>
                    <div class="font-mono text-[11px] text-muted-foreground">held up to {{ formatUsd(g.max_cost_nanos, { maxDecimals: 4 }) }}</div>
                </div>

                <button
                    v-if="active && g.pixelforge_id"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-border px-2.5 py-1.5 text-xs text-muted-foreground transition hover:border-violet-400/50 hover:text-foreground"
                    :disabled="checking"
                    title="Ask Pixelforge for the latest status now"
                    @click="checkNow"
                >
                    <RefreshCw class="size-3.5" :class="{ 'animate-spin': checking }" /> Check
                </button>
            </div>
        </div>
    </article>
</template>
