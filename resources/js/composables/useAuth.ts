import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import type { AuthUser, PageProps } from '@/types';

export function useAuth() {
    const page = usePage<PageProps>();
    const user = computed<AuthUser | null>(() => page.props.auth?.user ?? null);
    const isAdmin = computed(() => !!user.value?.is_admin);
    const isAuthenticated = computed(() => !!user.value);

    return { user, isAdmin, isAuthenticated };
}
