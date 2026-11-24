<script setup lang="ts">
import { useAnimatedNumber } from '@/composables/useAnimatedNumber';
import { exactUsd, formatUsd } from '@/lib/money';
import type { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { Plus, Wallet } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const page = usePage<SharedData>();
const balance = computed(() => page.props.auth.user?.balance_nanos ?? 0);
const shown = useAnimatedNumber(balance);

// Flash briefly when the balance changes, so charges and refunds are noticeable.
const pulse = ref(false);
watch(balance, () => {
    pulse.value = true;
    setTimeout(() => (pulse.value = false), 900);
});
</script>

<template>
    <Link
        :href="route('credits.index')"
        class="group relative flex items-center gap-3 overflow-hidden rounded-xl border border-sidebar-border bg-gradient-to-br from-violet-500/10 via-transparent to-cyan-400/10 p-3 transition hover:border-violet-400/50 group-data-[collapsible=icon]:hidden"
        :title="exactUsd(balance)"
    >
        <div class="flex size-9 items-center justify-center rounded-lg bg-violet-500/15 text-violet-400">
            <Wallet class="size-4" />
        </div>
        <div class="min-w-0 flex-1">
            <div class="text-[11px] uppercase tracking-wider text-muted-foreground">Credits</div>
            <div class="font-mono text-sm font-semibold transition-colors duration-500" :class="pulse ? 'text-fuchsia-400' : 'text-foreground'">
                {{ formatUsd(shown, { maxDecimals: 4 }) }}
            </div>
        </div>
        <Plus class="size-4 text-muted-foreground transition group-hover:rotate-90 group-hover:text-violet-400" />
    </Link>
</template>
