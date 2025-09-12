<?php

namespace App\Http\Controllers\API;

use App\Discount;
use App\Domain;
use App\Group;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Jobs\Mail\SignupVerificationJob;
use App\Payment;
use App\Plan;
use App\Providers\PaymentProvider;
use App\ReferralCode;
use App\Rules\Password;
use App\Rules\ReferralCode as ReferralCodeRule;
use App\Rules\SignupExternalEmail;
use App\Rules\SignupToken as SignupTokenRule;
use App\Rules\UserEmailDomain;
use App\Rules\UserEmailLocal;
use App\SignupCode;
use App\SignupInvitation;
use App\SignupToken;
use App\Tenant;
use App\User;
use App\Utils;
use App\VatRate;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Signup process API
 */
class SignupController extends Controller
{
    /**
     * List of plans for signup.
     *
     * @unauthenticated
     */
    public function plans(Request $request): JsonResponse
    {
        // Use reverse order just to have individual on left, group on right ;)
        // But prefer monthly on left, yearly on right
        $plans = Plan::withEnvTenantContext()->where('hidden', false)
            ->orderBy('months')->orderByDesc('title')
            ->get();

        return response()->json([
            'status' => 'success',
            // List of plans available for signup
            'plans' => PlanResource::collection($plans),
        ]);
    }

