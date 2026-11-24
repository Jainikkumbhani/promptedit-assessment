import { onBeforeUnmount, ref, watch, type Ref } from 'vue';

/** Smoothly counts towards a changing number (easeOutExpo), for prices and balances. */
export function useAnimatedNumber(source: Ref<number>, duration = 650) {
    const display = ref(source.value);
    let frame = 0;

    watch(source, (to) => {
        cancelAnimationFrame(frame);

        const from = display.value;
        const start = performance.now();

        const tick = (now: number) => {
            const t = Math.min(1, (now - start) / duration);
            const eased = t === 1 ? 1 : 1 - Math.pow(2, -10 * t);
            display.value = Math.round(from + (to - from) * eased);

            if (t < 1) {
                frame = requestAnimationFrame(tick);
            }
        };

        frame = requestAnimationFrame(tick);
    });

    onBeforeUnmount(() => cancelAnimationFrame(frame));

    return display;
}
