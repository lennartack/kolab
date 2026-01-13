<?php

namespace App\Providers;

use App\Payment;
use App\Providers\Payment\Coinbase;
use App\Providers\Payment\Mollie;
use App\Providers\Payment\Stripe;
use App\Utils;
use App\Wallet;
use Illuminate\Support\Facades\Cache;

abstract class PaymentProvider
{
    public const METHOD_CREDITCARD = 'creditcard';
    public const METHOD_PAYPAL = 'paypal';
    public const METHOD_BANKTRANSFER = 'banktransfer';
    public const METHOD_DIRECTDEBIT = 'directdebit';
    public const METHOD_BITCOIN = 'bitcoin';

    public const PROVIDER_MOLLIE = 'mollie';
    public const PROVIDER_STRIPE = 'stripe';
    public const PROVIDER_COINBASE = 'coinbase';

    private static $paymentMethodIcons = [
        self::METHOD_CREDITCARD => ['prefix' => 'far', 'name' => 'credit-card'],
        self::METHOD_PAYPAL => ['prefix' => 'fab', 'name' => 'paypal'],
        self::METHOD_BANKTRANSFER => ['prefix' => 'fas', 'name' => 'building-columns'],
        self::METHOD_BITCOIN => ['prefix' => 'fab', 'name' => 'bitcoin'],
    ];

    /**
     * Detect the name of the provider
     *
     * @param Wallet|string|null $provider_or_wallet
     *
     * @return string The name of the provider
     */
    private static function providerName($provider_or_wallet = null): string
    {
        if ($provider_or_wallet instanceof Wallet) {
            $settings = $provider_or_wallet->getSettings(['stripe_id', 'mollie_id']);

            if ($settings['stripe_id']) {
                $provider = self::PROVIDER_STRIPE;
            } elseif ($settings['mollie_id']) {
                $provider = self::PROVIDER_MOLLIE;
            }
        } else {
            $provider = $provider_or_wallet;
        }

        if (empty($provider)) {
            $provider = \config('services.payment_provider') ?: self::PROVIDER_MOLLIE;
        }

        return \strtolower($provider);
    }

    /**
     * Factory method
     *
     * @param Wallet|string|null $provider_or_wallet
     */
    public static function factory($provider_or_wallet = null, $currency = null)
    {
        if (is_string($currency) && \strtolower($currency) == 'btc') {
            return new Coinbase();
        }
        switch (self::providerName($provider_or_wallet)) {
            case self::PROVIDER_STRIPE:
                return new Stripe();
            case self::PROVIDER_MOLLIE:
                return new Mollie();
            case self::PROVIDER_COINBASE:
                return new Coinbase();
            default:
                throw new \Exception("Invalid payment provider: {$provider_or_wallet}");
        }
    }

    /**
     * Create a new auto-payment mandate for a wallet.
     *
     * @param Wallet $wallet  The wallet
     * @param array  $payment Payment data:
     *                        - amount: Value in cents (wallet currency)
     *                        - credit_amount: Balance'able base amount in cents (wallet currency)
     *                        - vat_rate_id: VAT rate id
     *                        - currency: The operation currency
     *                        - description: Operation desc.
     *                        - methodId: Payment method
     *                        - redirectUrl: The location to goto after checkout
     *
     * @return array Provider payment data:
     *               - id: Operation identifier
     *               - redirectUrl: the location to redirect to
     */
    abstract public function createMandate(Wallet $wallet, array $payment): ?array;

    /**
     * Revoke the auto-payment mandate for a wallet.
     *
     * @param Wallet $wallet The wallet
     *
     * @return bool True on success, False on failure
     */
    abstract public function deleteMandate(Wallet $wallet): bool;

    /**
     * Get a auto-payment mandate for a wallet.
     *
     * @param Wallet $wallet The wallet
     *
     * @return array|null Mandate information:
     *                    - id: Mandate identifier
     *                    - method: user-friendly payment method desc.
     *                    - methodId: Payment method
     *                    - isPending: the process didn't complete yet
     *                    - isValid: the mandate is valid
     */
    abstract public function getMandate(Wallet $wallet): ?array;

    /**
     * Get a link to the customer in the provider's control panel
     *
     * @param Wallet $wallet The wallet
     *
     * @return string|null The string representing <a> tag
     */
    abstract public function customerLink(Wallet $wallet): ?string;

    /**
     * Get a provider name
     *
     * @return string Provider name
     */
    abstract public function name(): string;

    /**
     * Create a new payment.
     *
     * @param Wallet $wallet  The wallet
     * @param array  $payment Payment data:
     *                        - amount: Value in cents (wallet currency)
     *                        - credit_amount: Balance'able base amount in cents (wallet currency)
     *                        - vat_rate_id: Vat rate id
     *                        - currency: The operation currency
     *                        - type: first/oneoff/recurring
     *                        - description: Operation description
     *                        - methodId: Payment method
     *
     * @return array Provider payment/session data:
     *               - id: Operation identifier
     *               - redirectUrl
     */
    abstract public function payment(Wallet $wallet, array $payment): ?array;

    /**
     * Update payment status (and balance).
     *
     * @return int HTTP response code
     */
    abstract public function webhook(): int;

