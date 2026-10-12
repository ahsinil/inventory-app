<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\Integration\WebhookDeliveryService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WebhookController extends Controller
{
    public function index()
    {
        return $this->successResponse(WebhookSubscription::latest('id')->paginate());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'url' => 'required|url|max:2000',
            'events' => 'required|array|min:1',
            'events.*' => 'string|max:100',
        ]);

        $secret = 'whsec_'.Str::random(40);
        $sub = WebhookSubscription::create($data + [
            'company_id' => $request->user()->company_id ?? Company::value('id'),
            'secret' => $secret,
        ]);

        // Signing secret is shown ONCE.
        return $this->successResponse(
            ['subscription' => $sub, 'secret' => $secret],
            ['notice' => 'Store the signing secret now. It will not be shown again.'],
            201
        );
    }

    public function update(Request $request, WebhookSubscription $webhook)
    {
        $data = $request->validate([
            'name' => 'string|max:255',
            'url' => 'url|max:2000',
            'events' => 'array|min:1',
            'events.*' => 'string|max:100',
            'is_active' => 'boolean',
        ]);
        $webhook->update($data);

        return $this->successResponse($webhook);
    }

    public function destroy(WebhookSubscription $webhook)
    {
        $webhook->delete();

        return $this->successResponse(null);
    }

    public function deliveries(WebhookSubscription $webhook)
    {
        return $this->successResponse($webhook->deliveries()->latest('id')->paginate());
    }

    /** Replay a delivery: reset and attempt again immediately. */
    public function replay(WebhookDelivery $delivery, WebhookDeliveryService $service)
    {
        $delivery->update(['status' => 'PENDING', 'attempts' => 0, 'next_attempt_at' => now()]);

        return $this->successResponse($service->attempt($delivery->load('subscription')));
    }
}
