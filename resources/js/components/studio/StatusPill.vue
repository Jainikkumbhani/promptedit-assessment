<script setup lang="ts">
import type { GenerationStatus } from '@/types';
import { AlertTriangle, Check, Clock, Loader2, Send } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ status: GenerationStatus; retryReason?: string | null }>();

const config = computed(() => {
    switch (props.status) {
        case 'submitting':
            return {
                label: props.retryReason ? 'Waiting for a slot' : 'Submitting',
                icon: Send,
                tone: 'text-sky-300 bg-sky-500/10 ring-sky-500/30',
                dot: 'bg-sky-400',
                live: true,
            };
        case 'queued':
            return { label: 'Queued', icon: Clock, tone: 'text-amber-300 bg-amber-500/10 ring-amber-500/30', dot: 'bg-amber-400', live: true };
        case 'processing':
            return {
                label: 'Processing',
                icon: Loader2,
                tone: 'text-violet-300 bg-violet-500/10 ring-violet-500/30',
                dot: 'bg-violet-400',
                live: true,
            };
        case 'succeeded':
            return {
                label: 'Succeeded',
                icon: Check,
                tone: 'text-emerald-300 bg-emerald-500/10 ring-emerald-500/30',
                dot: 'bg-emerald-400',
                live: false,
            };
        default:
            return { label: 'Failed', icon: AlertTriangle, tone: 'text-rose-300 bg-rose-500/10 ring-rose-500/30', dot: 'bg-rose-400', live: false };
    }
});
</script>

<template>
    <Transition
        mode="out-in"
        enter-from-class="opacity-0 scale-90"
        enter-active-class="transition duration-300"
        leave-to-class="opacity-0 scale-90"
        leave-active-class="transition duration-150"
    >
        <span
            :key="config.label"
            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset backdrop-blur"
            :class="config.tone"
        >
            <span class="relative flex size-2">
                <span v-if="config.live" class="absolute inline-flex size-full animate-pulse-ring rounded-full" :class="config.dot" />
                <span class="relative inline-flex size-2 rounded-full" :class="config.dot" />
            </span>
            <component :is="config.icon" class="size-3.5" :class="{ 'animate-spin': status === 'processing' }" />
            {{ config.label }}
        </span>
    </Transition>
</template>
