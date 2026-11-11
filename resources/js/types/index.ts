export interface Category {
    id: number;
    twitch_id: string;
    name: string;
    box_art_url: string | null;
    is_active: boolean;
    notifications_enabled: boolean;
    use_global_filters: boolean;
    filter_source: string;
    min_viewers: number | null;
    min_avg_viewers: number | null;
    languages: string[] | null;
    keywords: string[] | null;
    tags: string[] | null;
    streams_count?: number;
    created_at: string;
    updated_at: string;
}

export interface TagFilter {
    id: number;
    tag: string;
    min_viewers: number | null;
    min_avg_viewers: number | null;
    languages: string[] | null;
    keywords: string[] | null;
}

export interface Stream {
    id: number;
    twitch_id: string;
    user_id: string;
    user_login: string;
    user_name: string;
    category_id: number | null;
    category?: Category;
    game_name: string | null;
    game_box_art_url: string | null;
    title: string;
    viewer_count: number;
    avg_viewers: number | null;
    language: string | null;
    thumbnail_url: string | null;
    profile_image_url: string | null;
    started_at: string | null;
    tags: string[] | null;
    is_mature: boolean;
    synced_at: string | null;
}

export interface AlertRule {
    id: number;
    name: string;
    streamer_login: string | null;
    category_id: number | null;
    category_ids: number[] | null;
    category_tags: string[] | null;
    category?: Category;
    match_mode: 'first_time' | 'always';
    min_viewers: number | null;
    min_avg_viewers: number | null;
    language: string | null;
    keywords: string[] | null;
    notify_email: boolean;
    notify_discord: boolean;
    notify_telegram: boolean;
    notify_webhook: boolean;
    notify_on_category_change: boolean;
    notify_on_stream_start: boolean;
    notification_template: AlertNotificationTemplate | null;
    is_active: boolean;
    created_at: string;
}

export type AlertTemplateBlock =
    | { type: 'field'; field: AlertTemplateField }
    | { type: 'text'; value: string };

export type AlertTemplateField = 'streamer_name' | 'streamer_login' | 'title' | 'category' | 'viewers' | 'language' | 'url' | 'alert_names' | 'tags' | 'started_at';

export interface AlertNotificationTemplate {
    version: 1;
    blocks: AlertTemplateBlock[];
}

export interface IgnoredStreamer {
    id: number;
    twitch_user_id: string;
    user_login: string;
    user_name: string;
    profile_image_url: string | null;
    reason: string | null;
    created_at: string;
}

export interface HistoryEvent {
    id: number;
    type: string;
    stream_twitch_id: string | null;
    streamer_login: string | null;
    streamer_name: string | null;
    category_name: string | null;
    title: string | null;
    viewer_count: number | null;
    profile_image_url: string | null;
    metadata: Record<string, any> | null;
    created_at: string;
}

export interface PendingAlert {
    rule_name: string;
    streamer: string;
    streamer_login: string;
    title: string;
    viewer_count: number;
    category: string | null;
    thumbnail_url: string | null;
    profile_image_url: string | null;
    twitch_url: string;
    notify_browser: boolean;
    notify_email: boolean;
    triggered_at: string;
}

export interface BlacklistRule {
    id: number;
    type: 'channel' | 'keyword' | 'tag';
    value: string;
    twitch_user_id: string | null;
    profile_image_url: string | null;
    created_at: string;
}

export interface Stats {
    categories_count: number;
    streams_count: number;
    alerts_count: number;
    blacklist_count: number;
}

export interface AppSettings {
    theme: string;
    last_sync_at: string | null;
    sync_frequency_minutes: number;
}

export interface Flash {
    success: string | null;
    error: string | null;
}

export interface PageProps {
    stats: Stats;
    appSettings: AppSettings;
    flash: Flash;
    pendingAlerts: PendingAlert[];
}

export interface PaginatedData<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

export interface GroupedStreams {
    category: Category;
    streams: Stream[];
}

export type StreamFilters = {
    sort: string;
    group: string;
    category: number | null;
    search: string | null;
    density: string;
}
