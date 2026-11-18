<?php

namespace App\Services\Auth;

use App\Models\Setting;
use App\Models\User;
use App\Support\Redactor;
use App\Support\SettingValue;
use App\Support\Url;
use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * OpenID Connect relying party: authorization-code flow with PKCE, state and
 * nonce, ID-token signature + claim verification against the provider's
 * JWKS, optional userinfo lookup, and account provisioning / role mapping.
 */
class OidcService
{
    /** Settings key behind the Users page switch. Not part of config export/import. */
    public const AUTO_PROVISION_SETTING = 'oidc_auto_provision';

    private const SESSION_KEY = 'oidc_flow';

    private const FLOW_TTL_SECONDS = 600;

    public function __construct(private JwtVerifier $jwt) {}

    public function isConfigured(): bool
    {
        return (bool) config('oidc.enabled')
            && filled(config('oidc.issuer'))
            && filled(config('oidc.client_id'))
            && filled(config('oidc.client_secret'));
    }

    public function issuer(): string
    {
        return rtrim((string) config('oidc.issuer'), '/');
    }

    public function buttonLabel(): string
    {
        return (string) config('oidc.button_label', 'Sign in with SSO');
    }

    public function redirectUri(): string
    {
        return (string) (config('oidc.redirect_uri') ?: route('oidc.callback'));
    }

