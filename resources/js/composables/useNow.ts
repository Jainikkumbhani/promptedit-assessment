import { onBeforeUnmount, onMounted, ref } from 'vue';

/** A ticking "now", for elapsed-time labels. */
export function useNow(intervalMs = 1000) {
    const now = ref(Date.now());
    let timer: ReturnType<typeof setInterval> | undefined;

    onMounted(() => (timer = setInterval(() => (now.value = Date.now()), intervalMs)));
    onBeforeUnmount(() => clearInterval(timer));

    return now;
}

export function secondsSince(iso: string | null, now: number): number {
    return iso ? Math.max(0, Math.round((now - new Date(iso).getTime()) / 1000)) : 0;
}

export function formatDuration(seconds: number): string {
    return seconds < 60 ? `${seconds}s` : `${Math.floor(seconds / 60)}m ${String(seconds % 60).padStart(2, '0')}s`;
}
