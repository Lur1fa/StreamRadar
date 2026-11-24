{{-- All variables are plain strings; Blade's {{ }} HTML-escapes every one of them. --}}
@php($brand = \App\Support\Brand::COLOR)
<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 600px; margin: 0 auto; background: #f9fafb; padding: 24px;">
    <div style="text-align: center; margin-bottom: 24px;">
        <div style="display: inline-block; background: linear-gradient(135deg, {{ $brand }}, #6366f1); padding: 10px 16px; border-radius: 12px;">
            <span style="color: white; font-size: 18px; font-weight: bold;">StreamRadar</span>
        </div>
    </div>

@if (count($entries) > 1)
    <p style="text-align: center; color: #6b7280; font-size: 14px; margin-bottom: 20px;">{{ count($entries) }} streams matched your alert rules</p>
@endif

@foreach ($entries as $entry)
    <div style="background: white; border-radius: 12px; overflow: hidden; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.08);">
        <div style="background: linear-gradient(135deg, {{ $brand }}, #6366f1); padding: 12px 16px;">
            <table cellpadding="0" cellspacing="0" border="0"><tr>
                <td>
                    @if ($entry['avatar'])
                        <img src="{{ $entry['avatar'] }}" width="48" height="48" style="border-radius: 50%; margin-right: 12px; vertical-align: middle;" alt="{{ $entry['user_name'] }}" />
                    @else
                        <div style="width: 48px; height: 48px; border-radius: 50%; background: rgba(255,255,255,0.2); margin-right: 12px; display: inline-block; vertical-align: middle;"></div>
                    @endif
                </td>
                <td>
                    <div style="color: white; font-weight: 700; font-size: 16px;">{{ $entry['user_name'] }}</div>
                    <div style="color: rgba(255,255,255,0.8); font-size: 12px;">is live</div>
                </td>
            </tr></table>
        </div>
        <div style="padding: 16px;">
            <p style="margin: 0 0 12px; font-size: 15px; font-weight: 600; color: #111827;">{{ $entry['title'] }}</p>
            @if ($entry['image'])
                <a href="{{ $entry['url'] }}" style="display: block; margin-bottom: 12px;"><img src="{{ $entry['image'] }}" width="{{ $entry['image_width'] }}" style="border-radius: 8px; display: block;" alt="" /></a>
            @endif
            <div style="white-space: pre-wrap; margin: 0 0 12px; color: #374151; font-size: 14px;">{{ $entry['message'] }}</div>
            <table cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 12px;">
                <tr>
                    <td style="padding-right: 16px;">
                        <span style="color: {{ $brand }}; font-weight: 600; font-size: 13px;">
                            @if ($entry['category_box_art'])
                                <img src="{{ $entry['category_box_art'] }}" width="16" height="21" style="vertical-align: middle; margin-right: 4px; border-radius: 2px;" alt="" />
                            @endif
                            {{ $entry['category'] }}
                        </span>
                    </td>
                    <td style="padding-right: 16px;">
                        <span style="color: #ef4444; font-weight: 600; font-size: 13px;">👁 {{ $entry['viewers'] }}</span>
                    </td>
                    <td>
                        <span style="color: #6b7280; font-size: 13px;">🌐 {{ $entry['language'] }}</span>
                    </td>
                </tr>
            </table>
            <a href="{{ $entry['url'] }}" style="display: inline-block; padding: 10px 24px; background: {{ $brand }}; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 14px;">Watch on Twitch →</a>
            <p style="margin: 12px 0 0; color: #9ca3af; font-size: 11px;">Alert: {{ $entry['rules'] }}</p>
        </div>
    </div>
@endforeach

    <p style="text-align: center; color: #9ca3af; font-size: 11px; margin-top: 8px;">
        Sent by StreamRadar · Manage alerts in Settings
    </p>
</div>
