/**
 * Shared, framework-free helpers. Vue-specific helpers (anything that needs
 * refs or lifecycle hooks) live in composables/.
 */

import type { DestinationType, DestinationTypeInfo } from '@/types';

// ── Twitch ──────────────────────────────────────────────────────────────

export function twitchChannelUrl(login: string): string {
    return `https://www.twitch.tv/${encodeURIComponent(login)}`;
}

export function twitchCategoryUrl(name: string): string {
    const slug = name.toLowerCase().replace(/&/g, 'and').replace(/[^a-z0-9\s-]/g, '').replace(/\s+/g, '-').replace(/-+/g, '-');
    return 'https://www.twitch.tv/directory/category/' + slug;
}

/** Fill a Twitch `{width}` / `{height}` image template; '' when there is none. */
export function sizedImage(template: string | null | undefined, width: number, height: number): string {
    if (!template) return '';
    return template.replace('{width}', String(width)).replace('{height}', String(height));
}

// ── Dates ───────────────────────────────────────────────────────────────

/**
 * "Just now", "5m ago", "3h ago", then a date formatted with `olderFormat`.
 * Pass `useNow().value` as `now` so the label refreshes on its own.
 */
export function relativeTime(
    iso: string | null | undefined,
    now: number = Date.now(),
    olderFormat: Intl.DateTimeFormatOptions = {},
    empty = 'Never',
): string {
    if (!iso) return empty;
    const date = new Date(iso);
    const minutes = Math.floor((now - date.getTime()) / 60000);
    if (minutes < 1) return 'Just now';
    if (minutes < 60) return `${minutes}m ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h ago`;
    return date.toLocaleDateString(undefined, olderFormat);
}

/** A timestamp in the viewer's locale (date and time unless `options` narrow it); `empty` when there is none. */
export function formatDate(iso: string | null | undefined, options?: Intl.DateTimeFormatOptions, empty = ''): string {
    return iso ? new Date(iso).toLocaleString(undefined, options) : empty;
}

// ── JSON and browser storage ────────────────────────────────────────────

export function parseJson<T>(raw: string | null | undefined, fallback: T): T {
    if (raw == null || raw === '') return fallback;
    try {
        return JSON.parse(raw) as T;
    } catch {
        return fallback;
    }
}

/**
 * A JSON value from localStorage (sp_pinned, sp_collapsed). Keys that hold a
 * plain string (sp_density, sp_group, sr_tracking_sort, theme) are not JSON:
 * keep reading those with localStorage.getItem().
 */
export function readStored<T>(key: string, fallback: T): T {
    try {
        return parseJson(localStorage.getItem(key), fallback);
    } catch {
        return fallback;
    }
}

export function writeStored(key: string, value: unknown): void {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Storage full or blocked (private mode): the preference is simply not kept.
    }
}

// ── HTTP ────────────────────────────────────────────────────────────────

interface HttpErrorLike {
    response?: { status?: number; data?: { message?: unknown; error?: unknown } };
    message?: unknown;
}

/** The message to show for a failed axios request. */
export function requestErrorMessage(
    error: unknown,
    fallback = 'Request failed.',
    tooManyRequests = 'Too many requests. Wait a minute and try again.',
): string {
    const e = (error ?? {}) as HttpErrorLike;
    if (e.response?.status === 429) return tooManyRequests;
    for (const candidate of [e.response?.data?.message, e.response?.data?.error, e.message]) {
        if (typeof candidate === 'string' && candidate !== '') return candidate;
    }
    return fallback;
}

// ── Lists ───────────────────────────────────────────────────────────────

/** A copy of `list` with `value` added at the end, unless it is already there. */
export function withItem<T>(list: readonly T[], value: T): T[] {
    return list.includes(value) ? [...list] : [...list, value];
}

/** A copy of `list` without `value`. */
export function withoutItem<T>(list: readonly T[], value: T): T[] {
    return list.filter(item => item !== value);
}

/** Every distinct tag of the given categories, sorted. */
export function distinctTags(categories: readonly { tags?: string[] | null }[]): string[] {
    const tags = new Set<string>();
    categories.forEach(c => c.tags?.forEach(t => tags.add(t)));
    return Array.from(tags).sort();
}

// ── Alert destinations ──────────────────────────────────────────────────

/** How alert rules show each destination type, in the order they list them. */
const DESTINATION_COLORS: Record<DestinationType, { on: string; text: string }> = {
    discord: { on: 'bg-indigo-50 dark:bg-indigo-500/10 text-indigo-700 dark:text-indigo-300 border-indigo-200 dark:border-indigo-500/30', text: 'text-indigo-500' },
    email: { on: 'bg-blue-50 dark:bg-blue-500/10 text-blue-700 dark:text-blue-300 border-blue-200 dark:border-blue-500/30', text: 'text-blue-500' },
    telegram: { on: 'bg-sky-50 dark:bg-sky-500/10 text-sky-700 dark:text-sky-300 border-sky-200 dark:border-sky-500/30', text: 'text-sky-500' },
    ntfy: { on: 'bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 border-emerald-200 dark:border-emerald-500/30', text: 'text-emerald-500' },
    webhook: { on: 'bg-orange-50 dark:bg-orange-500/10 text-orange-700 dark:text-orange-300 border-orange-200 dark:border-orange-500/30', text: 'text-orange-500' },
};

/** The server's destination types with their colours, in display order. */
export function destinationChannels(types: readonly DestinationTypeInfo[]) {
    const order = Object.keys(DESTINATION_COLORS);
    return [...types]
        .sort((a, b) => order.indexOf(a.value) - order.indexOf(b.value))
        .map(type => ({ ...type, ...DESTINATION_COLORS[type.value] }));
}
