<script setup lang="ts">
import { computed, ref } from 'vue';
import { ChevronDown, X, AlertTriangle } from 'lucide-vue-next';
import AlertTemplateBuilder from '@/components/AlertTemplateBuilder.vue';
import { useChipInput } from '@/composables/useChipInput';
import { destinationChannels, distinctTags } from '@/utils';
import type { AlertRuleFields, Category, DestinationType, DestinationTypeInfo } from '@/types';

/**
 * The "Notify me via … when … in …" form of an alert rule, for creating
 * (`variant="create"`) and for editing (`variant="edit"`). It fills in the
 * given Inertia form and emits `submit` once the form is ready to send.
 */
const props = defineProps<{
    form: AlertRuleFields & { processing: boolean };
    variant: 'create' | 'edit';
    categories: Category[];
    /** The server's destination types (DestinationType::forBrowser()). */
    types: DestinationTypeInfo[];
    /** Types the account has a working destination for. */
    readyTypes: DestinationType[];
    /** Template field labels (AlertTemplateValidator::FIELD_LABELS). */
    templateFields: Record<string, string>;
}>();

const emit = defineEmits<{ submit: []; cancel: [] }>();

const isEdit = props.variant === 'edit';
// The edit form sits on a grey panel, the new-alert form on a white card.
const fieldBg = isEdit ? 'bg-white' : 'bg-gray-50';
const offPill = isEdit ? 'bg-gray-100' : 'bg-gray-50';

const channels = computed(() => destinationChannels(props.types));
const availableTags = computed(() => distinctTags(props.categories));

// The modes start from what the rule already has; an empty form means anyone, any category.
const channelMode = ref<'anyone' | 'specific'>(props.form.streamer_login ? 'specific' : 'anyone');
const categoryMode = ref<'any' | 'specific' | 'tagged'>(
    props.form.category_tags.length ? 'tagged' : (props.form.category_ids.length || props.form.category_id) ? 'specific' : 'any',
);
const hasFilters = computed(() => !!(props.form.min_viewers || props.form.min_avg_viewers || props.form.language || props.form.keywords.length));
const showFilters = ref(isEdit && hasFilters.value);

const { input: kwInput, add: addKw, remove: removeKw } = useChipInput(() => props.form.keywords, v => (props.form.keywords = v));

// A name for a new rule that is left unnamed.
const autoName = computed(() => {
    const who = channelMode.value === 'specific' && props.form.streamer_login ? props.form.streamer_login : 'Anyone';
    let what = 'any category';
    if (categoryMode.value === 'specific' && props.form.category_ids.length) {
        what = props.form.category_ids.map(id => props.categories.find(c => c.id === id)?.name).filter(Boolean).join(', ') || 'categories';
    } else if (categoryMode.value === 'tagged' && props.form.category_tags.length) {
        what = props.form.category_tags.join(', ');
    }
    return `${who} — ${what}`;
});

const warnings = computed(() => channels.value
    .filter(c => props.form[c.flag] && !props.readyTypes.includes(c.value))
    .map(c => c.value === 'email'
        ? 'No enabled Email destination, or no mail server yet — Settings → Notifications'
        : `No enabled ${c.label} destination — Settings → Notifications`));

function submit() {
    const f = props.form;
    if (!isEdit && !f.name) f.name = autoName.value;
    if (channelMode.value === 'anyone') f.streamer_login = '';
    if (categoryMode.value !== 'specific') { f.category_id = null; f.category_ids = []; }
    if (categoryMode.value !== 'tagged') f.category_tags = [];
    emit('submit');
}

const select = 'px-3 py-1.5 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white border border-gray-200 dark:border-zinc-700 outline-none focus:ring-2 focus:ring-purple-500 font-medium';
const field = `px-3 py-1.5 text-sm rounded-lg ${fieldBg} dark:bg-zinc-800 text-gray-900 dark:text-white border border-gray-200 dark:border-zinc-700 outline-none`;
const choice = (on: boolean) => ['inline-flex px-2.5 py-1 rounded-full text-xs font-medium cursor-pointer transition-all border',
    on ? 'bg-purple-50 dark:bg-purple-500/10 text-purple-700 dark:text-purple-300 border-purple-200 dark:border-purple-500/30' : 'bg-gray-50 dark:bg-zinc-800 text-gray-400 dark:text-zinc-600 border-transparent'];
</script>

