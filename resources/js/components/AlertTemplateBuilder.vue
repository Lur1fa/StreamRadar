<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { ChevronDown, Plus, RotateCcw, Trash2, AlertTriangle } from 'lucide-vue-next';
import TokenField from '@/components/TokenField.vue';
import type { AlertAttachment, AlertClickAction, AlertNotificationTemplate, AlertTemplateBlock, AlertTemplateFields } from '@/types';
import { fromState, isLink, MAX_ACTIONS, renderBlocks, sameBlocks, textToBlocks, toState } from '@/utils/alertTemplate';

/**
 * The notification of an alert rule: its message (text with tokens), what a
 * click opens, the attached image, and ntfy action buttons. Emits null while
 * everything is left at the defaults.
 */
const props = withDefaults(defineProps<{
    modelValue: AlertNotificationTemplate | null;
    /** Template fields with label, description and sample value (AlertController::templateProps()). */
    fields: AlertTemplateFields;
    /** The built-in message as blocks (AlertTemplateValidator::DEFAULT_MESSAGE). */
    defaultMessage: AlertTemplateBlock[];
    /** The form's validation errors; those of `notification_template…` are shown here. */
    errors?: Record<string, string>;
    fieldBg?: string;
}>(), { errors: () => ({}), fieldBg: 'bg-gray-50' });

const emit = defineEmits<{ 'update:modelValue': [value: AlertNotificationTemplate | null] }>();

const state = reactive(toState(props.modelValue, props.defaultMessage));
let lastEmitted = JSON.stringify(fromState(state, props.defaultMessage));

watch(state, () => {
    const template = fromState(state, props.defaultMessage);
    const json = JSON.stringify(template);
    if (json !== lastEmitted) {
        lastEmitted = json;
        emit('update:modelValue', template);
    }
}, { deep: true });

// From outside: the form was reset, or another rule is edited.
watch(() => props.modelValue, (value) => {
    if (JSON.stringify(value ?? null) === lastEmitted) return;
    Object.assign(state, toState(value, props.defaultMessage));
    lastEmitted = JSON.stringify(fromState(state, props.defaultMessage));
}, { deep: true });

const open = ref(props.modelValue !== null || Object.keys(props.errors).some(k => k.startsWith('notification_template')));
const customized = computed(() => props.modelValue !== null);

// ── Message ─────────────────────────────────────────────────────────
const messageIsDefault = computed(() => !state.message.length || sameBlocks(state.message, props.defaultMessage));
const messagePreview = computed(() => renderBlocks(state.message.length ? state.message : props.defaultMessage, props.fields));
function resetMessage() { state.message = props.defaultMessage.map(b => ({ ...b })); }

// ── Click ───────────────────────────────────────────────────────────
const clickOptions: { value: AlertClickAction; label: string }[] = [
    { value: 'stream', label: 'Open the stream on Twitch' },
    { value: 'custom', label: 'Open a custom link' },
    { value: 'none', label: 'Do nothing' },
];
const clickPreview = computed(() => state.click === 'custom' ? renderBlocks(state.click_url, props.fields, true) : props.fields.url?.sample ?? '');

// ── Attachment ──────────────────────────────────────────────────────
const attachmentOptions: { value: AlertAttachment; label: string }[] = [
    { value: 'preview', label: 'Stream preview image' },
    { value: 'avatar', label: 'Streamer avatar' },
    { value: 'box_art', label: 'Category box art' },
    { value: 'none', label: 'No attachment' },
];

// ── Action buttons (ntfy) ───────────────────────────────────────────
const presets = [
    { label: 'Open chat', url: 'https://www.twitch.tv/popout/{StreamerLogin}/chat' },
    { label: 'Past broadcasts', url: 'https://www.twitch.tv/{StreamerLogin}/videos' },
];
const availablePresets = computed(() => presets.filter(p => !state.actions.some(a => renderBlocks(a.label, props.fields) === p.label)));

function addAction(preset?: { label: string; url: string }) {
    if (state.actions.length >= MAX_ACTIONS) return;
    state.actions.push(preset
        ? { label: textToBlocks(preset.label, props.fields), url: textToBlocks(preset.url, props.fields) }
        : { label: [], url: [] });
}
function removeAction(index: number) { state.actions.splice(index, 1); }

