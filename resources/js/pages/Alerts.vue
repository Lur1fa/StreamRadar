<script setup lang="ts">
import { ref, computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Plus, Bell, Trash2, CheckCircle, XCircle, X, Pencil } from 'lucide-vue-next';
import AppLayout from '@/layouts/AppLayout.vue';
import ConfirmModal from '@/components/ConfirmModal.vue';
import AlertRuleForm from '@/components/AlertRuleForm.vue';
import { useNow } from '@/composables/useNow';
import { destinationChannels, relativeTime } from '@/utils';
import { fromState, toState } from '@/utils/alertTemplate';
import type { AlertRule, AlertRuleFields, AlertTemplateBlock, AlertTemplateFields, Category, DestinationType, DestinationTypeInfo, NotifyKey } from '@/types';

const props = defineProps<{
    alertRules: AlertRule[];
    categories: Category[];
    /** Types the account has a working destination for. */
    readyTypes: DestinationType[];
    /** The server's destination types (DestinationType::forBrowser()). */
    destinationTypes: DestinationTypeInfo[];
    /** Template fields with label, description and sample value (AlertController::templateProps()). */
    templateFields: AlertTemplateFields;
    /** The built-in message as blocks (AlertTemplateValidator::DEFAULT_MESSAGE). */
    templateDefaultMessage: AlertTemplateBlock[];
}>();

// A rule goes to every enabled destination of each type it has switched on (Settings → Notifications).
const channels = computed(() => destinationChannels(props.destinationTypes));

/** One rule switch per destination type. */
function flags(on: (type: DestinationTypeInfo) => boolean): Record<NotifyKey, boolean> {
    return Object.fromEntries(props.destinationTypes.map(t => [t.flag, on(t)])) as Record<NotifyKey, boolean>;
}

/** A new rule: sent to every type the account can already deliver to. */
function blankRule(): AlertRuleFields {
    return {
        name: '', streamer_login: '', category_id: null, category_ids: [], category_tags: [],
        min_viewers: null, min_avg_viewers: null, language: '', keywords: [],
        ...flags(t => props.readyTypes.includes(t.value)),
        notify_on_category_change: false, notify_on_stream_start: true, notification_template: null,
    };
}

// ── New alert ───────────────────────────────────────────────────────
const showForm = ref(false);
const form = useForm(blankRule());

function toggleForm() {
    showForm.value = !showForm.value;
    if (!showForm.value) form.reset();
    editingId.value = null;
}

function submit() {
    form.post('/alerts', { preserveScroll: true, onSuccess: () => { form.reset(); showForm.value = false; } });
}

// ── Edit ────────────────────────────────────────────────────────────
const editingId = ref<number | null>(null);
const editForm = useForm(blankRule());

function startEdit(rule: AlertRule) {
    // Copies, so editing never changes the rule shown in the list until it is saved.
    Object.assign(editForm, {
        name: rule.name,
        streamer_login: rule.streamer_login || '',
        category_id: rule.category_id,
        category_ids: [...(rule.category_ids ?? [])],
        category_tags: [...(rule.category_tags ?? [])],
        min_viewers: rule.min_viewers,
        min_avg_viewers: rule.min_avg_viewers,
        language: rule.language || '',
        keywords: [...(rule.keywords ?? [])],
        ...flags(t => !!rule[t.flag]),
        notify_on_category_change: rule.notify_on_category_change,
        notify_on_stream_start: rule.notify_on_stream_start,
        // A copy in the current format (older rules may still have a version 1 template).
        notification_template: fromState(toState(rule.notification_template, props.templateDefaultMessage), props.templateDefaultMessage),
    });
    editForm.clearErrors();
    editingId.value = rule.id;
}

function saveEdit(rule: AlertRule) {
    editForm.put(`/alerts/${rule.id}`, { preserveScroll: true, onSuccess: () => { editingId.value = null; } });
}

// ── Actions ─────────────────────────────────────────────────────────
function toggleActive(rule: AlertRule) { router.put(`/alerts/${rule.id}`, { is_active: !rule.is_active }, { preserveScroll: true }); }
const deletingRule = ref<AlertRule | null>(null);
function deleteRule() { if (!deletingRule.value) return; router.delete(`/alerts/${deletingRule.value.id}`, { preserveScroll: true, onSuccess: () => { deletingRule.value = null; } }); }

const now = useNow();
function formatTime(dateStr: string): string {
    return relativeTime(dateStr, now.value, { month: 'short', day: 'numeric' });
}
</script>