    /**
     * Whether a first SSO sign-in may create an account. The switch on the
     * Users page wins once an administrator has used it; until then
     * OIDC_AUTO_PROVISION decides.
     */
    public function autoProvisionEnabled(): bool
    {
        $stored = Setting::get(self::AUTO_PROVISION_SETTING);

        if ($stored === null || $stored === '') {
            return (bool) config('oidc.auto_provision', true);
        }

        return filter_var($stored, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Whether an account created by an administrator is picked up by SSO on
     * first sign-in (matched by provider-verified e-mail). Only then is an
     * account without a local password of any use.
     */
    public function linksAccountsByEmail(): bool
    {
        return $this->isConfigured() && (bool) config('oidc.link_by_email', true);
    }

    public function setAutoProvision(bool $enabled): void
    {
        Setting::set(self::AUTO_PROVISION_SETTING, SettingValue::encode($enabled));
    }

    // ── Discovery ────────────────────────────────────────────────────────

    public function discovery(bool $fresh = false): array
    {
        return $this->rememberJson('discovery', $fresh, fn () => $this->issuer().'/.well-known/openid-configuration', function (Response $response) {
            $doc = $response->successful() ? $response->json() : null;
            if (! is_array($doc) || empty($doc['authorization_endpoint']) || empty($doc['token_endpoint']) || empty($doc['jwks_uri'])) {
                Log::error('OIDC discovery document is invalid or incomplete.', ['status' => $response->status()]);
                throw new OidcException('The identity provider configuration is invalid.');
            }

            if (rtrim((string) ($doc['issuer'] ?? ''), '/') !== $this->issuer()) {
                Log::error('OIDC discovery issuer mismatch.', ['expected' => $this->issuer(), 'actual' => $doc['issuer'] ?? null]);
                throw new OidcException('The identity provider configuration is invalid.');
            }

            foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri', 'userinfo_endpoint', 'end_session_endpoint'] as $endpoint) {
                if (isset($doc[$endpoint]) && ! str_starts_with((string) $doc[$endpoint], 'https://') && ! $this->allowInsecureEndpoints()) {
                    Log::error("OIDC endpoint [{$endpoint}] is not https.");
                    throw new OidcException('The identity provider configuration is invalid.');
                }
            }

            return $doc;
        });
    }

    public function jwks(bool $fresh = false): array
    {
        return $this->rememberJson('jwks', $fresh, fn () => $this->discovery()['jwks_uri'], function (Response $response) {
            $keys = $response->successful() ? ($response->json('keys') ?? null) : null;
            if (! is_array($keys)) {
                throw new OidcException('The identity provider configuration is invalid.');
            }

            return $keys;
        });
    }

    // ── Authorization request ────────────────────────────────────────────

    public function buildAuthorizationUrl(Request $request): string
    {
        $discovery = $this->discovery();

        $state = Str::random(40);
        $nonce = Str::random(40);
        $verifier = Str::random(96);
        $challenge = JwtVerifier::base64UrlEncode(hash('sha256', $verifier, true));

        $request->session()->put(self::SESSION_KEY, [
            'state' => $state,
            'nonce' => $nonce,
            'verifier' => $verifier,
            'started_at' => time(),
        ]);

        $params = [
            'response_type' => 'code',
            'client_id' => config('oidc.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'scope' => (string) config('oidc.scopes', 'openid profile email'),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];

        return Url::withQuery((string) $discovery['authorization_endpoint'], $params);
    }

    // ── Callback ─────────────────────────────────────────────────────────

    public function handleCallback(Request $request): OidcIdentity
    {
        $flow = $request->session()->pull(self::SESSION_KEY);

        if (! is_array($flow) || empty($flow['state']) || empty($flow['nonce']) || empty($flow['verifier'])) {
            throw new OidcException('The sign-in session has expired. Please try again.');
        }

        if ((time() - (int) ($flow['started_at'] ?? 0)) > self::FLOW_TTL_SECONDS) {
            throw new OidcException('The sign-in session has expired. Please try again.');
        }

        $state = (string) $request->query('state', '');
        if ($state === '' || ! hash_equals($flow['state'], $state)) {
            Log::warning('OIDC callback with invalid state.', ['ip' => $request->ip()]);
            throw new OidcException('The sign-in request could not be verified. Please try again.');
        }

        if ($request->filled('error')) {
            Log::warning('OIDC provider returned an error.', ['error' => Str::limit(Redactor::message($request->query('error')), 100)]);
            throw new OidcException('The identity provider rejected the sign-in.');
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            throw new OidcException('The identity provider did not return an authorization code.');
        }

        $discovery = $this->discovery();

        try {
            $response = Http::asForm()
                ->timeout($this->timeout())
                ->acceptJson()
                ->post($discovery['token_endpoint'], [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $this->redirectUri(),
                    'client_id' => config('oidc.client_id'),
                    'client_secret' => config('oidc.client_secret'),
                    'code_verifier' => $flow['verifier'],
                ]);
        } catch (Throwable $e) {
            $this->logFailure('OIDC token request failed', $e);
            throw new OidcException('The identity provider could not be reached.');
        }

        if (! $response->successful() || ! is_string($response->json('id_token'))) {
            Log::warning('OIDC token exchange failed.', ['status' => $response->status(), 'error' => Str::limit(Redactor::message((string) $response->json('error', '')), 100)]);
            throw new OidcException('The identity provider rejected the sign-in.');
        }

        $idToken = $response->json('id_token');
        $claims = $this->verifyIdToken($idToken, $flow['nonce']);

        $accessToken = $response->json('access_token');
        if (is_string($accessToken) && ! empty($discovery['userinfo_endpoint']) && $this->needsUserinfo($claims)) {
            $claims = array_merge($claims, $this->userinfo($discovery['userinfo_endpoint'], $accessToken, (string) $claims['sub']));
        }

        $email = isset($claims['email']) && is_string($claims['email']) ? strtolower(trim($claims['email'])) : null;
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $email = null;
        }

        $name = collect(['name', 'preferred_username', 'nickname', 'given_name'])
            ->map(fn ($k) => isset($claims[$k]) && is_string($claims[$k]) ? trim($claims[$k]) : null)
            ->first(fn ($v) => $v !== null && $v !== '');

        return new OidcIdentity(
            issuer: $this->issuer(),
            subject: (string) $claims['sub'],
            email: $email,
            emailVerified: filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
            name: $name ?: $email,
            claims: $claims,
            idToken: $idToken,
        );
    }

    /**
     * @return array<string, mixed> verified claims
     */
    public function verifyIdToken(string $idToken, string $expectedNonce): array
    {
        try {
            $decoded = $this->jwt->decode($idToken);
        } catch (Throwable) {
            throw new OidcException('The identity provider returned an invalid token.');
        }

        $alg = (string) ($decoded['header']['alg'] ?? '');
        if (! in_array($alg, (array) config('oidc.allowed_algorithms', JwtVerifier::supportedAlgorithms()), true)) {
            Log::warning('OIDC ID token uses a disallowed algorithm.', ['alg' => $alg]);
            throw new OidcException('The identity provider returned an invalid token.');
        }

        $kid = $decoded['header']['kid'] ?? null;
        $key = $this->findKey($this->jwks(), $kid) ?? $this->findKey($this->jwks(fresh: true), $kid);
        if ($key === null) {
            Log::warning('OIDC signing key not found in JWKS.', ['kid' => $kid]);
            throw new OidcException('The identity provider returned an invalid token.');
        }

        if (! $this->jwt->verifyRsa($decoded['signing_input'], $decoded['signature'], $key, $alg)) {
            Log::warning('OIDC ID token signature verification failed.');
            throw new OidcException('The identity provider returned an invalid token.');
        }

        $claims = $decoded['payload'];
        $leeway = (int) config('oidc.clock_leeway', 60);
        $now = time();

        if (rtrim((string) ($claims['iss'] ?? ''), '/') !== $this->issuer()) {
            throw new OidcException('The identity provider returned an invalid token.');
        }

        $aud = $claims['aud'] ?? null;
        $audiences = is_array($aud) ? $aud : [$aud];
        if (! in_array(config('oidc.client_id'), $audiences, true)) {
            throw new OidcException('The identity provider returned an invalid token.');
        }
        if (count($audiences) > 1 && (! isset($claims['azp']) || $claims['azp'] !== config('oidc.client_id'))) {
            throw new OidcException('The identity provider returned an invalid token.');
        }

        if (! isset($claims['exp']) || ! is_numeric($claims['exp']) || (int) $claims['exp'] < $now - $leeway) {
            throw new OidcException('The identity provider returned an expired token.');
        }
        if (isset($claims['iat']) && is_numeric($claims['iat']) && (int) $claims['iat'] > $now + $leeway) {
            throw new OidcException('The identity provider returned an invalid token.');
        }
        if (isset($claims['nbf']) && is_numeric($claims['nbf']) && (int) $claims['nbf'] > $now + $leeway) {
            throw new OidcException('The identity provider returned an invalid token.');
        }

        if (! isset($claims['nonce']) || ! is_string($claims['nonce']) || ! hash_equals($expectedNonce, $claims['nonce'])) {
            throw new OidcException('The sign-in request could not be verified. Please try again.');
        }

        if (empty($claims['sub']) || ! is_scalar($claims['sub'])) {
            throw new OidcException('The identity provider returned an invalid token.');
        }

        return $claims;
    }

    // ── Provisioning ─────────────────────────────────────────────────────

    public function resolveUser(OidcIdentity $identity): User
    {
        $domains = (array) config('oidc.allowed_email_domains', []);
        if ($domains !== []) {
            $domain = $identity->email ? strtolower(substr(strrchr($identity->email, '@') ?: '', 1)) : '';
            if ($domain === '' || ! in_array($domain, array_map('strtolower', $domains), true)) {
                throw new OidcException('Your account is not permitted to access this application.');
            }
        }

        $user = User::where('oidc_issuer', $identity->issuer)->where('oidc_subject', $identity->subject)->first();

        if (! $user && $identity->email && config('oidc.link_by_email', true)) {
            $byEmail = User::findByEmail($identity->email);
            if ($byEmail) {
                if (! $identity->emailVerified) {
                    Log::warning('OIDC login refused: e-mail matches an existing account but is not verified by the provider.');
                    throw new OidcException('Your account could not be linked. Ask an administrator to link it.');
                }
                if ($byEmail->oidc_subject && $byEmail->oidc_subject !== $identity->subject) {
                    throw new OidcException('Your account could not be linked. Ask an administrator to link it.');
                }
                $byEmail->forceFill(['oidc_issuer' => $identity->issuer, 'oidc_subject' => $identity->subject])->save();
                $user = $byEmail;
            }
        }

        if (! $user) {
            if (! $this->autoProvisionEnabled()) {
                throw new OidcException('Your account has not been provisioned. Ask an administrator to create it.');
            }
            if (! $identity->email) {
                throw new OidcException('The identity provider did not supply an e-mail address.');
            }
            if (User::findByEmail($identity->email)) {
                throw new OidcException('Your account could not be linked. Ask an administrator to link it.');
            }

            $isFirst = User::query()->count() === 0;

            $user = User::create([
                'name' => $identity->name ?: $identity->email,
                'email' => $identity->email,
                'role' => ($isFirst && config('oidc.first_user_admin')) ? User::ROLE_ADMIN : User::ROLE_USER,
                'password' => null,
                'oidc_issuer' => $identity->issuer,
                'oidc_subject' => $identity->subject,
                'email_verified_at' => $identity->emailVerified ? now() : null,
            ]);
        }

        if ($user->isDisabled()) {
            throw new OidcException('Your account is disabled.');
        }

        $this->syncRole($user, $identity);

        // The provider's display name wins; last_login_at is stamped by SessionLogin.
        if ($identity->name && $identity->name !== $user->name) {
            $user->forceFill(['name' => $identity->name])->save();
        }

        return $user;
    }

    public function endSessionUrl(?string $idToken, string $postLogoutRedirect): ?string
    {
        if (! config('oidc.rp_initiated_logout', true) || ! $this->isConfigured()) {
            return null;
        }

        try {
            $endpoint = $this->discovery()['end_session_endpoint'] ?? null;
        } catch (Throwable) {
            return null;
        }

        if (! is_string($endpoint) || $endpoint === '') {
            return null;
        }

        $params = ['post_logout_redirect_uri' => $postLogoutRedirect, 'client_id' => config('oidc.client_id')];
        if ($idToken) {
            $params['id_token_hint'] = $idToken;
        }

        return Url::withQuery($endpoint, $params);
    }

    // ── Internals ────────────────────────────────────────────────────────

    private function syncRole(User $user, OidcIdentity $identity): void
    {
        $adminValues = (array) config('oidc.admin_values', []);
        $adminEmails = (array) config('oidc.admin_emails', []);

        if ($adminValues === [] && $adminEmails === []) {
            return; // no mapping configured: roles are managed inside StreamRadar
        }

        $claimName = (string) config('oidc.admin_claim', 'groups');

        if ($adminValues !== [] && ! array_key_exists($claimName, $identity->claims)) {
            Log::warning("OIDC role mapping: the provider sent no [{$claimName}] claim, so nobody can be mapped to admin by it. Check that OIDC_SCOPES requests the scope that releases this claim (often \"groups\") and that the client is allowed to use that scope.");
        }

        $claim = $identity->claims[$claimName] ?? [];
        $claimValues = is_array($claim) ? $claim : [$claim];
        $claimValues = array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $claimValues);

        $isAdmin = array_intersect($adminValues, $claimValues) !== []
            || ($identity->email && in_array($identity->email, $adminEmails, true));

        $target = $isAdmin ? User::ROLE_ADMIN : User::ROLE_USER;
        if ($user->role === $target) {
            return;
        }

        if ($target === User::ROLE_USER && $user->isLastActiveAdmin()) {
            Log::warning("OIDC role sync would demote the last administrator [{$user->email}]; keeping admin role.");

            return;
        }

        $user->forceFill(['role' => $target])->save();
    }

    /**
     * The ID token is not required to carry profile claims: many providers
     * release e-mail and group membership from the userinfo endpoint only.
     *
     * @param  array<string, mixed>  $claims
     */
    private function needsUserinfo(array $claims): bool
    {
        if (empty($claims['email']) || ! isset($claims['email_verified'])) {
            return true;
        }

        return (array) config('oidc.admin_values', []) !== []
            && ! array_key_exists((string) config('oidc.admin_claim', 'groups'), $claims);
    }

    private function userinfo(string $endpoint, string $accessToken, string $expectedSub): array
    {
        try {
            $response = Http::withToken($accessToken)->timeout($this->timeout())->acceptJson()->get($endpoint);
        } catch (Throwable $e) {
            $this->logFailure('OIDC userinfo request failed', $e);

            return [];
        }

        $info = $response->successful() ? $response->json() : null;
        if (! is_array($info) || (string) ($info['sub'] ?? '') !== $expectedSub) {
            Log::warning('OIDC userinfo response missing or subject mismatch; ignoring.');

            return [];
        }

        return $info;
    }

    private function findKey(array $keys, ?string $kid): ?array
    {
        $candidates = array_values(array_filter($keys, function ($k) use ($kid) {
            if (! is_array($k) || ($k['kty'] ?? null) !== 'RSA') {
                return false;
            }
            if (isset($k['use']) && $k['use'] !== 'sig') {
                return false;
            }

            return $kid === null ? true : (($k['kid'] ?? null) === $kid);
        }));

        if ($kid === null && count($candidates) !== 1) {
            return null;
        }

        return $candidates[0] ?? null;
    }

    /**
     * A JSON document from the provider, cached per issuer. $validate gets
     * the response and returns what to cache, or throws.
     *
     * @param  Closure(): string  $url
     * @param  Closure(Response): array  $validate
     */
    private function rememberJson(string $name, bool $fresh, Closure $url, Closure $validate): array
    {
        $key = "oidc:{$name}:".sha1($this->issuer());

        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, (int) config('oidc.discovery_ttl', 3600), function () use ($name, $url, $validate) {
            try {
                $response = Http::timeout($this->timeout())->acceptJson()->get($url());
            } catch (Throwable $e) {
                $this->logFailure("OIDC {$name} request failed", $e);
                throw new OidcException('The identity provider could not be reached.');
            }

            return $validate($response);
        });
    }

    private function timeout(): int
    {
        return max(1, (int) config('oidc.http_timeout', 10));
    }

    private function allowInsecureEndpoints(): bool
    {
        return app()->environment('testing');
    }

    private function logFailure(string $message, Throwable $e): void
    {
        Log::error($message, ['error' => Redactor::message($e->getMessage())]);
    }
}
