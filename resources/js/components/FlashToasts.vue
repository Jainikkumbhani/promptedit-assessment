<script setup lang="ts">
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { CheckCircle2, X, XCircle } from 'lucide-vue-next';
import { ref, watch } from 'vue';

interface Toast {
    id: number;
    kind: 'success' | 'error';
    message: string;
}

const page = usePage<SharedData>();
const toasts = ref<Toast[]>([]);
let nextId = 1;

const push = (kind: Toast['kind'], message: string | null | undefined) => {
    if (!message) return;

    const id = nextId++;
    toasts.value.push({ id, kind, message });
    setTimeout(() => dismiss(id), 4500);
};

const dismiss = (id: number) => (toasts.value = toasts.value.filter((t) => t.id !== id));

watch(
    () => page.props.flash,
    (flash) => {
        push('success', flash?.success);
        push('error', flash?.error);
    },
    { immediate: true, deep: true },
);
</script>

<template>
    <div class="pointer-events-none fixed bottom-4 right-4 z-50 flex w-full max-w-sm flex-col gap-2">
        <TransitionGroup
            enter-from-class="translate-y-4 opacity-0 scale-95"
            enter-active-class="transition duration-300 ease-out"
            leave-to-class="translate-x-8 opacity-0"
            leave-active-class="transition duration-200 ease-in"
        >
            <div v-for="toast in toasts" :key="toast.id" class="glass pointer-events-auto flex items-start gap-3 p-4 text-sm" role="status">
                <CheckCircle2 v-if="toast.kind === 'success'" class="mt-0.5 size-5 shrink-0 text-success" />
                <XCircle v-else class="mt-0.5 size-5 shrink-0 text-destructive" />
                <p class="flex-1">{{ toast.message }}</p>
                <button class="text-muted-foreground transition hover:text-foreground" @click="dismiss(toast.id)">
                    <X class="size-4" />
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>
