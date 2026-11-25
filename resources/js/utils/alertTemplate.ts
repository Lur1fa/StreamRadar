import type {
    AlertNotificationTemplate,
    AlertTemplateBlock,
    AlertTemplateField,
    AlertTemplateFields,
    LegacyAlertNotificationTemplate,
} from '@/types';

/**
 * Helpers for notification templates: the token ⇄ text syntax of the editor
 * (`{Title} is live`), previews with the sample stream, and normalizing what
 * the server stores. The server (AlertTemplateValidator) has the final say.
 */

export const MAX_ACTIONS = 3;

/** The name of a field in text form: streamer_name → StreamerName. */
export function tokenName(field: string): string {
    return field.split('_').map(part => part.charAt(0).toUpperCase() + part.slice(1)).join('');
}

/** Adjacent text merged, empty text dropped. */
export function mergeBlocks(blocks: AlertTemplateBlock[]): AlertTemplateBlock[] {
    const result: AlertTemplateBlock[] = [];
    for (const block of blocks) {
        if (block.type === 'text') {
            if (!block.value) continue;
            const last = result[result.length - 1];
            if (last?.type === 'text') result[result.length - 1] = { type: 'text', value: last.value + block.value };
            else result.push({ type: 'text', value: block.value });
        } else {
            result.push({ type: 'field', field: block.field });
        }
    }
    return result;
}

export function sameBlocks(a: AlertTemplateBlock[], b: AlertTemplateBlock[]): boolean {
    return JSON.stringify(mergeBlocks(a)) === JSON.stringify(mergeBlocks(b));
}

export function blocksToText(blocks: AlertTemplateBlock[]): string {
    return blocks.map(b => (b.type === 'text' ? b.value : `{${tokenName(b.field)}}`)).join('');
}

/** The unknown `{Names}` in a text, so the editor can point them out. */
export function unknownTokens(text: string, fields: AlertTemplateFields): string[] {
    const found = new Set<string>();
    for (const match of text.matchAll(/\{([A-Za-z_]+)\}/g)) {
        if (!findField(match[1], fields)) found.add(match[0]);
    }
    return [...found];
}

/** `{Title}`, `{title}` and `{TITLE}` all mean the title field; unknown names stay text. */
export function textToBlocks(text: string, fields: AlertTemplateFields): AlertTemplateBlock[] {
    const blocks: AlertTemplateBlock[] = [];
    let rest = 0;
    for (const match of text.matchAll(/\{([A-Za-z_]+)\}/g)) {
        const field = findField(match[1], fields);
        if (!field) continue;
        blocks.push({ type: 'text', value: text.slice(rest, match.index) });
        blocks.push({ type: 'field', field });
        rest = match.index! + match[0].length;
    }
    blocks.push({ type: 'text', value: text.slice(rest) });
    return mergeBlocks(blocks);
}

function findField(name: string, fields: AlertTemplateFields): AlertTemplateField | undefined {
    const wanted = name.replace(/_/g, '').toLowerCase();
    return (Object.keys(fields) as AlertTemplateField[]).find(f => f.replace(/_/g, '').toLowerCase() === wanted);
}

/** Like PHP's rawurlencode(), which the server uses for field values in links. */
function rawUrlEncode(value: string): string {
    return encodeURIComponent(value).replace(/[!'()*]/g, c => '%' + c.charCodeAt(0).toString(16).toUpperCase());
}

/** Blocks filled in with the sample stream. In a link, field values are URL-encoded (except the Twitch URL). */
export function renderBlocks(blocks: AlertTemplateBlock[], fields: AlertTemplateFields, link = false): string {
    return blocks.map(b => {
        if (b.type === 'text') return b.value;
        const value = fields[b.field]?.sample ?? '';
        return link && b.field !== 'url' ? rawUrlEncode(value) : value;
    }).join('');
}

/** The same check as AlertTemplateValidator::isLink(). */
export function isLink(value: string): boolean {
    if (!/^https?:\/\//i.test(value) || /[\s"<>\\]/.test(value)) return false;
    try {
        return new URL(value).hostname !== '';
    } catch {
        return false;
    }
}

/** A template as the editor works on it: every option present, the message as blocks. */
export interface TemplateState {
    message: AlertTemplateBlock[];
    click: AlertNotificationTemplate['click'];
    click_url: AlertTemplateBlock[];
    attachment: AlertNotificationTemplate['attachment'];
    actions: { label: AlertTemplateBlock[]; url: AlertTemplateBlock[] }[];
}

const copy = (blocks: AlertTemplateBlock[] | null | undefined): AlertTemplateBlock[] => (blocks ?? []).map(b => ({ ...b }));

export function toState(
    template: AlertNotificationTemplate | LegacyAlertNotificationTemplate | null | undefined,
    defaultMessage: AlertTemplateBlock[],
): TemplateState {
    if (template?.version === 1) {
        return { message: template.blocks.length ? copy(template.blocks) : copy(defaultMessage), click: 'stream', click_url: [], attachment: 'preview', actions: [] };
    }
    return {
        message: template?.message?.length ? copy(template.message) : copy(defaultMessage),
        click: template?.click ?? 'stream',
        click_url: copy(template?.click_url),
        attachment: template?.attachment ?? 'preview',
        actions: (template?.actions ?? []).map(a => ({ label: copy(a.label), url: copy(a.url) })),
    };
}

/** What the server stores: null when nothing differs from the defaults. */
export function fromState(state: TemplateState, defaultMessage: AlertTemplateBlock[]): AlertNotificationTemplate | null {
    const message = mergeBlocks(state.message);
    const custom = message.length > 0 && !sameBlocks(message, defaultMessage);
    const template: AlertNotificationTemplate = {
        version: 2,
        message: custom ? message : null,
        click: state.click,
        click_url: state.click === 'custom' ? mergeBlocks(state.click_url) : null,
        attachment: state.attachment,
        actions: state.actions.map(a => ({ label: mergeBlocks(a.label), url: mergeBlocks(a.url) })),
    };
    const isDefault = !template.message && template.click === 'stream' && template.attachment === 'preview' && !template.actions.length;
    return isDefault ? null : template;
}