<template>
    <AppLayout>
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-xl font-bold text-gray-900 dark:text-white">Alert Rules</h2>
            <button @click="toggleForm"
                class="px-4 py-2 text-sm font-medium bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors flex items-center gap-1.5">
                <Plus v-if="!showForm" class="w-4 h-4" />
                <X v-else class="w-4 h-4" />
                {{ showForm ? 'Cancel' : 'New Alert' }}
            </button>
        </div>

        <!-- ── New Alert: Sentence Builder ─────────────────────────── -->
        <Transition enter-active-class="transition ease-out duration-200" enter-from-class="opacity-0 -translate-y-2" enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition ease-in duration-150" leave-from-class="opacity-100" leave-to-class="opacity-0 -translate-y-2">
            <div v-if="showForm" class="mb-6 bg-white dark:bg-zinc-900 rounded-xl shadow-sm dark:shadow-none overflow-hidden p-5">
                <AlertRuleForm :form="form" variant="create" :categories="categories" :types="destinationTypes" :ready-types="readyTypes" :template-fields="templateFields" :template-default-message="templateDefaultMessage" @submit="submit" />
            </div>
        </Transition>

        <!-- ── Empty state ─────────────────────────────────────────── -->
        <div v-if="alertRules.length === 0 && !showForm" class="text-center py-16">
            <Bell class="w-16 h-16 mx-auto text-gray-300 dark:text-zinc-700" :stroke-width="1" />
            <h3 class="mt-4 text-lg font-semibold text-gray-700 dark:text-zinc-300">No alert rules</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-zinc-500">Create a rule to get notified when streams match your criteria.</p>
        </div>

        <!-- ── Rules List ──────────────────────────────────────────── -->
        <TransitionGroup v-else name="list" tag="div" class="space-y-3">
            <div v-for="rule in alertRules" :key="rule.id"
                :class="['bg-white dark:bg-zinc-900 rounded-xl shadow-sm dark:shadow-none transition-all overflow-hidden', !rule.is_active && 'opacity-50']">
                <div class="flex items-start justify-between gap-4 p-4">
                    <div class="flex-1">
                        <div class="flex items-center gap-2">
                            <h3 class="font-semibold text-gray-900 dark:text-white">{{ rule.name }}</h3>
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5 mt-1.5 text-xs text-gray-500 dark:text-zinc-500">
                            <span v-if="rule.streamer_login" class="px-2 py-0.5 accent-soft rounded-full font-medium">@{{ rule.streamer_login }}</span>
                            <span v-else class="px-2 py-0.5 bg-gray-50 dark:bg-zinc-800 text-gray-500 dark:text-zinc-400 rounded-full">Anyone</span>
                            <span class="text-gray-300 dark:text-zinc-700">&middot;</span>
                            <span v-if="rule.category_tags?.length" class="text-purple-600 dark:text-purple-400">{{ rule.category_tags.join(', ') }}</span>
                            <span v-else-if="rule.category_ids?.length">{{ rule.category_ids.map(id => categories.find(c => c.id === id)?.name).filter(Boolean).join(', ') }}</span>
                            <span v-else>{{ rule.category?.name || 'Any category' }}</span>
                            <template v-if="rule.notify_on_category_change">
                                <span class="text-gray-300 dark:text-zinc-700">&middot;</span>
                                <span class="text-amber-600 dark:text-amber-400">game changes</span>
                            </template>
                            <template v-if="rule.min_viewers">
                                <span class="text-gray-300 dark:text-zinc-700">&middot;</span>
                                <span>&ge;{{ rule.min_viewers }} viewers</span>
                            </template>
                            <template v-if="rule.language">
                                <span class="text-gray-300 dark:text-zinc-700">&middot;</span>
                                <span>{{ rule.language }}</span>
                            </template>
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5 mt-1.5">
                            <template v-for="c in channels" :key="c.flag">
                                <span v-if="rule[c.flag]" :class="['text-[10px] font-medium', c.text]">{{ c.label }}</span>
                            </template>
                            <span v-if="rule.latest_tracking" class="text-[10px] text-gray-400 dark:text-zinc-600 ml-1">Last: {{ formatTime(rule.latest_tracking.triggered_at) }}</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-1 shrink-0">
                        <button @click="editingId === rule.id ? editingId = null : startEdit(rule)"
                            class="p-2 text-gray-400 hover:text-purple-600 dark:hover:text-purple-400 rounded-lg hover:bg-gray-50 dark:hover:bg-zinc-800 transition-colors">
                            <Pencil class="w-4 h-4" />
                        </button>
                        <button @click="toggleActive(rule)" :class="['p-2 rounded-lg transition-colors', rule.is_active ? 'text-green-500 hover:bg-green-50 dark:hover:bg-green-500/10' : 'text-gray-400 hover:bg-gray-50 dark:hover:bg-zinc-800']">
                            <CheckCircle v-if="rule.is_active" class="w-4 h-4" />
                            <XCircle v-else class="w-4 h-4" />
                        </button>
                        <button @click="deletingRule = rule" class="p-2 text-gray-400 hover:text-red-600 dark:hover:text-red-400 rounded-lg hover:bg-gray-50 dark:hover:bg-zinc-800 transition-colors">
                            <Trash2 class="w-4 h-4" />
                        </button>
                    </div>
                </div>

                <!-- ── Edit Panel (sentence style) ─────────────────── -->
                <div v-if="editingId === rule.id" class="p-5 bg-gray-50 dark:bg-zinc-950/50 border-t border-gray-100 dark:border-zinc-800">
                    <AlertRuleForm :form="editForm" variant="edit" :categories="categories" :types="destinationTypes" :ready-types="readyTypes" :template-fields="templateFields" :template-default-message="templateDefaultMessage"
                        @submit="saveEdit(rule)" @cancel="editingId = null" />
                </div>
            </div>
        </TransitionGroup>

        <ConfirmModal :show="!!deletingRule" :title="`Delete &quot;${deletingRule?.name}&quot;?`" message="This alert rule and its tracking history will be permanently deleted." confirm-label="Delete" @confirm="deleteRule" @cancel="deletingRule = null" />
    </AppLayout>
</template>
