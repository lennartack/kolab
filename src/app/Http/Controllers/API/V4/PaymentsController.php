<?php

namespace App\Http\Controllers\API\V4;

use App\Http\Controllers\Controller;
use App\Http\Resources\WalletMandateResource;
use App\Jobs\Wallet\ChargeJob;
use App\Payment;
use App\Providers\PaymentProvider;
use App\Tenant;
use App\Utils;
use App\Wallet;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

class PaymentsController extends Controller
{
    /**
     * Get the auto-payment mandate info.
     */
    public function mandate(): WalletMandateResource
    {
        $user = $this->guard()->user();

        // TODO: Wallet selection
        $wallet = $user->wallets()->first();

        return new WalletMandateResource($wallet->getMandate());
    }

    /**
     * Create a new auto-payment mandate.
     */
    #[BodyParameter('amount', description: 'Money amount', type: 'float', required: true)]
    #[BodyParameter('balance', description: 'Wallet balance threshold', type: 'float', required: true)]
    public function mandateCreate(Request $request): JsonResponse
    {
        $user = $this->guard()->user();

        // TODO: Wallet selection
        $wallet = $user->wallets()->first();

        // Input validation
        if ($errors = self::mandateValidate($request, $wallet)) {
            return response()->json(['status' => 'error', 'errors' => $errors], 422);
        }

        $wallet->setSettings([
            'mandate_amount' => $request->amount,
            'mandate_balance' => $request->balance,
        ]);

        $mandate = [
            'currency' => $wallet->currency,

            'description' => Tenant::getConfig($user->tenant_id, 'app.name')
                . ' ' . self::trans('app.mandate-description-suffix'),

            'methodId' => $request->methodId ?: PaymentProvider::METHOD_CREDITCARD,
        ];

        // Normally the auto-payment setup operation is 0, if the balance is below the threshold
        // we'll top-up the wallet with the configured auto-payment amount
        if ($wallet->balance < round($request->balance * 100)) {
            $mandate['amount'] = (int) round($request->amount * 100);

            $mandate = $wallet->paymentRequest($mandate);
        }

        $provider = PaymentProvider::factory($wallet);

        $result = $provider->createMandate($wallet, $mandate);

        return response()->json([
            'status' => 'success',
            // Payment identifier
            'id' => $result['id'],
            // Payment checkout page location (Mollie)
            'redirectUrl' => $result['redirectUrl'] ?? null,
            // Payment checkout page location (Coinbase)
            'newWindowUrl' => $result['newWindowUrl'] ?? null,
        ]);
    }

    /**
     * Revoke the auto-payment mandate.
     */
    public function mandateDelete(): JsonResponse
    {
        $user = $this->guard()->user();

        // TODO: Wallet selection
        $wallet = $user->wallets()->first();

        $provider = PaymentProvider::factory($wallet);

        $provider->deleteMandate($wallet);

        $wallet->setSetting('mandate_disabled', null);

        return response()->json([
            'status' => 'success',
            'message' => self::trans('app.mandate-delete-success'),
        ]);
    }

    /**
     * Update a new auto-payment mandate.
     */
    #[BodyParameter('amount', description: 'Money amount', type: 'float', required: true)]
    #[BodyParameter('balance', description: 'Wallet balance threshold', type: 'float', required: true)]
    public function mandateUpdate(Request $request): JsonResponse
    {
        $user = $this->guard()->user();

        // TODO: Wallet selection
        $wallet = $user->wallets()->first();

        // Input validation
        if ($errors = self::mandateValidate($request, $wallet)) {
            return response()->json(['status' => 'error', 'errors' => $errors], 422);
        }

        $wallet->setSettings([
            'mandate_amount' => $request->amount,
            'mandate_balance' => $request->balance,
            // Re-enable the mandate to give it a chance to charge again
            // after it has been disabled (e.g. because the mandate amount was too small)
            'mandate_disabled' => null,
        ]);

        // Trigger auto-payment if the balance is below the threshold
        if ($wallet->balance < round($request->balance * 100)) {
            ChargeJob::dispatch($wallet->id);
        }

        $mandate = new WalletMandateResource($wallet->getMandate());

        return response()->json([
            'status' => 'success',
            'message' => self::trans('app.mandate-update-success'),
            'mandate' => $mandate,
        ]);
    }

