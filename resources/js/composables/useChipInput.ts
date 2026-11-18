import { ref } from 'vue';
import { withItem, withoutItem } from '@/utils';

/**
 * A text input with add / remove for a list of chips (languages, keywords,
 * tags). Replaces the addLang / removeLang style function pairs in Tracking,
 * Alerts and Settings.
 *
 * Always assigns a new array, so a list that came from page props is never
 * changed in place.
 *
 *     const langs = useChipInput(() => editForm.languages, v => (editForm.languages = v), { lowercase: true });
 *     // <input v-model="langs.input.value" @keydown.enter.prevent="langs.add"> … @click="langs.remove(l)"
 */
export function useChipInput(
    get: () => string[],
    set: (next: string[]) => void,
    options: { lowercase?: boolean } = {},
) {
    const input = ref('');

    function add() {
        let value = input.value.trim();
        if (options.lowercase) value = value.toLowerCase();
        if (value) set(withItem(get(), value));
        input.value = '';
    }

    function remove(value: string) {
        set(withoutItem(get(), value));
    }

    function reset() {
        input.value = '';
    }

    return { input, add, remove, reset };
}
