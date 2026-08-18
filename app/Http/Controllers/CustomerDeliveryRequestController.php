<?php

namespace App\Http\Controllers;

use App\Auth\CustomerDeliveryRequestPrincipal;
use App\Exceptions\DeliveryWorkflowException;
use App\Http\Requests\SubmitCustomerDeliveryRequestRequest;
use App\Services\CustomerDeliveryRequestService;
use App\Services\CustomerDeliveryRequestSessionService;
use App\Services\CustomerDeliveryRequestTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class CustomerDeliveryRequestController extends Controller
{
    public function enter(
        Request $request,
        string $token,
        CustomerDeliveryRequestTokenService $tokens,
        CustomerDeliveryRequestSessionService $sessions
    ): RedirectResponse|Response {
        $deliveryRequest = $tokens->findAccessible($token);

        if (! $deliveryRequest) {
            return $this->invalid();
        }

        return redirect()
            ->route('customer.delivery-request.page')
            ->withCookie($sessions->cookieFor($deliveryRequest, $request));
    }

    public function page(
        CustomerDeliveryRequestSessionService $sessions
    ): View|Response {
        $principal = auth('customer_delivery_request')->user();

        if (! $principal instanceof CustomerDeliveryRequestPrincipal) {
            return response()->view('delivery-request.invalid', status: 401);
        }

        $deliveryRequest = $sessions->deliveryRequestForPrincipal($principal);

        if (! $deliveryRequest) {
            return response()->view('delivery-request.invalid', status: 401);
        }

        return view('delivery-request.show', [
            'businessName' => $deliveryRequest->business()->value('name'),
            'expiresAt' => $principal->expiresAt,
        ]);
    }

    public function store(
        SubmitCustomerDeliveryRequestRequest $request,
        CustomerDeliveryRequestService $deliveryRequests,
        CustomerDeliveryRequestSessionService $sessions
    ): RedirectResponse|Response {
        $principal = auth('customer_delivery_request')->user();

        if (! $principal instanceof CustomerDeliveryRequestPrincipal) {
            return $this->invalid(401);
        }

        try {
            $deliveryRequests->submit($principal, $request->validated());
        } catch (DeliveryWorkflowException) {
            return $this->invalid(409);
        }

        return redirect()
            ->route('customer.delivery-request.submitted')
            ->withCookie($sessions->forgetCookie());
    }

    public function submitted(): View
    {
        return view('delivery-request.submitted');
    }

    public function destroy(
        CustomerDeliveryRequestSessionService $sessions
    ): Response {
        return response()->noContent()->withCookie($sessions->forgetCookie());
    }

    private function invalid(int $status = 404): Response
    {
        return response('Delivery request access is invalid or expired.', $status);
    }
}
