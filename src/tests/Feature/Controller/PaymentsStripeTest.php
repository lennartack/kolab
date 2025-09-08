<?php

namespace Tests\Feature\Controller;

use App\Jobs\Mail\PaymentJob;
use App\Jobs\Mail\PaymentMandateDisabledJob;
use App\Jobs\Wallet\ChargeJob;
use App\Payment;
use App\Providers\PaymentProvider;
use App\Transaction;
use App\VatRate;
use App\Wallet;
use App\WalletSetting;
use Illuminate\Support\Facades\Bus;
use Tests\StripeMocksTrait;
use Tests\TestCase;

class PaymentsStripeTest extends TestCase
{
    use StripeMocksTrait;

    protected function setUp(): void
    {
        parent::setUp();

        if (!\config('services.stripe.key')) {
            $this->markTestSkipped('No STRIPE_KEY');
        }

        // All tests in this file use Stripe
        \config(['services.payment_provider' => 'stripe']);
        \config(['app.vat.mode' => 0]);

        $this->deleteTestUser('payment-test@' . \config('app.domain'));

        $john = $this->getTestUser('john@kolab.org');
        $wallet = $john->wallets()->first();
        Wallet::where('id', $wallet->id)->update(['balance' => 0]);
        WalletSetting::where('wallet_id', $wallet->id)->delete();
        Transaction::where('object_id', $wallet->id)
            ->where('type', Transaction::WALLET_CREDIT)->delete();
        Payment::query()->delete();
        VatRate::query()->delete();
    }

    protected function tearDown(): void
    {
        if (\config('services.stripe.key')) {
            $this->deleteTestUser('payment-test@' . \config('app.domain'));

            $john = $this->getTestUser('john@kolab.org');
            $wallet = $john->wallets()->first();
            Wallet::where('id', $wallet->id)->update(['balance' => 0]);
            WalletSetting::where('wallet_id', $wallet->id)->delete();
            Transaction::where('object_id', $wallet->id)
                ->where('type', Transaction::WALLET_CREDIT)->delete();
            Payment::query()->delete();
            VatRate::query()->delete();
        }

        parent::tearDown();
    }

