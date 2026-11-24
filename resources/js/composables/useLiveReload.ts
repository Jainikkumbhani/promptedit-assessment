import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, watch, type Ref } from 'vue';

/**
 * While something is still in flight, quietly reload just the given props.
 * Stops on its own once everything has settled, and pauses in background tabs.
 */
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