<template>
    <div :class="isEdit ? 'space-y-4' : 'space-y-5'">
        <!-- Notify me via -->
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-sm font-semibold text-gray-900 dark:text-white">Notify me via</span>
            <label v-for="c in channels" :key="c.flag" :class="['inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium cursor-pointer transition-all border',
                form[c.flag] ? c.on : `${offPill} dark:bg-zinc-800 text-gray-400 dark:text-zinc-600 border-transparent`]">
                <input type="checkbox" v-model="form[c.flag]" class="sr-only" />
                {{ c.label }}
            </label>
        </div>

        <!-- when … -->
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-sm font-semibold text-gray-900 dark:text-white">when</span>
            <select v-model="channelMode" :class="select">
                <option value="anyone">anyone</option>
                <option value="specific">specific channel</option>
            </select>
            <input v-if="channelMode === 'specific'" v-model="form.streamer_login" type="text" placeholder="channel login"
                class="px-3 py-1.5 text-sm rounded-lg bg-gray-50 dark:bg-zinc-800 text-gray-900 dark:text-white border border-gray-200 dark:border-zinc-700 outline-none focus:ring-2 focus:ring-purple-500 w-40" />
        </div>

        <!-- triggers -->
        <div class="flex flex-col gap-2 pl-0.5">
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" v-model="form.notify_on_stream_start" :disabled="!form.notify_on_category_change" class="rounded text-purple-600 focus:ring-purple-500 bg-white dark:bg-zinc-800" />
                <span class="text-sm text-gray-700 dark:text-zinc-300">goes live</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" v-model="form.notify_on_category_change" :disabled="!form.notify_on_stream_start" class="rounded text-purple-600 focus:ring-purple-500 bg-white dark:bg-zinc-800" />
                <span class="text-sm text-gray-700 dark:text-zinc-300">changes game / category</span>
            </label>
        </div>

        <!-- in … -->
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-sm font-semibold text-gray-900 dark:text-white">in</span>
            <select v-model="categoryMode" :class="select">
                <option value="any">any tracked category</option>
                <option value="specific">specific categories</option>
                <option v-if="availableTags.length" value="tagged">tagged categories</option>
            </select>
            <div v-if="categoryMode === 'specific'" class="flex flex-wrap gap-1">
                <label v-for="cat in categories" :key="cat.id" :class="choice(form.category_ids.includes(cat.id))">
                    <input type="checkbox" :value="cat.id" v-model="form.category_ids" class="sr-only" />
                    {{ cat.name }}
                </label>
            </div>
            <div v-if="categoryMode === 'tagged'" class="flex flex-wrap gap-1">
                <label v-for="tag in availableTags" :key="tag" :class="choice(form.category_tags.includes(tag))">
                    <input type="checkbox" :value="tag" v-model="form.category_tags" class="sr-only" />
                    {{ tag }}
                </label>
            </div>
        </div>

        <!-- Filters -->
        <button type="button" @click="showFilters = !showFilters" class="flex items-center gap-1.5 text-xs font-medium text-gray-400 dark:text-zinc-500 hover:text-gray-600 dark:hover:text-zinc-300 transition-colors">
            <ChevronDown class="w-3.5 h-3.5 transition-transform" :class="{ 'rotate-180': showFilters }" />
            {{ showFilters ? 'Hide filters' : 'Add filters' }}
            <span v-if="hasFilters && !showFilters" class="w-1.5 h-1.5 rounded-full bg-purple-500"></span>
        </button>

        <div v-if="showFilters" class="space-y-3 pl-0.5">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-sm text-gray-600 dark:text-zinc-400">with at least</span>
                <input v-model.number="form.min_viewers" type="number" min="0" placeholder="0" :class="[field, 'w-20 text-center']" />
                <span class="text-sm text-gray-600 dark:text-zinc-400">viewers</span>
                <span class="text-sm text-gray-600 dark:text-zinc-400">and avg</span>
                <input v-model.number="form.min_avg_viewers" type="number" min="0" placeholder="0" :class="[field, 'w-20 text-center']" />
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-sm text-gray-600 dark:text-zinc-400">streaming in</span>
                <input v-model="form.language" type="text" placeholder="any" autocomplete="off" :class="[field, 'w-16 text-center placeholder:text-gray-300 dark:placeholder:text-zinc-600']" />
                <span class="text-sm text-gray-600 dark:text-zinc-400">language</span>
            </div>
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm text-gray-600 dark:text-zinc-400">title contains</span>
                    <div class="flex flex-wrap gap-1" v-if="form.keywords.length">
                        <span v-for="kw in form.keywords" :key="kw" class="inline-flex items-center gap-1 px-2 py-0.5 text-xs bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-300 rounded-full">
                            {{ kw }} <button type="button" @click="removeKw(kw)" class="hover:text-red-500"><X class="w-3 h-3" /></button>
                        </span>
                    </div>
                </div>
                <div class="flex gap-2 mt-1.5">
                    <input v-model="kwInput" @keydown.enter.prevent="addKw" placeholder="add keyword" :class="[field, 'flex-1']" />
                    <button type="button" @click="addKw" class="px-3 py-1.5 text-xs font-medium bg-gray-100 dark:bg-zinc-700 text-gray-600 dark:text-zinc-300 rounded-lg hover:bg-gray-200 dark:hover:bg-zinc-600">Add</button>
                </div>
            </div>
        </div>

        <!-- Name -->
        <div :class="['flex flex-wrap items-center gap-2 border-t dark:border-zinc-800', isEdit ? 'pt-3 border-gray-200' : 'pt-2 border-gray-100']">
            <span class="text-xs text-gray-400 dark:text-zinc-500">Alert name <span v-if="!isEdit" class="text-gray-300 dark:text-zinc-600">optional</span></span>
            <input v-model="form.name" type="text" :placeholder="isEdit ? undefined : autoName" :class="[field, 'flex-1 placeholder:text-gray-400 dark:placeholder:text-zinc-500']" />
        </div>

        <AlertTemplateBuilder v-model="form.notification_template" :fields="templateFields" />

        <!-- Warnings -->
        <div v-if="warnings.length" class="space-y-1.5">
            <div v-for="w in warnings" :key="w" class="flex items-center gap-2 px-3 py-2 text-xs font-medium bg-red-50 dark:bg-red-500/10 text-red-700 dark:text-red-400 rounded-lg">
                <AlertTriangle class="w-3.5 h-3.5 shrink-0" /> {{ w }}
            </div>
        </div>

        <div v-if="isEdit" class="flex gap-2">
            <button type="button" @click="submit" :disabled="form.processing || !form.name"
                class="px-4 py-2 text-sm font-medium bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50 transition-colors">Save</button>
            <button type="button" @click="emit('cancel')" class="px-4 py-2 text-sm font-medium text-gray-600 dark:text-zinc-400 hover:bg-gray-100 dark:hover:bg-zinc-800 rounded-lg transition-colors">Cancel</button>
        </div>
        <button v-else type="button" @click="submit" :disabled="form.processing"
            class="w-full py-2.5 text-sm font-semibold bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50 transition-colors">
            Create Alert
        </button>
    </div>
</template>
