<script setup lang="ts">
import GenerationCard from '@/components/studio/GenerationCard.vue';
import { useAnimatedNumber } from '@/composables/useAnimatedNumber';
import { useLiveReload } from '@/composables/useLiveReload';
import AppLayout from '@/layouts/AppLayout.vue';
import { exactUsd, formatTokens, formatUsd } from '@/lib/money';
import type { BreadcrumbItem, Estimate, Generation, Pricing, SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { AlertTriangle, FlaskConical, ImagePlus, Info, Loader2, Plus, Sparkles, Wand2, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    pricing: Pricing | null;
    limits: { prompt: number; reference_images: number };
    generations: Generation[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Studio', href: route('studio') }];
const page = usePage<SharedData>();

const form = useForm({
    prompt: '',
    size: props.pricing?.sizes[0] ?? '1024x1024',
    quality: props.pricing?.qualities.includes('medium') ? 'medium' : (props.pricing?.qualities[0] ?? 'medium'),
    reference_images: [] as string[],
});

/* ---------- live estimate ---------- */

const estimate = ref<Estimate | null>(null);
const estimating = ref(false);

const xsrfToken = () => decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? '');

const refreshEstimate = useDebounceFn(async () => {
    if (!props.pricing) return;
    estimating.value = true;

    try {
        const response = await fetch(route('generations.estimate'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
            body: JSON.stringify({
                prompt: form.prompt,
                size: form.size,
                quality: form.quality,
                reference_images: form.reference_images.filter((url) => /^https?:\/\/\S+$/.test(url)),
            }),
        });
        if (response.ok) estimate.value = await response.json();
    } finally {
        estimating.value = false;
    }
}, 250);

watch(() => [form.prompt, form.size, form.quality, form.reference_images.join('\n')], refreshEstimate, { immediate: true });

const expected = useAnimatedNumber(computed(() => estimate.value?.expected_nanos ?? 0));
const balance = computed(() => page.props.auth.user.balance_nanos);
const creditsError = computed(() => page.props.errors?.credits);
const cannotAfford = computed(() => !!estimate.value && balance.value < estimate.value.max_nanos);

const breakdown = computed(() => {
    if (!estimate.value || !props.pricing) return [];
    const t = estimate.value.tokens;
    const r = props.pricing.rates;
    return [
        { label: 'Prompt text', tokens: `~${formatTokens(t.input_text.estimate)}`, hint: `±15%`, rate: r.input_text_tokens },
        { label: 'Reference images', tokens: formatTokens(t.input_image), hint: 'exact', rate: r.input_image_tokens },
        { label: 'Output image', tokens: formatTokens(t.output), hint: 'exact', rate: r.output_tokens },
        { label: 'Reasoning', tokens: `${formatTokens(t.reasoning.min)}–${formatTokens(t.reasoning.max)}`, hint: 'varies', rate: r.reasoning_tokens },
    ];
});

/* ---------- form helpers ---------- */

const sizeMeta: Record<string, { label: string; w: number; h: number }> = {
    '1024x1024': { label: 'Square', w: 1, h: 1 },
    '1536x1024': { label: 'Landscape', w: 3, h: 2 },
    '1024x1536': { label: 'Portrait', w: 2, h: 3 },
};

const qualityHint = (quality: string) => {
    const tokens = props.pricing?.output_tokens[quality]?.[form.size];
    return tokens ? `${formatTokens(tokens)} output tokens` : '';
};

const addReference = () => {
    if (form.reference_images.length < props.limits.reference_images) form.reference_images.push('');
};
const removeReference = (index: number) => form.reference_images.splice(index, 1);

const ideas = [
    'A lighthouse on a cliff at dawn, oil painting',
    'Isometric cozy coffee shop in the rain, soft neon',
    'Minimal poster of a fox made of origami, pastel',
];

const testKeywords = [
    { keyword: '[succeed]', hint: 'never fails' },
    { keyword: '[fail]', hint: 'fails while processing' },
    { keyword: '[slow]', hint: '60–90s to process' },
    { keyword: '[duplicate]', hint: 'every webhook twice' },
    { keyword: '[blocked]', hint: 'content policy 400' },
    { keyword: '[overloaded]', hint: '503 at submission' },
];
const showTesting = ref(false);
const addKeyword = (keyword: string) => {
    if (!form.prompt.toLowerCase().includes(keyword)) form.prompt = `${form.prompt.trim()} ${keyword}`.trim();
};

const submit = () => {
    form.transform((data) => ({ ...data, reference_images: data.reference_images.filter(Boolean) })).post(route('generations.store'), {
        preserveScroll: true,
    });
};

/* ---------- live feed ---------- */

const anyActive = computed(() => props.generations.some((g) => ['submitting', 'queued', 'processing'].includes(g.status)));
useLiveReload(anyActive, ['generations', 'auth']);
</script>

<template>
    <Head title="Studio" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-8 p-4 md:p-8">
            <!-- Hero -->
            <header class="animate-fade-up">
                <p
                    class="mb-2 inline-flex items-center gap-2 rounded-full border border-violet-500/30 bg-violet-500/10 px-3 py-1 text-xs font-medium text-violet-300"
                >
                    <Sparkles class="size-3.5" /> Powered by Pixelforge
                </p>
                <h1 class="text-3xl font-semibold tracking-tight md:text-4xl">Turn words into <span class="text-gradient">images</span></h1>
                <p class="mt-2 max-w-2xl text-muted-foreground">
                    See the price before you create. You pay the exact cost of what Pixelforge generates, and failed images are always free.
                </p>
            </header>

            <div v-if="!pricing" class="glass flex items-center gap-3 border-amber-500/40 p-4 text-sm text-amber-300">
                <AlertTriangle class="size-5" /> Pixelforge pricing is unavailable right now, so new generations are paused. Please try again in a
                minute.
            </div>

            <div class="grid gap-8 lg:grid-cols-[minmax(0,420px)_1fr]">
                <!-- Composer -->
                <form class="glass flex h-fit animate-pop-in flex-col gap-6 p-5 lg:sticky lg:top-6" @submit.prevent="submit">
                    <!-- Prompt -->
                    <div>
                        <div class="mb-2 flex items-center justify-between">
                            <label for="prompt" class="text-sm font-medium">Prompt</label>
                            <span
                                class="font-mono text-[11px]"
                                :class="form.prompt.length > limits.prompt ? 'text-destructive' : 'text-muted-foreground'"
                            >
                                {{ form.prompt.length }} / {{ limits.prompt }}
                            </span>
                        </div>
                        <textarea
                            id="prompt"
                            v-model="form.prompt"
                            rows="5"
                            :maxlength="limits.prompt"
                            placeholder="Describe the image you want…"
                            class="w-full resize-none rounded-xl border border-input bg-background/60 px-4 py-3 text-sm outline-none transition placeholder:text-muted-foreground/70 focus:border-violet-400/60 focus:ring-4 focus:ring-violet-500/15"
                            @keydown.meta.enter="submit"
                            @keydown.ctrl.enter="submit"
                        />
                        <p v-if="form.errors.prompt" class="mt-1.5 text-xs text-destructive">{{ form.errors.prompt }}</p>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <button
                                v-for="idea in ideas"
                                :key="idea"
                                type="button"
                                class="rounded-full border border-border px-2.5 py-1 text-[11px] text-muted-foreground transition hover:border-violet-400/50 hover:text-foreground"
                                @click="form.prompt = idea"
                            >
                                {{ idea.split(',')[0] }}
                            </button>
                        </div>
                    </div>

                    <!-- Size -->
                    <div>
                        <span class="mb-2 block text-sm font-medium">Size</span>
                        <div class="grid grid-cols-3 gap-2">
                            <button
                                v-for="size in pricing?.sizes ?? []"
                                :key="size"
                                type="button"
                                class="group flex flex-col items-center gap-2 rounded-xl border p-3 transition"
                                :class="
                                    form.size === size
                                        ? 'border-violet-400/70 bg-violet-500/10 shadow-[0_0_0_4px_hsl(263_90%_68%/0.12)]'
                                        : 'border-border hover:border-foreground/25'
                                "
                                @click="form.size = size"
                            >
                                <span class="flex h-9 items-center">
                                    <span
                                        class="rounded-[4px] border-2 transition"
                                        :class="form.size === size ? 'border-violet-400 bg-violet-400/20' : 'border-muted-foreground/50'"
                                        :style="{ width: `${(sizeMeta[size]?.w ?? 1) * 12}px`, height: `${(sizeMeta[size]?.h ?? 1) * 12}px` }"
                                    />
                                </span>
                                <span class="text-xs font-medium">{{ sizeMeta[size]?.label ?? size }}</span>
                                <span class="font-mono text-[10px] text-muted-foreground">{{ size }}</span>
                            </button>
                        </div>
                    </div>

                    <!-- Quality -->
                    <div>
                        <span class="mb-2 block text-sm font-medium">Quality</span>
                        <div class="relative grid grid-cols-3 rounded-xl bg-muted p-1">
                            <button
                                v-for="quality in pricing?.qualities ?? []"
                                :key="quality"
                                type="button"
                                class="relative z-10 rounded-lg px-3 py-2 text-xs font-medium capitalize transition"
                                :class="
                                    form.quality === quality
                                        ? 'bg-background text-foreground shadow-sm ring-1 ring-violet-400/40'
                                        : 'text-muted-foreground hover:text-foreground'
                                "
                                @click="form.quality = quality"
                            >
                                {{ quality }}
                            </button>
                        </div>
                        <p class="mt-1.5 font-mono text-[11px] text-muted-foreground">{{ qualityHint(form.quality) }}</p>
                    </div>

                    <!-- Reference images -->
                    <div>
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-sm font-medium"
                                >Reference images <span class="font-normal text-muted-foreground">(optional)</span></span
                            >
                            <button
                                v-if="form.reference_images.length < limits.reference_images"
                                type="button"
                                class="inline-flex items-center gap-1 text-xs text-violet-300 transition hover:text-violet-200"
                                @click="addReference"
                            >
                                <Plus class="size-3.5" /> Add URL
                            </button>
                        </div>
                        <TransitionGroup
                            tag="div"
                            class="flex flex-col gap-2"
                            enter-from-class="opacity-0 -translate-y-1"
                            enter-active-class="transition duration-200"
                            leave-to-class="opacity-0"
                            leave-active-class="transition duration-150"
                        >
                            <div v-for="(_, index) in form.reference_images" :key="index" class="flex items-center gap-2">
                                <div class="relative flex-1">
                                    <ImagePlus class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                                    <input
                                        v-model="form.reference_images[index]"
                                        type="url"
                                        placeholder="https://example.com/style.png"
                                        class="w-full rounded-lg border border-input bg-background/60 py-2 pl-9 pr-3 text-xs outline-none transition focus:border-violet-400/60 focus:ring-4 focus:ring-violet-500/15"
                                    />
                                </div>
                                <button
                                    type="button"
                                    class="rounded-lg p-2 text-muted-foreground transition hover:bg-muted hover:text-foreground"
                                    @click="removeReference(index)"
                                >
                                    <X class="size-4" />
                                </button>
                            </div>
                        </TransitionGroup>
                        <p v-if="!form.reference_images.length" class="text-[11px] text-muted-foreground">
                            Guide the style with up to {{ limits.reference_images }} image URLs ({{ pricing?.tokens_per_reference_image ?? 765 }}
                            tokens each).
                        </p>
                        <p
                            v-for="(error, key) in form.errors"
                            v-show="String(key).startsWith('reference_images')"
                            :key="key"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ error }}
                        </p>
                    </div>

                    <!-- Estimate -->
                    <div
                        class="relative overflow-hidden rounded-xl border border-violet-500/25 bg-gradient-to-br from-violet-500/10 via-fuchsia-500/5 to-cyan-400/10 p-4"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="flex items-center gap-1.5 text-[11px] uppercase tracking-wider text-muted-foreground">
                                    Estimated cost
                                    <Loader2 v-if="estimating" class="size-3 animate-spin" />
                                </div>
                                <div class="font-mono text-3xl font-semibold tracking-tight" :title="exactUsd(estimate?.expected_nanos)">
                                    ≈ {{ formatUsd(expected, { maxDecimals: 4 }) }}
                                </div>
                                <div v-if="estimate" class="mt-0.5 font-mono text-xs text-muted-foreground">
                                    {{ formatUsd(estimate.low_nanos, { maxDecimals: 4 }) }} – {{ formatUsd(estimate.max_nanos, { maxDecimals: 4 }) }}
                                </div>
                            </div>
                            <div class="text-right text-[11px] text-muted-foreground">
                                <div>Your credits</div>
                                <div class="font-mono text-sm text-foreground">{{ formatUsd(balance, { maxDecimals: 4 }) }}</div>
                            </div>
                        </div>

                        <dl v-if="breakdown.length" class="mt-4 space-y-1.5 border-t border-border/50 pt-3 text-xs">
                            <div v-for="row in breakdown" :key="row.label" class="flex items-center justify-between gap-2">
                                <dt class="text-muted-foreground">{{ row.label }}</dt>
                                <dd class="font-mono">
                                    {{ row.tokens }} <span class="text-muted-foreground">· ${{ row.rate }}/1M · {{ row.hint }}</span>
                                </dd>
                            </div>
                        </dl>

                        <p class="mt-3 flex gap-1.5 text-[11px] leading-relaxed text-muted-foreground">
                            <Info class="mt-0.5 size-3.5 shrink-0" />
                            <span
                                >Includes our 25% fee. We reserve the maximum while it runs, then charge the exact cost from Pixelforge's token usage.
                                Failed images cost nothing.</span
                            >
                        </p>
                    </div>

                    <Transition
                        enter-from-class="opacity-0 -translate-y-1"
                        enter-active-class="transition"
                        leave-to-class="opacity-0"
                        leave-active-class="transition"
                    >
                        <div
                            v-if="cannotAfford || creditsError"
                            class="flex items-start gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-200"
                        >
                            <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                            <span>
                                {{ creditsError ?? 'Not enough credits for the worst case of this image.' }}
                                <Link :href="route('credits.index')" class="font-medium underline underline-offset-2">Add credits</Link>
                            </span>
                        </div>
                    </Transition>

                    <button
                        type="submit"
                        class="btn-glow flex h-12 items-center justify-center gap-2 rounded-xl text-sm font-semibold"
                        :disabled="form.processing || !pricing || !form.prompt.trim() || cannotAfford"
                    >
                        <Loader2 v-if="form.processing" class="size-4 animate-spin" />
                        <Wand2 v-else class="size-4" />
                        {{ form.processing ? 'Sending…' : 'Generate image' }}
                        <kbd class="ml-1 hidden rounded bg-white/15 px-1.5 py-0.5 font-mono text-[10px] md:inline">⌘↵</kbd>
                    </button>

                    <!-- Testing helpers -->
                    <div class="-mt-2">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 text-[11px] text-muted-foreground transition hover:text-foreground"
                            @click="showTesting = !showTesting"
                        >
                            <FlaskConical class="size-3.5" /> {{ showTesting ? 'Hide' : 'Show' }} Pixelforge test keywords
                        </button>
                        <Transition
                            enter-from-class="opacity-0 -translate-y-1"
                            enter-active-class="transition duration-200"
                            leave-to-class="opacity-0"
                            leave-active-class="transition duration-150"
                        >
                            <div v-if="showTesting" class="mt-2 grid grid-cols-2 gap-1.5">
                                <button
                                    v-for="item in testKeywords"
                                    :key="item.keyword"
                                    type="button"
                                    class="rounded-lg border border-dashed border-border px-2 py-1.5 text-left transition hover:border-violet-400/50"
                                    @click="addKeyword(item.keyword)"
                                >
                                    <span class="block font-mono text-[11px] text-violet-300">{{ item.keyword }}</span>
                                    <span class="block text-[10px] text-muted-foreground">{{ item.hint }}</span>
                                </button>
                            </div>
                        </Transition>
                    </div>
                </form>

                <!-- Live feed -->
                <section class="min-w-0">
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="text-lg font-semibold">Recent</h2>
                        <Link :href="route('generations.index')" class="text-sm text-muted-foreground transition hover:text-foreground"
                            >View all history →</Link
                        >
                    </div>

                    <TransitionGroup
                        v-if="generations.length"
                        tag="div"
                        class="grid items-start gap-5 sm:grid-cols-2 xl:grid-cols-3"
                        enter-from-class="opacity-0 translate-y-4 scale-95"
                        enter-active-class="transition duration-500 ease-out"
                        move-class="transition duration-500"
                    >
                        <GenerationCard v-for="generation in generations" :key="generation.id" :generation="generation" />
                    </TransitionGroup>

                    <div v-else class="glass flex flex-col items-center justify-center gap-4 px-6 py-20 text-center">
                        <div class="relative">
                            <div class="absolute inset-0 animate-pulse rounded-full bg-violet-500/30 blur-2xl" />
                            <div
                                class="relative flex size-16 animate-float items-center justify-center rounded-2xl bg-gradient-to-br from-violet-500 via-fuchsia-500 to-cyan-400 shadow-xl shadow-violet-500/30"
                            >
                                <Sparkles class="size-7 text-white" />
                            </div>
                        </div>
                        <div>
                            <h3 class="font-semibold">Your canvas is empty</h3>
                            <p class="mt-1 text-sm text-muted-foreground">Write a prompt and your first image will appear here, live.</p>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