    /**
     * Test creating/updating/deleting an outo-payment mandate
     *
     * @group stripe
     */
    public function testMandates(): void
    {
        Bus::fake();

        // Unauth access not allowed
        $response = $this->get("api/v4/payments/mandate");
        $response->assertStatus(401);
        $response = $this->post("api/v4/payments/mandate", []);
        $response->assertStatus(401);
        $response = $this->post("api/v4/payments/mandate/reset", []);
        $response->assertStatus(401);
        $response = $this->put("api/v4/payments/mandate", []);
        $response->assertStatus(401);
        $response = $this->delete("api/v4/payments/mandate");
        $response->assertStatus(401);

        $user = $this->getTestUser('john@kolab.org');
        $wallet = $user->wallets()->first();

        // Test creating a mandate (invalid input)
        $post = [];
        $response = $this->actingAs($user)->post("api/v4/payments/mandate", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertCount(2, $json['errors']);
        $this->assertSame('The amount field is required.', $json['errors']['amount'][0]);
        $this->assertSame('The balance field is required.', $json['errors']['balance'][0]);

        // Test creating a mandate (invalid input)
        $post = ['amount' => 100, 'balance' => 'a'];
        $response = $this->actingAs($user)->post("api/v4/payments/mandate", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertCount(1, $json['errors']);
        $this->assertSame('The balance must be a number.', $json['errors']['balance'][0]);

        // Test creating a mandate (invalid input)
        $post = ['amount' => -100, 'balance' => 0];
        $response = $this->actingAs($user)->post("api/v4/payments/mandate", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertCount(1, $json['errors']);
        $min = $wallet->money(Payment::MIN_AMOUNT);
        $this->assertSame("Minimum amount for a single payment is {$min}.", $json['errors']['amount']);

        // Test creating a mandate (negative balance, amount too small)
        Wallet::where('id', $wallet->id)->update(['balance' => -2000]);
        $post = ['amount' => Payment::MIN_AMOUNT / 100, 'balance' => 0];
        $response = $this->actingAs($user)->post("api/v4/payments/mandate", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertCount(1, $json['errors']);
        $this->assertSame("The specified amount does not cover the balance on the account.", $json['errors']['amount']);

        // Test creating a mandate (valid input)
        $post = ['amount' => 20.10, 'balance' => 0, 'methodId' => PaymentProvider::METHOD_CREDITCARD];
        $response = $this->actingAs($user)->post("api/v4/payments/mandate", $post);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertMatchesRegularExpression('|^cs_test_|', $json['id']);

        // Assert the proper payment amount has been used
        // Stripe in 'setup' mode does not allow to set the amount
        $payment = $wallet->payments()->first();
        $this->assertSame(0, $payment->amount);
        $this->assertSame($user->tenant->title . " Auto-Payment Setup", $payment->description);
        $this->assertSame(Payment::TYPE_MANDATE, $payment->type);

        // Test fetching the mandate information
        $response = $this->actingAs($user)->get("api/v4/payments/mandate");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('20.1', $json['amount']);
        $this->assertSame('0', $json['balance']);
        $this->assertFalse($json['isDisabled']);

        // We would have to invoke a browser to accept the "first payment" to make
        // the mandate validated/completed. Instead, we'll mock the mandate object.
        $setupIntent = '{
            "id": "AAA",
            "object": "setup_intent",
            "created": 123456789,
            "payment_method": "pm_YYY",
            "status": "succeeded",
            "usage": "off_session",
            "customer": null
        }';

        $paymentMethod = '{
            "id": "pm_YYY",
            "object": "payment_method",
            "card": {
                "brand": "visa",
                "country": "US",
                "last4": "4242"
            },
            "created": 123456789,
            "type": "card"
        }';

        $client = $this->mockStripe();
        $client->addResponse($setupIntent);
        $client->addResponse($paymentMethod);

        // As we do not use checkout page, we do not receive a webworker request
        // I.e. we have to fake the mandate id
        $wallet = $user->wallets()->first();
        $wallet->setSetting('stripe_mandate_id', 'AAA');
        $wallet->setSetting('mandate_disabled', 1);

        $response = $this->actingAs($user)->get("api/v4/payments/mandate");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('20.1', $json['amount']);
        $this->assertSame('0', $json['balance']);
        $this->assertSame('Visa (**** **** **** 4242)', $json['method']);
        $this->assertFalse($json['isPending']);
        $this->assertTrue($json['isValid']);
        $this->assertTrue($json['isDisabled']);

        // Test updating mandate details (invalid input)
        $wallet->setSetting('mandate_disabled', null);
        $wallet->balance = 1000;
        $wallet->save();
        $user->refresh();
        $post = [];
        $response = $this->actingAs($user)->put("api/v4/payments/mandate", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertCount(2, $json['errors']);
        $this->assertSame('The amount field is required.', $json['errors']['amount'][0]);
        $this->assertSame('The balance field is required.', $json['errors']['balance'][0]);

        $post = ['amount' => -100, 'balance' => 0];
        $response = $this->actingAs($user)->put("api/v4/payments/mandate", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertCount(1, $json['errors']);
        $this->assertSame("Minimum amount for a single payment is {$min}.", $json['errors']['amount']);

        // Test updating a mandate (valid input)
        $client->addResponse($setupIntent);
        $client->addResponse($paymentMethod);

        $post = ['amount' => 30.10, 'balance' => 10];
        $response = $this->actingAs($user)->put("api/v4/payments/mandate", $post);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertSame('The auto-payment has been updated.', $json['message']);
        $this->assertSame('30.1', $wallet->getSetting('mandate_amount'));
        $this->assertSame('10', $wallet->getSetting('mandate_balance'));
        $this->assertSame('AAA', $json['mandate']['id']);
        $this->assertFalse($json['mandate']['isDisabled']);

        // Test updating a disabled mandate (invalid input)
        $wallet->setSetting('mandate_disabled', 1);
        $wallet->balance = -2000;
        $wallet->save();
        $user->refresh(); // required so the controller sees the wallet update from above

        $post = ['amount' => 15.10, 'balance' => 1];
        $response = $this->actingAs($user)->put("api/v4/payments/mandate", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertCount(1, $json['errors']);
        $this->assertSame('The specified amount does not cover the balance on the account.', $json['errors']['amount']);

        // Test updating a disabled mandate (valid input)
        $client->addResponse($setupIntent);
        $client->addResponse($paymentMethod);

        $post = ['amount' => 30, 'balance' => 1];
        $response = $this->actingAs($user)->put("api/v4/payments/mandate", $post);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertSame('The auto-payment has been updated.', $json['message']);
        $this->assertSame('AAA', $json['mandate']['id']);
        $this->assertFalse($json['mandate']['isDisabled']);

        Bus::assertDispatchedTimes(ChargeJob::class, 1);
        Bus::assertDispatched(ChargeJob::class, function ($job) use ($wallet) {
            $job_wallet_id = $this->getObjectProperty($job, 'walletId');
            return $job_wallet_id === $wallet->id;
        });

        $this->unmockStripe();

        // Test mandate reset
        $wallet->payments()->delete();
        $response = $this->actingAs($user)->post("api/v4/payments/mandate/reset", []);
        $response->assertStatus(200);

        $payment = $wallet->payments()->first();
        $this->assertSame(0, $payment->amount);
        $this->assertSame($user->tenant->title . " Auto-Payment Setup", $payment->description);
        $this->assertSame(Payment::TYPE_MANDATE, $payment->type);

        // Delete mandate
        $wallet->setSetting('mandate_disabled', 1);
        $client = $this->mockStripe();
        $client->addResponse($setupIntent);
        $client->addResponse($paymentMethod);
        $client->addResponse($paymentMethod);

        $response = $this->actingAs($user)->delete("api/v4/payments/mandate");
        $response->assertStatus(200);

        $this->assertNull($wallet->getSetting('mandate_disabled'));
        $this->assertNull($wallet->getSetting('stripe_mandate_id'));

        $this->unmockStripe();
    }

    /**
     * Test creating a payment and receiving a status via webhook
     *
     * @group stripe
     */
    public function testStoreAndWebhook(): void
    {
        Bus::fake();

        // Unauth access not allowed
        $response = $this->post("api/v4/payments", []);
        $response->assertStatus(401);

        $user = $this->getTestUser('john@kolab.org');
        $wallet = $user->wallets()->first();

        $post = ['amount' => -1];
        $response = $this->actingAs($user)->post("api/v4/payments", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertCount(1, $json['errors']);
        $min = $wallet->money(Payment::MIN_AMOUNT);
        $this->assertSame("Minimum amount for a single payment is {$min}.", $json['errors']['amount']);

        // Invalid currency
        $post = ['amount' => '12.34', 'currency' => 'FOO', 'methodId' => 'creditcard'];
        $response = $this->actingAs($user)->post("api/v4/payments", $post);
        $response->assertStatus(500);

        // Successful payment
        $post = ['amount' => '12.34', 'currency' => 'CHF', 'methodId' => 'creditcard'];
        $response = $this->actingAs($user)->post("api/v4/payments", $post);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertMatchesRegularExpression('|^cs_test_|', $json['id']);

        $payments = Payment::where('wallet_id', $wallet->id)->get();

        $this->assertCount(1, $payments);
        $payment = $payments[0];
        $this->assertSame(1234, $payment->amount);
        $this->assertSame($user->tenant->title . ' Payment', $payment->description);
        $this->assertSame('open', $payment->status);
        $this->assertSame(0, $wallet->balance);

        // Test the webhook

        $post = [
            'id' => "evt_1GlZ814fj3SIEU8wtxMZ4Nsa",
            'object' => "event",
            'api_version' => "2020-03-02",
            'created' => 1590147209,
            'data' => [
                'object' => [
                    'id' => $payment->id,
                    'object' => "payment_intent",
                    'amount' => 1234,
                    'amount_capturable' => 0,
                    'amount_received' => 1234,
                    'capture_method' => "automatic",
                    'client_secret' => "pi_1GlZ7w4fj3SIEU8w1RlBpN4l_secret_UYRNDTUUU7nkYHpOLZMb3uf48",
                    'confirmation_method' => "automatic",
                    'created' => 1590147204,
                    'currency' => "chf",
                    'customer' => "cus_HKDZ53OsKdlM83",
                    'last_payment_error' => null,
                    'livemode' => false,
                    'metadata' => [],
                    'receipt_email' => "payment-test@kolabnow.com",
                    'status' => "succeeded",
                ],
            ],
            'type' => "payment_intent.succeeded",
        ];

        // Test payment succeeded event
        $response = $this->webhookRequest($post);
        $response->assertStatus(200);

        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
        $this->assertSame(1234, $wallet->fresh()->balance);

        $transaction = $wallet->transactions()
            ->where('type', Transaction::WALLET_CREDIT)->get()->last();

        $this->assertSame(1234, $transaction->amount);
        $this->assertSame(
            "Payment transaction {$payment->id} using Stripe",
            $transaction->description
        );

        // Assert that email notification job wasn't dispatched,
        // it is expected only for recurring payments
        Bus::assertDispatchedTimes(PaymentJob::class, 0);

        // Test that balance didn't change if the same event is posted
        $response = $this->webhookRequest($post);
        $response->assertStatus(200);

        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
        $this->assertSame(1234, $wallet->fresh()->balance);

        // Test for payment failure ('failed' status)
        $payment->refresh();
        $payment->status = Payment::STATUS_OPEN;
        $payment->save();

        $post['type'] = "payment_intent.payment_failed";
        $post['data']['object']['status'] = 'failed';

        $response = $this->webhookRequest($post);
        $response->assertStatus(200);

        $this->assertSame(Payment::STATUS_FAILED, $payment->fresh()->status);
        $this->assertSame(1234, $wallet->fresh()->balance);

        // Assert that email notification job wasn't dispatched,
        // it is expected only for recurring payments
        Bus::assertDispatchedTimes(PaymentJob::class, 0);

        // Test for payment failure ('canceled' status)
        $payment->refresh();
        $payment->status = Payment::STATUS_OPEN;
        $payment->save();

        $post['type'] = "payment_intent.canceled";
        $post['data']['object']['status'] = 'canceled';

        $response = $this->webhookRequest($post);
        $response->assertStatus(200);

        $this->assertSame(Payment::STATUS_CANCELED, $payment->fresh()->status);
        $this->assertSame(1234, $wallet->fresh()->balance);

        // Assert that email notification job wasn't dispatched,
        // it is expected only for recurring payments
        Bus::assertDispatchedTimes(PaymentJob::class, 0);
    }

    /**
     * Test receiving webhook request for setup intent
     *
     * @group stripe
     */
    public function testCreateMandateAndWebhook(): void
    {
        $user = $this->getTestUser('john@kolab.org');
        $wallet = $user->wallets()->first();
        Wallet::where('id', $wallet->id)->update(['balance' => -1000]);

        // Test creating a mandate (valid input)
        $post = ['amount' => 20.10, 'balance' => 0];
        $response = $this->actingAs($user)->post("api/v4/payments/mandate", $post);
        $response->assertStatus(200);

        $payment = $wallet->payments()->first();

        $this->assertSame(Payment::STATUS_OPEN, $payment->status);
        $this->assertSame(Payment::TYPE_MANDATE, $payment->type);
        $this->assertSame(0, $payment->amount);

        $post = [
            'id' => "evt_1GlZ814fj3SIEU8wtxMZ4Nsa",
            'object' => "event",
            'api_version' => "2020-03-02",
            'created' => 1590147209,
            'data' => [
                'object' => [
                    'id' => $payment->id,
                    'object' => "setup_intent",
                    'client_secret' => "pi_1GlZ7w4fj3SIEU8w1RlBpN4l_secret_UYRNDTUUU7nkYHpOLZMb3uf48",
                    'created' => 1590147204,
                    'customer' => "cus_HKDZ53OsKdlM83",
                    'last_setup_error' => null,
                    'metadata' => [],
                    'status' => "succeeded",
                ],
            ],
            'type' => "setup_intent.succeeded",
        ];

        Bus::fake();

        // Test payment succeeded event
        $response = $this->webhookRequest($post);
        $response->assertStatus(200);

        $payment->refresh();

        $this->assertSame(Payment::STATUS_PAID, $payment->status);
        $this->assertSame($payment->id, $wallet->fresh()->getSetting('stripe_mandate_id'));

        // Expect a wallet charge job if the balance is negative
        Bus::assertDispatchedTimes(ChargeJob::class, 1);
        Bus::assertDispatched(ChargeJob::class, static function ($job) use ($wallet) {
            $job_wallet_id = TestCase::getObjectProperty($job, 'walletId');
            return $job_wallet_id === $wallet->id;
        });

        // TODO: test other setup_intent.* events
    }

    /**
     * Test automatic payment charges
     *
     * @group stripe
     */
    public function testTopUpAndWebhook(): void
    {
        Bus::fake();

        $user = $this->getTestUser('john@kolab.org');
        $wallet = $user->wallets()->first();

        // Stripe API does not allow us to create a mandate easily
        // That's why we we'll mock API responses
        // Create a fake mandate
        $wallet->setSettings([
            'mandate_amount' => 20.10,
            'mandate_balance' => 10,
            'stripe_mandate_id' => 'AAA',
        ]);

        $setupIntent = json_encode([
            "id" => "AAA",
            "object" => "setup_intent",
            "created" => 123456789,
            "payment_method" => "pm_YYY",
            "status" => "succeeded",
            "usage" => "off_session",
            "customer" => null,
        ]);

        $paymentMethod = json_encode([
            "id" => "pm_YYY",
            "object" => "payment_method",
            "card" => [
                "brand" => "visa",
                "country" => "US",
                "last4" => "4242",
            ],
            "created" => 123456789,
            "type" => "card",
        ]);

        $paymentIntent = json_encode([
            "id" => "pi_XX",
            "object" => "payment_intent",
            "created" => 123456789,
            "amount" => 2010,
            "currency" => "chf",
            "description" => $user->tenant->title . " Recurring Payment",
        ]);

        $client = $this->mockStripe();
        $client->addResponse($setupIntent);
        $client->addResponse($paymentMethod);
        $client->addResponse($setupIntent);
        $client->addResponse($paymentIntent);
        $client->addResponse($setupIntent);
        $client->addResponse($paymentMethod);

        // Expect a recurring payment as we have a valid mandate at this point
        $result = $wallet->topUp();
        $this->assertTrue($result);

        // Check that the payments table contains a new record with proper amount
        // There should be two records, one for the first payment and another for
        // the recurring payment
        $this->assertCount(1, $wallet->payments()->get());
        $payment = $wallet->payments()->first();
        $this->assertSame(2010, $payment->amount);
        $this->assertSame($user->tenant->title . " Recurring Payment", $payment->description);
        $this->assertSame("pi_XX", $payment->id);

        // Expect no payment if the mandate is disabled
        $wallet->setSetting('mandate_disabled', 1);
        $result = $wallet->topUp();
        $this->assertFalse($result);
        $this->assertCount(1, $wallet->payments()->get());

        // Expect no payment if balance is ok
        $wallet->setSetting('mandate_disabled', null);
        $wallet->balance = 1000;
        $wallet->save();
        $result = $wallet->topUp();
        $this->assertFalse($result);
        $this->assertCount(1, $wallet->payments()->get());

        // Expect no payment if the top-up amount is not enough
        $wallet->setSetting('mandate_disabled', null);
        $wallet->balance = -2050;
        $wallet->save();

        $result = $wallet->topUp();
        $this->assertFalse($result);
        $this->assertCount(1, $wallet->payments()->get());

        Bus::assertDispatchedTimes(PaymentMandateDisabledJob::class, 1);
        Bus::assertDispatched(PaymentMandateDisabledJob::class, function ($job) use ($wallet) {
            $job_wallet = $this->getObjectProperty($job, 'wallet');
            return $job_wallet->id === $wallet->id;
        });

        // Expect no payment if there's no mandate
        $wallet->setSetting('mollie_mandate_id', null);
        $wallet->balance = 0;
        $wallet->save();
        $result = $wallet->topUp();
        $this->assertFalse($result);
        $this->assertCount(1, $wallet->payments()->get());

        Bus::assertDispatchedTimes(PaymentMandateDisabledJob::class, 1);

        $this->unmockStripe();

        // Test webhook

        $post = [
            'id' => "evt_1GlZ814fj3SIEU8wtxMZ4Nsa",
            'object' => "event",
            'api_version' => "2020-03-02",
            'created' => 1590147209,
            'data' => [
                'object' => [
                    'id' => $payment->id,
                    'object' => "payment_intent",
                    'amount' => 2010,
                    'capture_method' => "automatic",
                    'created' => 1590147204,
                    'currency' => "chf",
                    'customer' => "cus_HKDZ53OsKdlM83",
                    'last_payment_error' => null,
                    'metadata' => [],
                    'receipt_email' => "payment-test@kolabnow.com",
                    'status' => "succeeded",
                ],
            ],
            'type' => "payment_intent.succeeded",
        ];

        // Test payment succeeded event
        $response = $this->webhookRequest($post);
        $response->assertStatus(200);

        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
        $this->assertSame(2010, $wallet->fresh()->balance);
        $transaction = $wallet->transactions()
            ->where('type', Transaction::WALLET_CREDIT)->get()->last();

        $this->assertSame(2010, $transaction->amount);
        $this->assertSame(
            "Auto-payment transaction {$payment->id} using Stripe",
            $transaction->description
        );

        // Assert that email notification job has been dispatched
        Bus::assertDispatchedTimes(PaymentJob::class, 1);
        Bus::assertDispatched(PaymentJob::class, function ($job) use ($payment) {
            $job_payment = $this->getObjectProperty($job, 'payment');
            return $job_payment->id === $payment->id;
        });

        Bus::fake();

        // Test for payment failure ('failed' status)
        $payment->refresh();
        $payment->status = Payment::STATUS_OPEN;
        $payment->save();

        $wallet->setSetting('mandate_disabled', null);

        $post['type'] = "payment_intent.payment_failed";
        $post['data']['object']['status'] = 'failed';

        $response = $this->webhookRequest($post);
        $response->assertStatus(200);

        $wallet->refresh();

        $this->assertSame(Payment::STATUS_FAILED, $payment->fresh()->status);
        $this->assertSame(2010, $wallet->balance);
        $this->assertTrue(!empty($wallet->getSetting('mandate_disabled')));

        // Assert that email notification job has been dispatched
        Bus::assertDispatchedTimes(PaymentJob::class, 1);
        Bus::assertDispatched(PaymentJob::class, function ($job) use ($payment) {
            $job_payment = $this->getObjectProperty($job, 'payment');
            return $job_payment->id === $payment->id;
        });

        Bus::fake();

        // Test for payment failure ('canceled' status)
        $payment->refresh();
        $payment->status = Payment::STATUS_OPEN;
        $payment->save();

        $post['type'] = "payment_intent.canceled";
        $post['data']['object']['status'] = 'canceled';

        $response = $this->webhookRequest($post);
        $response->assertStatus(200);

        $this->assertSame(Payment::STATUS_CANCELED, $payment->fresh()->status);
        $this->assertSame(2010, $wallet->fresh()->balance);

        // Assert that email notification job wasn't dispatched,
        // it is expected only for recurring payments
        Bus::assertDispatchedTimes(PaymentJob::class, 0);
    }

    /**
     * Test payment/top-up with VAT_MODE=1
     *
     * @group stripe
     */
    public function testPaymentsWithVatModeOne(): void
    {
        \config(['app.vat.mode' => 1]);

        $user = $this->getTestUser('payment-test@' . \config('app.domain'));
        $user->setSetting('country', 'US');
        $wallet = $user->wallets()->first();
        $vatRate = VatRate::create([
            'country' => 'US',
            'rate' => 5.0,
            'start' => now()->subDay(),
        ]);

        // Payment
        $post = ['amount' => '10', 'currency' => 'CHF', 'methodId' => 'creditcard'];
        $response = $this->actingAs($user)->post("api/v4/payments", $post);
        $response->assertStatus(200);

        // Check that the payments table contains a new record with proper amount(s)
        $payment = $wallet->payments()->first();
        $this->assertSame(1000 + (int) round(1000 * $vatRate->rate / 100), $payment->amount);
        $this->assertSame(1000, $payment->credit_amount);
        $this->assertSame($payment->amount, $payment->currency_amount);
        $this->assertSame('CHF', $payment->currency);
        $this->assertSame($vatRate->id, $payment->vat_rate_id);
        $this->assertSame('open', $payment->status);

        $wallet->payments()->delete();
        $wallet->balance = -1000;
        $wallet->save();

        // Top-up (mandate creation)
        // Create a valid mandate first (expect an extra payment)
        $post = ['amount' => 20.10, 'balance' => 0, 'methodId' => PaymentProvider::METHOD_CREDITCARD];
        $response = $this->actingAs($user)->post("api/v4/payments/mandate", $post);
        $response->assertStatus(200);

        // Check that the payments table contains a new record with proper amount(s)
        // Stripe mandates always use amount=0
        $payment = $wallet->payments()->first();
        $this->assertSame(0, $payment->amount);
        $this->assertSame(0, $payment->credit_amount);
        $this->assertSame(0, $payment->currency_amount);
        $this->assertNull($payment->vat_rate_id);

        $wallet->payments()->delete();
        $wallet->balance = -1000;
        $wallet->save();

        // Top-up (recurring payment)
        // Expect a recurring payment as we have a valid mandate at this point
        // and the balance is below the threshold
        $wallet->setSettings(['stripe_mandate_id' => 'AAA']);
        $setupIntent = json_encode([
            "id" => "AAA",
            "object" => "setup_intent",
            "created" => 123456789,
            "payment_method" => "pm_YYY",
            "status" => "succeeded",
            "usage" => "off_session",
            "customer" => null,
        ]);

        $paymentMethod = json_encode([
            "id" => "pm_YYY",
            "object" => "payment_method",
            "card" => [
                "brand" => "visa",
                "country" => "US",
                "last4" => "4242",
            ],
            "created" => 123456789,
            "type" => "card",
        ]);

        $paymentIntent = json_encode([
            "id" => "pi_XX",
            "object" => "payment_intent",
            "created" => 123456789,
            "amount" => 2010 + (int) round(2010 * $vatRate->rate / 100),
            "currency" => "chf",
            "description" => "Recurring Payment",
        ]);

        $client = $this->mockStripe();
        $client->addResponse($setupIntent);
        $client->addResponse($paymentMethod);
        $client->addResponse($setupIntent);
        $client->addResponse($paymentIntent);

        $result = $wallet->topUp();
        $this->assertTrue($result);

        // Check that the payments table contains a new record with proper amount(s)
        $payment = $wallet->payments()->first();
        $this->assertSame(2010 + (int) round(2010 * $vatRate->rate / 100), $payment->amount);
        $this->assertSame(2010, $payment->credit_amount);
        $this->assertSame($payment->amount, $payment->currency_amount);
        $this->assertSame($vatRate->id, $payment->vat_rate_id);
    }

    /**
     * Test listing payment methods
     *
     * @group stripe
     */
    public function testListingPaymentMethods(): void
    {
        Bus::fake();

        $user = $this->getTestUser('john@kolab.org');

        $response = $this->actingAs($user)->get('api/v4/payments/methods?type=' . Payment::TYPE_ONEOFF);
        $response->assertStatus(200);
        $json = $response->json();

        $hasCoinbase = !empty(\config('services.coinbase.key'));

        $this->assertCount(2 + (int) $hasCoinbase, $json);
        $this->assertSame('creditcard', $json[0]['id']);
        $this->assertSame('paypal', $json[1]['id']);
        if ($hasCoinbase) {
            $this->assertSame('bitcoin', $json[2]['id']);
        }

        $response = $this->actingAs($user)->get('api/v4/payments/methods?type=' . Payment::TYPE_RECURRING);
        $response->assertStatus(200);
        $json = $response->json();

        $this->assertCount(1, $json);
        $this->assertSame('creditcard', $json[0]['id']);
    }

    /**
     * Generate Stripe-Signature header for a webhook payload
     */
    protected function webhookRequest($post)
    {
        $secret = \config('services.stripe.webhook_secret');
        $ts = time();

        $payload = "{$ts}." . json_encode($post);
        $sig = sprintf('t=%d,v1=%s', $ts, \hash_hmac('sha256', $payload, $secret));

        return $this->withHeaders(['Stripe-Signature' => $sig])
            ->json('POST', "api/webhooks/payment/stripe", $post);
    }
}
