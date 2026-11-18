import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Validation errors of the current page, including errors that did not come
 * from a form on it (for example an SSO error after a redirect).
 */
export function usePageErrors() {
    const page = usePage();

    return computed(() => (page.props.errors ?? {}) as Record<string, string>);
}