    /**
     * Create a payment record in DB
     *
     * @param array  $payment   Payment information
     * @param string $wallet_id Wallet ID
     *
     * @return Payment Payment object
     */
    protected function storePayment(array $payment, $wallet_id): Payment
    {
        $payment['wallet_id'] = $wallet_id;
        $payment['provider'] = $this->name();

        return Payment::createFromArray($payment);
    }

    /**
     * Convert a value from $sourceCurrency to $targetCurrency
     *
     * @param int    $amount         Amount in cents of $sourceCurrency
     * @param string $sourceCurrency Currency from which to convert
     * @param string $targetCurrency Currency to convert to
     *
     * @return int Exchanged amount in cents of $targetCurrency
     */
    protected function exchange(int $amount, string $sourceCurrency, string $targetCurrency): int
    {
        return (int) round($amount * Utils::exchangeRate($sourceCurrency, $targetCurrency));
    }

    /**
     * List supported payment methods from this provider
     *
     * @param string $type     the payment type for which we require a method (oneoff/recurring)
     * @param string $currency Currency code
     *
     * @return array Array of array with available payment methods:
     *               - id: id of the method
     *               - name: User readable name of the payment method
     *               - minimumAmount: Minimum amount to be charged in cents
     *               - currency: Currency used for the method
     *               - exchangeRate: The projected exchange rate (actual rate is determined during payment)
     *               - icon: An icon (icon name) representing the method
     */
    abstract public function providerPaymentMethods(string $type, string $currency): array;

    /**
     * Get a payment.
     *
     * @param string $paymentId Payment identifier
     *
     * @return array Payment information:
     *               - id: Payment identifier
     *               - status: Payment status
     *               - isCancelable: The payment can be canceled
     *               - checkoutUrl: The checkout url to complete the payment or null if none
     */
    abstract public function getPayment($paymentId): array;

    /**
     * Return an array of whitelisted payment methods with override values.
     *
     * @param string $type the payment type for which we require a method
     *
     * @return array Array of methods
     */
    protected static function paymentMethodsWhitelist($type): array
    {
        $methods = [];
        switch ($type) {
            case Payment::TYPE_ONEOFF:
                $methods = explode(',', \config('app.payment.methods_oneoff'));
                break;
            case Payment::TYPE_RECURRING:
                $methods = explode(',', \config('app.payment.methods_recurring'));
                break;
            default:
                \Log::error("Unknown payment type: " . $type);
        }
        $methods = array_map('strtolower', array_map('trim', $methods));
        return $methods;
    }

    /**
     * Return an array of whitelisted payment methods with override values.
     *
     * @param string $type the payment type for which we require a method
     *
     * @return array<array> Array of methods
     */
    private static function applyMethodWhitelist($type, $availableMethods): array
    {
        $methods = [];

        // Use only whitelisted methods, and apply values from whitelist (overriding the backend)
        $whitelistMethods = self::paymentMethodsWhitelist($type);
        foreach ($whitelistMethods as $id) {
            if (array_key_exists($id, $availableMethods)) {
                $method = $availableMethods[$id];
                $method['icon'] = self::$paymentMethodIcons[$id];
                $methods[] = $method;
            }
        }

        return $methods;
    }

    /**
     * List supported payment methods for $wallet
     *
     * @param Wallet $wallet The wallet
     * @param string $type   The payment type for which we require a method (oneoff/recurring)
     *
     * @return array<array{
     *   // id of the method
     *   id: string,
     *   // User readable name of the payment method
     *   name: string,
     *   // Minimum amount to be charged in cents
     *   minimumAmount: float,
     *   // Currency used for the method
     *   currency: string,
     *    // The projected exchange rate (actual rate is determined during payment)
     *   exchangeRate: float,
     *   // An icon (icon name) representing the method
     *   icon: array
     * }>
     */
    public static function paymentMethods(Wallet $wallet, $type): array
    {
        $providerName = self::providerName($wallet);

        $cacheKey = "methods-{$providerName}-{$type}-{$wallet->currency}";

        if ($methods = Cache::get($cacheKey)) {
            \Log::debug("Using payment method cache" . var_export($methods, true));
            return $methods;
        }

        $provider = self::factory($providerName);
        $methods = $provider->providerPaymentMethods($type, $wallet->currency);

        if (!empty(\config('services.coinbase.key'))) {
            $coinbaseProvider = self::factory(self::PROVIDER_COINBASE);
            $methods = array_merge($methods, $coinbaseProvider->providerPaymentMethods($type, $wallet->currency));
        }
        $methods = self::applyMethodWhitelist($type, $methods);

        \Log::debug("Loaded payment methods " . var_export($methods, true));

        Cache::put($cacheKey, $methods, now()->addHours(1));

        return $methods;
    }

    /**
     * Returns the full URL for the wallet page, used when returning from an external payment page.
     * Depending on the request origin it will return a URL for the User or Reseller UI.
     *
     * @return string The redirect URL
     */
    public static function redirectUrl(): string
    {
        $url = Utils::serviceUrl('/wallet');
        $domain = \request()->host();

        if (str_starts_with($domain, 'reseller.')) {
            $url = preg_replace('|^(https?://)([^/]+)|', '\1' . $domain, $url);
        }

        return $url;
    }
}
