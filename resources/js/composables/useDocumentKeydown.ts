import { onUnmounted, watch, type WatchSource } from 'vue';

/**
 * Listen to keydown on the whole document while `active` is true (for
 * example while a dialog is open). Replaces the add/removeEventListener
 * pairs in ConfirmModal and NotificationDestinations.
 *
 *     useDocumentKeydown(() => props.show, (e) => { if (e.key === 'Escape') emit('cancel'); });
 */
export function useDocumentKeydown(active: WatchSource<boolean>, handler: (event: KeyboardEvent) => void) {
    const stop = () => document.removeEventListener('keydown', handler);

    watch(active, (on) => {
        if (on) document.addEventListener('keydown', handler);
        else stop();
    }, { immediate: true });

    onUnmounted(stop);
}