const actionPreviews = computed(() => state.actions.map(a => ({
    label: renderBlocks(a.label, props.fields).trim(),
    url: renderBlocks(a.url, props.fields, true),
})));

// ── Errors ──────────────────────────────────────────────────────────
function error(key: string): string | undefined {
    const entry = Object.entries(props.errors).find(([k]) => k === key || k.startsWith(`${key}.`));
    return entry?.[1];
}
const generalError = computed(() => props.errors['notification_template'] ?? error('notification_template.attachment') ?? error('notification_template.click'));

const select = 'px-3 py-1.5 text-sm rounded-lg text-gray-900 dark:text-white dark:bg-zinc-800 outline-none focus:ring-2 focus:ring-purple-500 font-medium';
const sectionLabel = 'text-sm font-semibold text-gray-900 dark:text-white';
const appliesTo = 'text-[11px] text-gray-400 dark:text-zinc-500';
</script>

<template>
    <div class="space-y-4">
        <button type="button" @click="open = !open" :aria-expanded="open"
            class="flex items-center gap-1.5 text-xs font-medium text-gray-400 dark:text-zinc-500 hover:text-gray-600 dark:hover:text-zinc-300 transition-colors">
            <ChevronDown class="w-3.5 h-3.5 transition-transform" :class="{ 'rotate-180': open }" />
            {{ open ? 'Hide notification options' : 'Customize notification' }}
            <span v-if="customized && !open" class="w-1.5 h-1.5 rounded-full bg-purple-500"></span>
        </button>

        <div v-if="open" class="space-y-5">
            <!-- Message -->
            <section class="rounded-xl border border-gray-200 dark:border-zinc-800 p-4 space-y-3">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h4 :class="sectionLabel">Message</h4>
                        <p :class="appliesTo">ntfy · Discord · Telegram · Email</p>
                    </div>
                    <button type="button" @click="resetMessage" :disabled="messageIsDefault" title="Reset to the built-in message" aria-label="Reset to the built-in message"
                        class="p-1.5 rounded-lg border border-gray-300 dark:border-zinc-600 text-gray-600 dark:text-zinc-300 hover:text-purple-700 hover:border-purple-300 dark:hover:text-purple-300 disabled:opacity-40 disabled:pointer-events-none transition-colors">
                        <RotateCcw class="w-3.5 h-3.5" />
                    </button>
                </div>

                <TokenField v-model="state.message" :fields="fields" multiline label="Notification message" :field-bg="fieldBg"
                    placeholder="Empty: the built-in message is sent" />
                <p v-if="error('notification_template.message')" class="text-[11px] text-red-600 dark:text-red-400">{{ error('notification_template.message') }}</p>

                <div class="rounded-lg border border-gray-200 dark:border-zinc-800 px-3 py-2.5">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-zinc-400">Preview</div>
                    <div class="mt-1 font-mono text-sm font-medium text-purple-700 dark:text-purple-300 whitespace-pre-wrap break-words">{{ messagePreview }}</div>
                    <div v-if="!state.message.length" class="mt-1 text-[11px] text-gray-400 dark:text-zinc-500">The message is empty, so the built-in message is sent.</div>
                </div>
            </section>

            <!-- On click -->
            <section class="space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                    <h4 :class="[sectionLabel, 'w-28 shrink-0']">On click</h4>
                    <select v-model="state.click" :class="[select, fieldBg]" aria-label="On click">
                        <option v-for="o in clickOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                    </select>
                    <span :class="appliesTo">ntfy · Discord</span>
                </div>
                <div v-if="state.click === 'custom'" class="sm:pl-30 space-y-1">
                    <TokenField v-model="state.click_url" :fields="fields" compact :spellcheck="false" label="Custom click link" :field-bg="fieldBg"
                        placeholder="https://…" />
                    <p v-if="error('notification_template.click_url')" class="text-[11px] text-red-600 dark:text-red-400">{{ error('notification_template.click_url') }}</p>
                    <p v-else-if="state.click_url.length && !isLink(clickPreview)" class="text-[11px] text-amber-600 dark:text-amber-400">Start the link with https:// and leave out spaces.</p>
                    <p v-else-if="clickPreview" class="text-[11px] font-mono text-gray-500 dark:text-zinc-400 break-all">→ {{ clickPreview }}</p>
                </div>
            </section>

            <!-- Attachment -->
            <section class="flex flex-wrap items-center gap-2">
                <h4 :class="[sectionLabel, 'w-28 shrink-0']">Attachment</h4>
                <select v-model="state.attachment" :class="[select, fieldBg]" aria-label="Attachment">
                    <option v-for="o in attachmentOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
                <span :class="appliesTo">ntfy · Discord · Telegram · Email</span>
            </section>

            <!-- Action buttons -->
            <section class="space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                    <h4 :class="sectionLabel">Action buttons</h4>
                    <span :class="appliesTo">ntfy · up to {{ MAX_ACTIONS }}, each opens a link</span>
                </div>

                <div v-for="(action, i) in state.actions" :key="i" class="space-y-1">
                    <div class="flex items-start gap-2">
                        <div class="grid flex-1 min-w-0 gap-2 sm:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
                            <TokenField v-model="action.label" :fields="fields" compact :label="`Button ${i + 1} label`" :field-bg="fieldBg" placeholder="Label" />
                            <TokenField v-model="action.url" :fields="fields" compact :spellcheck="false" :label="`Button ${i + 1} link`" :field-bg="fieldBg" placeholder="https://…" />
                        </div>
                        <button type="button" @click="removeAction(i)" :aria-label="`Remove button ${i + 1}`"
                            class="p-2 mt-0.5 text-gray-400 hover:text-red-600 dark:hover:text-red-400 rounded-lg hover:bg-gray-100 dark:hover:bg-zinc-800 transition-colors">
                            <Trash2 class="w-4 h-4" />
                        </button>
                    </div>
                    <p v-if="error(`notification_template.actions.${i}`)" class="text-[11px] text-red-600 dark:text-red-400">{{ error(`notification_template.actions.${i}`) }}</p>
                    <p v-else-if="action.url.length && !isLink(actionPreviews[i].url)" class="text-[11px] text-amber-600 dark:text-amber-400">Start the link with https:// and leave out spaces.</p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" @click="addAction()" :disabled="state.actions.length >= MAX_ACTIONS"
                        class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-md border border-gray-300 dark:border-zinc-600 text-gray-600 dark:text-zinc-300 hover:border-purple-300 hover:text-purple-700 dark:hover:text-purple-300 disabled:opacity-40 disabled:pointer-events-none transition-colors">
                        <Plus class="w-3 h-3" /> Button
                    </button>
                    <template v-if="state.actions.length < MAX_ACTIONS">
                        <button v-for="p in availablePresets" :key="p.label" type="button" @click="addAction(p)"
                            class="px-2 py-0.5 text-xs rounded-full bg-gray-100 dark:bg-zinc-800 text-gray-500 dark:text-zinc-400 hover:text-purple-700 dark:hover:text-purple-300 transition-colors">
                            + {{ p.label }}
                        </button>
                    </template>
                </div>
                <p v-if="error('notification_template.actions') && !state.actions.some((_, i) => error(`notification_template.actions.${i}`))"
                    class="text-[11px] text-red-600 dark:text-red-400">{{ error('notification_template.actions') }}</p>

                <div v-if="actionPreviews.some(a => a.label)" class="flex flex-wrap gap-1.5 pt-1">
                    <span v-for="(a, i) in actionPreviews" :key="i" v-show="a.label" :title="a.url"
                        :class="['px-3 py-1 text-xs font-medium rounded-md border', isLink(a.url)
                            ? 'border-purple-200 dark:border-purple-500/30 text-purple-700 dark:text-purple-300'
                            : 'border-amber-300 dark:border-amber-500/40 text-amber-700 dark:text-amber-400']">{{ a.label }}</span>
                </div>
            </section>

            <div v-if="generalError" class="flex items-center gap-2 px-3 py-2 text-xs font-medium bg-red-50 dark:bg-red-500/10 text-red-700 dark:text-red-400 rounded-lg">
                <AlertTriangle class="w-3.5 h-3.5 shrink-0" /> {{ generalError }}
            </div>
            <p class="text-[11px] text-gray-400 dark:text-zinc-500">Webhook destinations always receive the full JSON payload instead.</p>
        </div>
    </div>
</template>
