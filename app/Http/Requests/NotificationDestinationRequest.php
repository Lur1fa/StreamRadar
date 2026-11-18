<?php

namespace App\Http\Requests;

use App\Models\NotificationDestination;
use App\Rules\SafeWebhookUrl;
use App\Services\Http\SafeHttpClient;
use App\Services\Notifications\DestinationType;
use App\Support\Text;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator as ValidatorFactory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creating or editing one of the signed-in account's alert destinations.
 *
 * Payload: `name`, `type`, `enabled`, `config` (plain settings of the type),
 * `secrets` (new secret values) and `clear_secrets` (secrets to remove).
 * An empty secret keeps the stored value, as long as the type is unchanged.
 * A webhook's header and query names are in `config.headers` /
 * `config.query`, their values at the same position in `secrets.headers` /
 * `secrets.query`.
 */
class NotificationDestinationRequest extends FormRequest
{
    /** No control characters (CR/LF would split a header). */
    private const PRINTABLE = Text::PRINTABLE_RULE;

    /** One word of visible ASCII, as tokens are. */
    private const TOKEN = 'regex:/^[\x21-\x7E]+$/';

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function type(): ?DestinationType
    {
        return DestinationType::tryFrom((string) $this->input('type'));
    }

    /** The destination being edited; route binding already limits it to the account. */
    public function destination(): ?NotificationDestination
    {
        $destination = $this->route('destination');

        return $destination instanceof NotificationDestination ? $destination : null;
    }