    /**
     * Reset the auto-payment mandate, create a new payment for it.
     */
    public function mandateReset(Request $request): JsonResponse
    {
        $user = $this->guard()->user();

        // TODO: Wallet selection
        $wallet = $user->wallets()->first();

        $mandate = [
            'currency' => $wallet->currency,

            'description' => Tenant::getConfig($user->tenant_id, 'app.name')
                . ' ' . self::trans('app.mandate-description-suffix'),

            'methodId' => $request->methodId ?: PaymentProvider::METHOD_CREDITCARD,
            'redirectUrl' => Utils::serviceUrl('/payment/status', $user->tenant_id),
        ];

        $provider = PaymentProvider::factory($wallet);

        $result = $provider->createMandate($wallet, $mandate);

        return response()->json([
            'status' => 'success',
            // Payment identifier
            'id' => $result['id'],
            // Payment checkout page location (Mollie)
            'redirectUrl' => $result['redirectUrl'] ?? null,
            // Payment checkout page location (Coinbase)
            'newWindowUrl' => $result['newWindowUrl'] ?? null,
        ]);
    }

    /**
     * Validate an auto-payment mandate request.
     *
     * @param Request $request the API request
     * @param Wallet  $wallet  The wallet
     *
     * @return array|null List of errors on error or Null on success
     */
    protected static function mandateValidate(Request $request, Wallet $wallet)
    {
        $rules = [
            'amount' => 'required|numeric',
            'balance' => 'required|numeric|min:0',
        ];

        // Check required fields
        $v = Validator::make($request->all(), $rules);

        // TODO: allow comma as a decimal point?

        if ($v->fails()) {
            return $v->errors()->toArray();
        }

        $amount = (int) round($request->amount * 100);

        // Validate the minimum value
        // It has to be at least minimum payment amount and must cover current debt,
        // and must be more than a yearly/monthly payment (according to the plan)
        $min = $wallet->getMinMandateAmount();
        $label = 'minamount';

        if ($wallet->balance < 0 && $wallet->balance < $min * -1) {
            $min = $wallet->balance * -1;
            $label = 'minamountdebt';
        }

        if ($amount < $min) {
            return ['amount' => self::trans("validation.{$label}", ['amount' => $wallet->money($min)])];
        }

        return null;
    }

    /**
     * Get status of the last payment.
     */
    public function paymentStatus(): JsonResponse
    {
        $user = $this->guard()->user();
        $wallet = $user->wallets()->first();

        $payment = $wallet->payments()->orderBy('created_at', 'desc')->first();

        if (empty($payment)) {
            return $this->errorResponse(404);
        }

        $done = [Payment::STATUS_PAID, Payment::STATUS_CANCELED, Payment::STATUS_FAILED, Payment::STATUS_EXPIRED];

        if (in_array($payment->status, $done)) {
            $label = "app.payment-status-{$payment->status}";
        } else {
            $label = "app.payment-status-checking";
        }

        return response()->json([
            // Payment identifier
            'id' => $payment->id,
            // Payment status
            'status' => $payment->status,
            // Payment type
            'type' => $payment->type,
            // Payment status message
            'statusMessage' => self::trans($label),
            // Payment description
            'description' => $payment->description,
        ]);
    }

