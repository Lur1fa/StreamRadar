<?php

namespace App\Http\Controllers;

use App\Http\Requests\NotificationDestinationRequest;
use App\Models\NotificationDestination;
use App\Rules\SafeWebhookUrl;
use App\Services\AlertService;
use App\Services\Http\UnsafeUrlException;
use App\Services\Notifications\DeliveryFailed;
use App\Services\Notifications\DestinationType;
use App\Services\Notifications\SampleAlerts;
use App\Support\Redactor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The signed-in account's alert destinations (Settings → Notifications).
 * Route model binding only resolves the account's own destinations, so
 * another account's destination is a 404.
 */
class NotificationDestinationController extends Controller
{
    public function store(NotificationDestinationRequest $request): RedirectResponse
    {
        NotificationDestination::createFor($request->user(), $request->destinationAttributes());

        return back()->with('success', 'Destination saved.');
    }

    public function update(NotificationDestinationRequest $request, NotificationDestination $destination): RedirectResponse
    {
        $destination->fill($request->destinationAttributes())->save();

        return back()->with('success', 'Destination saved.');
    }

    public function destroy(NotificationDestination $destination): RedirectResponse
    {
        $destination->delete();

        return back()->with('success', 'Destination deleted.');
    }

    /**
     * Send made-up alerts through the same code real alerts use. The answer
     * says why it failed without revealing the URL or credentials; details
     * go to the log, scrubbed.
     */
    public function test(Request $request, NotificationDestination $destination, AlertService $alerts, SampleAlerts $samples): JsonResponse
    {
        if (! $destination->isComplete()) {
            return response()->json(['success' => false, 'message' => 'This destination is not complete yet. Edit it and fill in the missing fields.']);
        }

        try {
            $alerts->deliver($destination, $samples->make($destination->type === DestinationType::Email ? 2 : 1));

            return response()->json(['success' => true, 'message' => 'Test sent.']);
        } catch (Throwable $e) {
            Log::info('Destination test failed', [
                'destination' => $destination->id,
                'exception' => $e::class,
                'error' => Redactor::message($e->getMessage()),
            ]);

            return response()->json(['success' => false, 'message' => $this->failureMessage($request, $destination, $e)]);
        }
    }

    private function failureMessage(Request $request, NotificationDestination $destination, Throwable $e): string
    {
        $status = $e instanceof DeliveryFailed ? $e->status : null;
        $refused = in_array($status, [401, 403], true);

        // These never contain the URL or credentials.
        $reason = match (true) {
            $e instanceof DeliveryFailed => $e->getMessage(),
            $e instanceof UnsafeUrlException => 'The address is not allowed: '.$e->getMessage().($e->allowable ? ' '.SafeWebhookUrl::localNetworkHint() : ''),
            $e instanceof ConnectionException => 'Could not connect.',
            default => 'Sending failed.',
        };

        $check = match ($destination->type) {
            DestinationType::Ntfy => $refused ? 'Check the access token, or the user name and password.' : 'Check the server URL and topic.',
            DestinationType::Webhook => $refused ? 'Check the authentication, headers and query parameters.' : 'Check the URL and method.',
            DestinationType::Email => $request->user()->isAdmin()
                ? 'Check the addresses and the mail server under Mail Server.'
                : 'Check the addresses, or ask an administrator to check the mail server.',
            DestinationType::Telegram => 'Check the bot token and chat ID.',
            DestinationType::Discord => 'Check the webhook URL.',
        };

        return $e instanceof UnsafeUrlException ? $reason : "{$reason} {$check}";
    }
}