    protected function prepareForValidation(): void
    {
        $trim = fn (mixed $value) => is_string($value) ? trim($value) : $value;
        $clean = function (mixed $section) use ($trim): array {
            if (! is_array($section)) {
                return [];
            }

            return array_map(fn ($value) => is_array($value) ? array_map($trim, array_values($value)) : $trim($value), $section);
        };

        $this->merge([
            'name' => $trim($this->input('name')),
            'config' => $clean($this->input('config')),
            'secrets' => $clean($this->input('secrets')),
            'clear_secrets' => array_values(array_filter((array) $this->input('clear_secrets', []), 'is_string')),
        ]);
    }

    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:100', self::PRINTABLE],
            'type' => ['required', Rule::enum(DestinationType::class)],
            'enabled' => ['required', 'boolean'],
            'config' => ['array'],
            'secrets' => ['array'],
            'clear_secrets' => ['array'],
            'clear_secrets.*' => ['string'],
        ];

        $type = $this->type();

        return $type === null ? $rules : $rules + self::configRules($type) + $this->secretRules($type);
    }

    /**
     * Rules for the plain settings of a type. Also used for destinations in
     * a backup, with an empty prefix.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function configRules(DestinationType $type, string $prefix = 'config.'): array
    {
        $text = fn (int $max) => ['nullable', 'string', "max:{$max}", self::PRINTABLE];

        $rules = match ($type) {
            DestinationType::Ntfy => [
                'server_url' => ['required', 'string', 'max:2048', new SafeWebhookUrl(credentialsHint: 'Enter the access token, or the user name and password, in their own fields.')],
                'topic' => ['required', 'string', 'regex:'.DestinationType::NTFY_TOPIC],
                'username' => [...$text(255), 'not_regex:/:/'],
                'title_prefix' => $text(64),
            ],
            DestinationType::Webhook => [
                'method' => ['required', Rule::in(SafeHttpClient::METHODS)],
                'auth' => ['required', 'in:none,basic,bearer'],
                'auth_username' => ["required_if:{$prefix}auth,basic", ...$text(255), 'not_regex:/:/'],
                'headers' => ['array', 'max:20'],
                'headers.*' => ['required', 'string', 'max:64', "regex:/^[A-Za-z0-9!#$%&'*+.^_`|~-]+$/", 'distinct:ignore_case', self::notReservedHeader(...)],
                'query' => ['array', 'max:20'],
                'query.*' => ['required', 'string', 'max:128', 'distinct', self::PRINTABLE],
            ],
            DestinationType::Email => [
                'to' => ['required', 'string', 'max:1000', self::emailList(...)],
                'subject_prefix' => $text(64),
            ],
            DestinationType::Telegram => [
                'chat_id' => ['required', 'string', 'max:64', 'regex:'.DestinationType::TELEGRAM_CHAT_ID],
            ],
            DestinationType::Discord => [],
        };

        $prefixed = [];
        foreach ($rules as $key => $rule) {
            $prefixed[$prefix.$key] = $rule;
        }

        return $prefixed;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function secretRules(DestinationType $type): array
    {
        // Required unless a value is already stored and stays.
        $required = fn (string $key, ?Closure $when = null) => Rule::requiredIf(fn () => ($when === null || $when()) && ! $this->keepsSecret($key));
        $auth = fn (string $method) => fn () => $this->input('config.auth') === $method;

        return match ($type) {
            DestinationType::Ntfy => [
                'secrets.access_token' => ['nullable', 'string', 'max:255', self::TOKEN],
                'secrets.password' => [$required('password', fn () => filled($this->input('config.username'))), 'nullable', 'string', 'max:255'],
            ],
            DestinationType::Webhook => [
                'secrets.url' => [$required('url'), 'nullable', 'string', 'max:2048', new SafeWebhookUrl(credentialsHint: 'Choose Basic under Authentication instead.')],
                'secrets.auth_password' => [$required('auth_password', $auth('basic')), 'nullable', 'string', 'max:255'],
                'secrets.auth_token' => [$required('auth_token', $auth('bearer')), 'nullable', 'string', 'max:1024', self::TOKEN],
                'secrets.headers' => ['array', 'max:20'],
                'secrets.headers.*' => ['nullable', 'string', 'max:2048', self::PRINTABLE],
                'secrets.query' => ['array', 'max:20'],
                'secrets.query.*' => ['nullable', 'string', 'max:2048', self::PRINTABLE],
            ],
            DestinationType::Email => [],
            DestinationType::Telegram => [
                'secrets.bot_token' => [$required('bot_token'), 'nullable', 'string', 'max:255', 'regex:'.DestinationType::TELEGRAM_BOT_TOKEN],
            ],
            DestinationType::Discord => [
                'secrets.webhook_url' => [$required('webhook_url'), 'nullable', 'string', 'max:2048', SafeWebhookUrl::discord()],
            ],
        };
    }

    /**
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->type() !== DestinationType::Webhook) {
                return;
            }

            $stored = $this->storedSecrets();

            // Every header and query parameter needs a value: a new one, or the stored one.
            foreach (['headers', 'query'] as $list) {
                $values = (array) $this->input("secrets.{$list}", []);
                foreach ((array) $this->input("config.{$list}", []) as $i => $name) {
                    $value = $values[$i] ?? null;
                    $kept = is_string($name) && isset($stored[$list][$name]);
                    if ((! is_string($value) || $value === '') && ! $kept) {
                        $validator->errors()->add("secrets.{$list}.{$i}", 'Enter a value.');
                    }
                }
            }

            if ($this->input('config.auth') !== 'none') {
                foreach ((array) $this->input('config.headers', []) as $i => $name) {
                    if (is_string($name) && strcasecmp($name, 'Authorization') === 0) {
                        $validator->errors()->add("config.headers.{$i}", 'Authentication already sets this header. Choose None under Authentication to set it yourself.');
                    }
                }
            }
        }];
    }

    public function attributes(): array
    {
        return [
            'config.server_url' => 'server URL',
            'config.topic' => 'topic',
            'config.username' => 'user name',
            'config.title_prefix' => 'title prefix',
            'config.method' => 'method',
            'config.auth' => 'authentication',
            'config.auth_username' => 'user name',
            'config.headers.*' => 'header name',
            'config.query.*' => 'parameter name',
            'config.to' => 'recipients',
            'config.subject_prefix' => 'subject prefix',
            'config.chat_id' => 'chat ID',
            'secrets.access_token' => 'access token',
            'secrets.password' => 'password',
            'secrets.url' => 'URL',
            'secrets.auth_password' => 'password',
            'secrets.auth_token' => 'token',
            'secrets.headers.*' => 'header value',
            'secrets.query.*' => 'parameter value',
            'secrets.bot_token' => 'bot token',
            'secrets.webhook_url' => 'webhook URL',
        ];
    }

    public function messages(): array
    {
        return [
            'config.topic.regex' => 'A topic may only contain letters, digits, - and _ (at most 64 characters).',
            'config.username.not_regex' => 'A user name cannot contain ":".',
            'config.auth_username.not_regex' => 'A user name cannot contain ":".',
            'config.auth_username.required_if' => 'Enter the user name.',
            'config.chat_id.regex' => 'The chat ID is a number, such as 123456789 or -1001234567890.',
            'config.headers.*.regex' => 'This is not a valid header name.',
            'config.headers.*.distinct' => 'This header is listed twice.',
            'config.query.*.distinct' => 'This parameter is listed twice.',
            'secrets.access_token.regex' => 'The access token cannot contain spaces.',
            'secrets.auth_token.regex' => 'The token cannot contain spaces.',
            'secrets.bot_token.regex' => 'This does not look like a bot token from @BotFather (123456789:ABC…).',
            'secrets.password.required' => 'Enter the password for this user name.',
            'secrets.auth_password.required' => 'Enter the password.',
            'secrets.auth_token.required' => 'Enter the token.',
        ];
    }

    /**
     * The validated destination: the plain settings of its type, and the
     * secrets merged with the stored ones.
     *
     * @return array{name: string, type: DestinationType, enabled: bool, config: array<string, mixed>, secrets: array<string, mixed>}
     */
    public function destinationAttributes(): array
    {
        $type = $this->type();
        $validated = $this->validated();
        $input = (array) ($validated['secrets'] ?? []);
        $clear = (array) ($validated['clear_secrets'] ?? []);
        $stored = $this->storedSecrets();

        $config = $type->normalizeConfig((array) ($validated['config'] ?? []));

        $secrets = [];
        foreach ($type->secretKeys() as $key) {
            $new = $input[$key] ?? null;
            if (is_string($new) && $new !== '') {
                $secrets[$key] = $new;
            } elseif (! in_array($key, $clear, true) && isset($stored[$key])) {
                $secrets[$key] = $stored[$key];
            }
        }

        if ($type === DestinationType::Webhook) {
            // Keep only the credentials of the chosen authentication.
            if ($config['auth'] !== 'basic') {
                $config['auth_username'] = '';
                unset($secrets['auth_password']);
            }
            if ($config['auth'] !== 'bearer') {
                unset($secrets['auth_token']);
            }

            foreach (['headers', 'query'] as $list) {
                $values = (array) ($input[$list] ?? []);
                $map = [];
                foreach ($config[$list] as $i => $name) {
                    $new = $values[$i] ?? null;
                    $map[$name] = is_string($new) && $new !== '' ? $new : (string) ($stored[$list][$name] ?? '');
                }
                $secrets[$list] = $map;
            }
        }

        if ($type === DestinationType::Ntfy && $config['username'] === '') {
            unset($secrets['password']);
        }

        return [
            'name' => (string) $validated['name'],
            'type' => $type,
            'enabled' => (bool) $validated['enabled'],
            'config' => $config,
            'secrets' => $secrets,
        ];
    }

    /** Whether a stored secret stays: same type, stored, and not being removed. */
    private function keepsSecret(string $key): bool
    {
        return isset($this->storedSecrets()[$key])
            && ! in_array($key, (array) $this->input('clear_secrets', []), true);
    }

    /**
     * Secrets of the destination being edited, if its type is unchanged.
     *
     * @return array<string, mixed>
     */
    private function storedSecrets(): array
    {
        $destination = $this->destination();

        return $destination !== null && $destination->type === $this->type() ? $destination->secrets : [];
    }

    private static function notReservedHeader(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && NotificationDestination::isReservedHeader($value)) {
            $fail('StreamRadar sets this header itself.');
        }
    }

    private static function emailList(string $attribute, mixed $value, Closure $fail): void
    {
        $addresses = NotificationDestination::splitAddresses((string) $value);

        if ($addresses === []) {
            $fail('Enter at least one e-mail address.');

            return;
        }
        if (count($addresses) > 10) {
            $fail('Enter at most 10 e-mail addresses.');

            return;
        }

        foreach ($addresses as $address) {
            if (ValidatorFactory::make(['address' => $address], ['address' => 'email:rfc|max:255'])->fails()) {
                $fail("\"{$address}\" is not a valid e-mail address.");

                return;
            }
        }
    }
}