    /**
     * Create a new payment.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->guard()->user();

        // TODO: Wallet selection
        $wallet = $user->wallets()->first();

        // Check required fields
        $v = Validator::make($request->all(), $rules = [
            // Money amount to pay
            'amount' => 'required|numeric',
        ]);

        // TODO: allow comma as a decimal point?

        if ($v->fails()) {
            return response()->json(['status' => 'error', 'errors' => $v->errors()], 422);
        }

        $amount = (int) round($request->amount * 100);

        // Validate the minimum value
        if ($amount < Payment::MIN_AMOUNT) {
            $min = $wallet->money(Payment::MIN_AMOUNT);
            $errors = ['amount' => self::trans('validation.minamount', ['amount' => $min])];
            return response()->json(['status' => 'error', 'errors' => $errors], 422);
        }

        $currency = $request->currency;

        $request = $wallet->paymentRequest([
            'type' => Payment::TYPE_ONEOFF,
            'currency' => $currency,
            'amount' => $amount,
            'methodId' => $request->methodId ?: PaymentProvider::METHOD_CREDITCARD,
            'description' => Tenant::getConfig($user->tenant_id, 'app.name') . ' Payment',
        ]);

        $provider = PaymentProvider::factory($wallet, $currency);

        $result = $provider->payment($wallet, $request);

        return response()->json([
            'status' => 'success',
            // Payment identifier
            'id' => $result['id'],
            // Payment checkout page location (Mollie)
            'redirectUrl' => $result['redirectUrl'] ?? null,
            // Payment checkout page location (Coinbase)
            'newWindowUrl' => $result['newWindowUrl'] ?? null,
        ]);
    }

    /**
     * Update payment status (and balance).
     *
     * @param string $provider Provider name
     */
    public function webhook($provider): Response
    {
        $code = 200;

        if ($provider = PaymentProvider::factory($provider)) {
            $code = $provider->webhook();
        }

        return response($code < 400 ? 'Success' : 'Server error', $code);
    }

    /**
     * List payment methods.
     */
    public function paymentMethods(Request $request): JsonResponse
    {
        $user = $this->guard()->user();

        // TODO: Wallet selection
        $wallet = $user->wallets()->first();

        $methods = PaymentProvider::paymentMethods($wallet, $request->type);

        return response()->json($methods);
    }

    /**
     * Check for pending payments.
     */
    public function hasPayments(Request $request): JsonResponse
    {
        $user = $this->guard()->user();

        // TODO: Wallet selection
        $wallet = $user->wallets()->first();

        $exists = $wallet->payments()->where('type', Payment::TYPE_ONEOFF)
            ->whereIn('status', [
                Payment::STATUS_OPEN,
                Payment::STATUS_PENDING,
                Payment::STATUS_AUTHORIZED,
            ])
            ->exists();

        return response()->json([
            'status' => 'success',
            // @var bool Indicates existence of pending payments
            'hasPending' => $exists,
        ]);
    }

    /**
     * List pending payments.
     */
    #[BodyParameter('page', description: 'List page', type: 'int')]
    public function payments(Request $request): JsonResponse
    {
        $user = $this->guard()->user();

        // TODO: Wallet selection
        $wallet = $user->wallets()->first();

        $pageSize = 10;
        $page = (int) (request()->input('page')) ?: 1;
        $hasMore = false;
        $result = $wallet->payments()->where('type', Payment::TYPE_ONEOFF)
            ->whereIn('status', [
                Payment::STATUS_OPEN,
                Payment::STATUS_PENDING,
                Payment::STATUS_AUTHORIZED,
            ])
            ->orderBy('created_at', 'desc')
            ->limit($pageSize + 1)
            ->offset($pageSize * ($page - 1))
            ->get();

        if (count($result) > $pageSize) {
            $result->pop();
            $hasMore = true;
        }

        $result = $result->map(static function ($item) use ($wallet) {
            $provider = PaymentProvider::factory($item->provider);
            $payment = $provider->getPayment($item->id);
            $entry = [
                'id' => $item->id,
                'createdAt' => $item->created_at->format('Y-m-d H:i'),
                'type' => $item->type,
                'description' => $item->description,
                'amount' => $item->amount,
                'currency' => $wallet->currency,
                // note: $item->currency/$item->currency_amount might be different
                'status' => $item->status,
                'isCancelable' => $payment['isCancelable'],
                'checkoutUrl' => $payment['checkoutUrl'],
            ];

            return $entry;
        });

        return response()->json([
            'status' => 'success',
            // @var array List of pending one-off payments
            'list' => $result,
            // @var int Number of list entries
            'count' => count($result),
            // @var int Current page number
            'page' => $page,
            // @var bool
            'hasMore' => $hasMore,
        ]);
    }
}
