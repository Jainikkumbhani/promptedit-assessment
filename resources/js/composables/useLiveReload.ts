import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, watch, type Ref } from 'vue';

// Reload some props every few seconds while `active` is true.
export function useLiveReload(active: Ref<boolean>, only: string[], intervalMs = 2500) {
    let timer: ReturnType<typeof setTimeout> | undefined;

    const schedule = () => {
        clearTimeout(timer);

        if (!active.value) {
            return;
        }

        timer = setTimeout(() => {
            if (document.hidden) {
                schedule();
                return;
            }

            router.reload({ only, onFinish: schedule });
        }, intervalMs);
    };

    watch(active, schedule, { immediate: true });
    onBeforeUnmount(() => clearTimeout(timer));
}