    /**
     * List of public domains for signup.
     *
     * @unauthenticated
     */
    public function domains(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            // @var array<string> List of domain namespaces available for signup
            'domains' => Domain::getPublicDomains(),
        ]);
    }

    /**
     * Starts signup process.
     *
     * Verifies user name and email, sends verification message. Returns the verification code.
     *
     * @unauthenticated
     */
    #[BodyParameter('plan', description: 'Plan identifier', type: 'string', required: true)]
    #[BodyParameter('first_name', description: 'First name', type: 'string')]
    #[BodyParameter('last_name', description: 'Last name', type: 'string')]
    #[BodyParameter('email', description: 'External email address for verification (required for non-token signup)', type: 'string')]
    #[BodyParameter('referral', description: 'Referral program code', type: 'string')]
    #[BodyParameter('voucher', description: 'Voucher code', type: 'string')]
    #[BodyParameter('token', description: 'Signup token (required for token-mode signup)', type: 'string')]
    public function init(Request $request): JsonResponse
    {
        // Don't allow URLs in user names preventing abuse of signup email
        // FIXME: I suppose we could also not use "Dear <user name>" in email
        $name_validator = static function (string $attribute, mixed $value, \Closure $fail) {
            if (str_contains($value, '://') || str_contains($value, '/')) {
                $fail(self::trans('validation.invalidvalue'));
            }
        };

        $rules = [
            'first_name' => ['string', 'max:50', $name_validator],
            'last_name' => ['string', 'max:50', $name_validator],
            'voucher' => 'max:32',
            'plan' => 'required',
        ];

        $plan = $this->getPlan($request);

        if ($plan?->mode == Plan::MODE_TOKEN) {
            $rules['token'] = ['required', 'string', new SignupTokenRule($plan)];
        } else {
            $rules['email'] = ['required', 'string', new SignupExternalEmail()];
            $rules['referral'] = ['nullable', 'string', new ReferralCodeRule()];
        }

        // Check required fields, validate input
        $v = Validator::make($request->all(), $rules);

        if ($v->fails()) {
            return response()->json(['status' => 'error', 'errors' => $v->errors()->toArray()], 422);
        }

        // Generate the verification code
        $code = SignupCode::create([
            'email' => $plan->mode == Plan::MODE_TOKEN ? $request->token : $request->email,
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'plan' => $plan->title,
            'voucher' => $request->voucher,
            'referral' => $request->referral,
        ]);

        $response = [
            'status' => 'success',
            // Verification code
            'code' => $code->code,
            // Plan mode
            'mode' => $plan->mode ?: 'email',
            // List of domain namespaces available for signup
            'domains' => Domain::getPublicDomains(),
            // @var bool Indicates that the plan is viable for a custom domain signup
            'is_domain' => $plan->hasDomain(),
            // @var string|null Short verification code (for token signups only)
            'short_code' => null,
        ];

        if ($plan->mode == Plan::MODE_TOKEN) {
            // Token verification, jump to the last step
            $response['short_code'] = $code->short_code;
        } else {
            // External email verification, send an email message
            SignupVerificationJob::dispatch($code);
            unset($response['short_code']);
        }

        return response()->json($response);
    }

    /**
     * Signup invitation information.
     *
     * @param string $id Signup invitation identifier
     *
     * @unauthenticated
     */
    public function invitation($id): JsonResponse
    {
        $invitation = SignupInvitation::withEnvTenantContext()->find($id);

        if (empty($invitation) || $invitation->isCompleted()) {
            return $this->errorResponse(404);
        }

        return response()->json([
            // Signup invitation identifier
            'id' => $id,
        ]);
    }

    /**
     * Validation of the verification code.
     *
     * @param Request $request HTTP request
     * @param bool    $update  Update the signup code record
     *
     * @unauthenticated
     */
    #[BodyParameter('code', description: 'Verification code', type: 'string', required: true)]
    #[BodyParameter('short_code', description: 'Short code', type: 'string', required: true)]
    public function verify(Request $request, $update = true): JsonResponse
    {
        // Validate the request args
        $v = Validator::make(
            $request->all(),
            [
                'code' => 'required',
                'short_code' => 'required',
            ]
        );

        if ($v->fails()) {
            return response()->json(['status' => 'error', 'errors' => $v->errors()], 422);
        }

        // Validate the verification code
        $code = SignupCode::withEnvTenantContext()->find($request->code);

        if (
            empty($code)
            || $code->isExpired()
            || Str::upper($request->short_code) !== Str::upper($code->short_code)
        ) {
            $errors = ['short_code' => self::trans('validation.signupcodeinvalid')];
            return response()->json(['status' => 'error', 'errors' => $errors], 422);
        }

        // For signup last-step mode remember the code object, so we can delete it
        // with single SQL query (->delete()) instead of two
        $request->merge(['code' => $code]);

        $plan = $this->getPlan($request);

        if (!$plan) {
            $errors = ['short_code' => self::trans('validation.signupcodeinvalid')];
            return response()->json(['status' => 'error', 'errors' => $errors], 422);
        }

        if ($update) {
            $code->verify_ip_address = $request->ip();
            $code->save();
        }

        return response()->json([
            'status' => 'success',
            // User email address to sign up for
            'email' => $code->email,
            // First name
            'first_name' => $code->first_name,
            // Last name
            'last_name' => $code->last_name,
            // Voucher code
            'voucher' => $code->voucher,
            // @var bool Indicates that the plan is viable for a custom domain signup
            'is_domain' => $plan->hasDomain(),
        ]);
    }

    /**
     * Validates the input to the final signup request.
     *
     * @unauthenticated
     */
    #[BodyParameter('login', description: 'User login', type: 'string', required: true)]
    #[BodyParameter('domain', description: 'User domain namespace', type: 'string', required: true)]
    #[BodyParameter('password', description: 'User password', type: 'string', required: true)]
    #[BodyParameter('voucher', description: 'Voucher code', type: 'string')]
    #[BodyParameter('plan', description: 'Plan identifier (required when not using a verification code)', type: 'string')]
    #[BodyParameter('invitation', description: 'Signup invitation identifier', type: 'string')]
    #[BodyParameter('token', description: 'Signup token (required for mode=token plans)', type: 'string')]
    #[BodyParameter('first_name', description: 'First name', type: 'string')]
    #[BodyParameter('last_name', description: 'Last name', type: 'string')]
    public function signupValidate(Request $request): JsonResponse
    {
        $rules = [
            'login' => 'required|min:2',
            'password' => ['required', 'confirmed', new Password()],
            'domain' => 'required',
            'voucher' => 'max:32',
        ];

        if ($request->invitation) {
            // Signup via invitation
            $invitation = SignupInvitation::withEnvTenantContext()->find($request->invitation);

            if (empty($invitation) || $invitation->isCompleted()) {
                return $this->errorResponse(404);
            }

            // Check optional fields
            $rules['first_name'] = 'max:128';
            $rules['last_name'] = 'max:128';
        }

        if (!$request->code) {
            $rules['plan'] = 'required';
        }

        $plan = $this->getPlan($request);

        // Direct signup by token
        if ($request->token) {
            // This will validate the token and the plan mode
            $rules['token'] = ['required', 'string', new SignupTokenRule($plan)];
        }

        // Validate input
        $v = Validator::make($request->all(), $rules);

        if ($v->fails()) {
            return response()->json(['status' => 'error', 'errors' => $v->errors()], 422);
        }

        $settings = [];

        if ($request->token) {
            $settings = ['signup_token' => strtoupper($request->token)];
        } elseif (!empty($invitation)) {
            $settings = [
                'external_email' => $invitation->email,
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
            ];
        } elseif (!$request->code && $plan?->mode == Plan::MODE_MANDATE) {
            // mandate mode
        } else {
            // Validate verification codes (again)
            $v = $this->verify($request, false);
            if ($v->status() !== 200) {
                return $v;
            }

            // Get user name/email from the verification code database
            $code_data = $v->getData();

            $settings = [
                'first_name' => $code_data->first_name,
                'last_name' => $code_data->last_name,
            ];

            $plan = $this->getPlan($request);

            if ($plan->mode == Plan::MODE_TOKEN) {
                $settings['signup_token'] = strtoupper($code_data->email);
            } else {
                $settings['external_email'] = $code_data->email;
            }
        }

        // Find the voucher discount
        if ($request->voucher) {
            $discount = Discount::where('code', \strtoupper($request->voucher))
                ->where('active', true)->first();

            if (!$discount) {
                $errors = ['voucher' => self::trans('validation.voucherinvalid')];
                return response()->json(['status' => 'error', 'errors' => $errors], 422);
            }
        }

        $is_domain = $plan->hasDomain();

        // Validate login
        if ($errors = self::validateLogin($request->login, $request->domain, $is_domain)) {
            return response()->json(['status' => 'error', 'errors' => $errors], 422);
        }

        // Set some properties for signup() method
        $request->settings = $settings;
        $request->discount = $discount ?? null;
        $request->invitation = $invitation ?? null;

        $result = [];

        if ($plan->mode == Plan::MODE_MANDATE) {
            $result = $this->mandateForPlan($plan, $request->discount);
        }

        return response()->json($result + ['status' => 'success']);
    }

    /**
     * Finishes the signup process.
     *
     * On success creates a new account and returns authentication token(s) and user information.
     *
     * @unauthenticated
     */
    #[BodyParameter('login', description: 'User login', type: 'string', required: true)]
    #[BodyParameter('domain', description: 'User domain namespace', type: 'string', required: true)]
    #[BodyParameter('password', description: 'User password', type: 'string', required: true)]
    #[BodyParameter('voucher', description: 'Voucher code', type: 'string')]
    #[BodyParameter('plan', description: 'Plan identifier (required when not using a verification code)', type: 'string')]
    #[BodyParameter('invitation', description: 'Signup invitation identifier', type: 'string')]
    #[BodyParameter('token', description: 'Signup token (required for mode=token plans)', type: 'string')]
    #[BodyParameter('first_name', description: 'First name', type: 'string')]
    #[BodyParameter('last_name', description: 'Last name', type: 'string')]
    public function signup(Request $request): JsonResponse
    {
        $v = $this->signupValidate($request);
        if ($v->status() !== 200) {
            return $v;
        }

        $is_domain = $request->plan->hasDomain();

        // We allow only ASCII, so we can safely lower-case the email address
        $login = Str::lower($request->login);
        $domain_name = Str::lower($request->domain);
        $domain = null;
        $user_status = User::STATUS_RESTRICTED;

        if ($request->discount && $request->discount->discount == 100) {
            if ($request->plan->mode == Plan::MODE_MANDATE) {
                $user_status = User::STATUS_ACTIVE;
            } else {
                $user_status = User::STATUS_NEW;
            }
        }

        DB::beginTransaction();

        // Create domain record
        if ($is_domain && !Domain::withTrashed()->where('namespace', $domain_name)->exists()) {
            $domain = Domain::create([
                'namespace' => $domain_name,
                'type' => Domain::TYPE_EXTERNAL,
            ]);
        }

        // Create user record
        $user = User::create([
            'email' => $login . '@' . $domain_name,
            'password' => $request->password,
            'status' => $user_status,
        ]);

        if ($request->discount) {
            $wallet = $user->wallets()->first();
            $wallet->discount()->associate($request->discount);
            $wallet->save();
        }

        $user->assignPlan($request->plan, $domain);

        // Save the external email and plan in user settings
        $user->setSettings($request->settings);

        // Update the invitation
        if ($request->invitation) {
            $request->invitation->status = SignupInvitation::STATUS_COMPLETED;
            $request->invitation->user_id = $user->id;
            $request->invitation->save();
        }

        // Soft-delete the verification code, and store some more info with it
        if ($request->code) {
            $request->code->user_id = $user->id;
            $request->code->submit_ip_address = $request->ip();
            $request->code->deleted_at = \now();
            $request->code->timestamps = false;
            $request->code->save();
        }

        // Referral program
        if (
            $request->code
            && $request->code->referral
            && ($code = ReferralCode::find($request->code->referral))
            && $code->program->active
        ) {
            // Keep the code-to-user relation
            $code->referrals()->create(['user_id' => $user->id]);

            // Use discount assigned to the referral program
            if (!$request->discount && $code->program->discount && $code->program->discount->active) {
                $wallet = $user->wallets()->first();
                $wallet->discount()->associate($code->program->discount);
                $wallet->save();
            }
        }

        // Bump up counter on the signup token
        if (!empty($request->settings['signup_token'])) {
            SignupToken::where('id', $request->settings['signup_token'])->increment('counter');
        }

        DB::commit();

        $response = AuthController::logonResponse($user, $request->password);

        if ($request->plan->mode == Plan::MODE_MANDATE) {
            $data = $response->getData(true);
            // TODO: Make it visible in the API Doc
            $data['checkout'] = $this->mandateForPlan($request->plan, $request->discount, $user);
            $response->setData($data);
        }

        return $response;
    }

    /**
     * Collects some content to display to the user before redirect to a checkout page.
     * Optionally creates a recurrent payment mandate for specified user/plan.
     */
    protected function mandateForPlan(Plan $plan, ?Discount $discount = null, ?User $user = null): array
    {
        $result = [];

        $min = Payment::MIN_AMOUNT;
        $planCost = $cost = $plan->cost();
        $disc = 0;

        if ($discount) {
            // Free accounts don't need the auto-payment mandate
            // Note: This means the voucher code is the only point of user verification
            if ($discount->discount == 100) {
                return [
                    'content' => self::trans('app.signup-account-free'),
                    'cost' => 0,
                ];
            }

            $planCost = (int) ($planCost * (100 - $discount->discount) / 100);
            $disc = $cost - $planCost;
        }

        if ($planCost > $min) {
            $min = $planCost;
        }

        if ($user) {
            $wallet = $user->wallets()->first();
            $wallet->setSettings([
                'mandate_amount' => sprintf('%.2F', round($min / 100, 2)),
                'mandate_balance' => 0,
            ]);

            $mandate = [
                'currency' => $wallet->currency,

                'description' => Tenant::getConfig($user->tenant_id, 'app.name')
                    . ' ' . self::trans('app.mandate-description-suffix'),

                'methodId' => PaymentProvider::METHOD_CREDITCARD,
                'redirectUrl' => Utils::serviceUrl('/payment/status', $user->tenant_id),
            ];

            $provider = PaymentProvider::factory($wallet);

            $result = $provider->createMandate($wallet, $mandate);
        }

        $country = Utils::countryForRequest();
        $period = $plan->months == 12 ? 'yearly' : 'monthly';
        $currency = \config('app.currency');
        $rate = VatRate::where('country', $country)
            ->where('start', '<=', now()->format('Y-m-d h:i:s'))
            ->orderByDesc('start')
            ->limit(1)
            ->first();

        $summary = '<tr class="subscription">'
                . '<td>' . self::trans("app.signup-subscription-{$period}") . '</td>'
                . '<td class="money">' . Utils::money($cost, $currency) . '</td>'
            . '</tr>';

        if ($discount) {
            $summary .= '<tr class="discount">'
                . '<td>' . self::trans('app.discount-code', ['code' => $discount->code]) . '</td>'
                . '<td class="money">' . Utils::money(-$disc, $currency) . '</td>'
            . '</tr>';
        }

        $summary .= '<tr class="sep"><td colspan="2"></td></tr>'
            . '<tr class="total">'
                . '<td>' . self::trans('app.total') . '</td>'
                . '<td class="money">' . Utils::money($planCost, $currency) . '</td>'
            . '</tr>';

        if ($rate && $rate->rate > 0) {
            // TODO: app.vat.mode
            $vat = (int) round($planCost * $rate->rate / 100);
            $content = self::trans('app.vat-incl', [
                'rate' => Utils::percent($rate->rate),
                'cost' => Utils::money($planCost - $vat, $currency),
                'vat' => Utils::money($vat, $currency),
            ]);

            $summary .= '<tr class="vat-summary"><td colspan="2">*' . $content . '</td></tr>';
        }

        $trialEnd = $plan->free_months ? now()->copy()->addMonthsWithoutOverflow($plan->free_months) : now();
        $params = [
            'cost' => Utils::money($planCost, $currency),
            'date' => $trialEnd->toDateString(),
        ];

        $result['title'] = self::trans("app.signup-plan-{$period}");
        $result['content'] = self::trans('app.signup-account-mandate', $params);
        $result['summary'] = '<table>' . $summary . '</table>';
        $result['cost'] = $planCost;

        return $result;
    }

    /**
     * Returns plan for the signup process
     *
     * @param Request $request HTTP request
     *
     * @returns ?Plan Plan object selected for current signup process
     */
    protected function getPlan(Request $request): ?Plan
    {
        if (!$request->plan instanceof Plan) {
            $plan = null;

            // Get the plan if specified and exists...
            if (($request->code instanceof SignupCode) && $request->code->plan) {
                $plan = Plan::withEnvTenantContext()->where('title', $request->code->plan)->first();
            } elseif ($request->plan) {
                $plan = Plan::withEnvTenantContext()->where('title', $request->plan)->first();
            }

            $request->merge(['plan' => $plan]);
        }

        return $request->plan;
    }

    /**
     * Login (kolab identity) validation
     *
     * @param string $login     Login (local part of an email address)
     * @param string $namespace Domain name
     * @param bool   $external  Enable signup for a custom domain
     *
     * @return array Error messages on validation error
     */
    protected static function validateLogin($login, $namespace, $external = false): ?array
    {
        // Validate login part alone
        $v = Validator::make(
            ['login' => $login],
            ['login' => ['required', 'string', new UserEmailLocal($external)]]
        );

        if ($v->fails()) {
            return ['login' => $v->errors()->toArray()['login'][0]];
        }

        $domain = null;
        if (is_string($namespace)) {
            $namespace = Str::lower($namespace);
            $domain = Domain::withTrashed()->where('namespace', $namespace)->first();
        }

        if ($domain && $domain->isPublic() && !$domain->trashed()) {
            // no error, everyone can signup for an existing public domain
        } elseif ($domain) {
            // domain exists and is not public (or is deleted)
            return ['domain' => self::trans('validation.domainnotavailable')];
        } else {
            // non-existing custom domain
            if (!$external) {
                return ['domain' => self::trans('validation.domaininvalid')];
            }

            $v = Validator::make(
                ['domain' => $namespace],
                ['domain' => ['required', 'string', new UserEmailDomain()]]
            );

            if ($v->fails()) {
                return ['domain' => $v->errors()->toArray()['domain'][0]];
            }
        }

        // Check if user with specified login already exists
        $email = $login . '@' . $namespace;
        if (User::emailExists($email) || User::aliasExists($email) || Group::emailExists($email)) {
            return ['login' => self::trans('validation.loginexists')];
        }

        return null;
    }
}
