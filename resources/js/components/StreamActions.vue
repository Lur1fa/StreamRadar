<script setup lang="ts">
import { computed, type Component } from 'vue';
import { router } from '@inertiajs/vue3';
import { ExternalLink, ShieldBan, UserMinus, UserPlus, Pin, PinOff } from 'lucide-vue-next';
import { twitchChannelUrl } from '@/utils';

/**
 * Pin, open, track/untrack and blacklist buttons of a stream. `labelled`
 * shows text buttons (card view); otherwise icons with tooltips (compact rows).
 */
const props = defineProps<{
    login: string;
    isPinned?: boolean;
    isTrackedChannel?: boolean;
    labelled?: boolean;
}>();

const emit = defineEmits<{
    togglePin: [login: string];
}>();

interface Action {
    key: string;
    icon: Component;
    label: string;
    tooltip: string;
    href?: string;
    run?: () => void;
    /** Hover colour of the icon button. */
    iconClass: string;
    /** Colours of the labelled button. */
    labelledClass: string;
}

const neutral = 'text-gray-500 hover:bg-gray-50 dark:text-zinc-400 dark:hover:bg-zinc-800';
const post = (url: string, data: Record<string, string>) => router.post(url, data, { preserveScroll: true });

const actions = computed<Action[]>(() => [
    {
        key: 'pin',
        icon: props.isPinned ? PinOff : Pin,
        label: props.isPinned ? 'Unpin' : 'Pin',
        tooltip: props.isPinned ? 'Unpin' : 'Pin',
        run: () => emit('togglePin', props.login),
        iconClass: 'hover:text-amber-500',
        labelledClass: props.isPinned ? 'text-amber-600 hover:bg-amber-50 dark:text-amber-400 dark:hover:bg-amber-500/10' : neutral,
    },
    {
        key: 'open',
        icon: ExternalLink,
        label: 'Watch',
        tooltip: 'Open on Twitch',
        href: twitchChannelUrl(props.login),
        iconClass: 'hover:text-purple-500 dark:hover:text-purple-400',
        labelledClass: 'text-purple-600 hover:bg-purple-50 dark:text-purple-400 dark:hover:bg-purple-500/10',
    },
    ...(props.isTrackedChannel
        ? [{
            key: 'untrack',
            icon: UserMinus,
            label: 'Untrack',
            tooltip: 'Untrack channel',
            run: () => router.delete(`/tracking/channels/by-login/${encodeURIComponent(props.login)}`, { preserveScroll: true }),
            iconClass: 'hover:text-orange-500',
            labelledClass: `${neutral} hover:text-orange-600 dark:hover:text-orange-400`,
        }]
        : [{
            key: 'track',
            icon: UserPlus,
            label: 'Track',
            tooltip: 'Track channel',
            run: () => post('/tracking/channels', { user_login: props.login }),
            iconClass: 'hover:text-green-500',
            labelledClass: `${neutral} hover:text-green-600 dark:hover:text-green-400`,
        }, {
            key: 'block',
            icon: ShieldBan,
            label: 'Block',
            tooltip: 'Blacklist',
            run: () => post('/blacklist', { type: 'channel', value: props.login }),
            iconClass: 'hover:text-red-500',
            labelledClass: `${neutral} hover:text-red-600 dark:hover:text-red-400`,
        }]),
]);
</script>

<template>
    <div :class="['flex items-center gap-1 sm:opacity-0 sm:group-hover:opacity-100 transition-opacity', labelled && 'mt-2 pt-2 border-t border-gray-100/60 dark:border-zinc-800/60']">
        <component
            :is="action.href ? 'a' : 'button'"
            v-for="action in actions"
            :key="action.key"
            :href="action.href"
            :target="action.href ? '_blank' : undefined"
            :type="action.href ? undefined : 'button'"
            v-tooltip="labelled ? null : action.tooltip"
            :class="labelled
                ? ['flex items-center gap-1 px-2 py-1 text-xs font-medium rounded-md transition-colors', action.labelledClass]
                : ['p-1 text-gray-400', action.iconClass]"
            @click="action.run?.()"
        >
            <component :is="action.icon" :class="labelled ? 'w-3.5 h-3.5' : 'w-4 h-4'" />
            <template v-if="labelled">{{ action.label }}</template>
        </component>
    </div>
</template>
