import { onUnmounted } from 'vue';

/**
 * Call `fn` only once calls have stopped for `delayMs`. A pending call is
 * dropped when the component unmounts. Replaces the hand-rolled
 * clearTimeout / setTimeout search handlers in Dashboard, History and Tracking.
 *
 *     const search = useDebouncedFn((q: string) => applyFilter('search', q || null), 300);
 */
export function useDebouncedFn<A extends unknown[]>(fn: (...args: A) => void, delayMs: number) {
    let timer: ReturnType<typeof setTimeout> | undefined;

    function cancel() {
        if (timer !== undefined) clearTimeout(timer);
        timer = undefined;
    }

    function run(...args: A) {
        cancel();
        timer = setTimeout(() => {
            timer = undefined;
            fn(...args);
        }, delayMs);
    }

    onUnmounted(cancel);

    return Object.assign(run, { cancel });
}
