<?php

namespace Tests\Feature\Controller;

use App\Package;
use App\Payment;
use App\ReferralProgram;
use App\Sku;
use App\Transaction;
use Carbon\Carbon;
use Tests\TestCase;

class WalletsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteTestUser('wallets-controller@kolabnow.com');
        $this->deleteTestUser('jane@kolabnow.com');
        ReferralProgram::query()->delete();
    }

    protected function tearDown(): void
    {
        $this->deleteTestUser('wallets-controller@kolabnow.com');
        $this->deleteTestUser('jane@kolabnow.com');
        ReferralProgram::query()->delete();

        parent::tearDown();
    }

    /**
     * Test adding a wallet controller
     */
    public function testControllerAdd(): void
    {
        $user = $this->getTestUser('wallets-controller@kolabnow.com');
        $jane = $this->getTestUser('jane@kolabnow.com');
        $wallet = $user->wallets()->first();
        $janes_wallet = $jane->wallets()->first();

        // Unauth access not allowed
        $response = $this->post("api/v4/wallets/{$wallet->id}/controllers/{$jane->id}");
        $response->assertStatus(401);

        // Unknown wallet or user
        $response = $this->actingAs($user)->post("api/v4/wallets/{$wallet->id}/controllers/123");
        $response->assertStatus(404);
        $response = $this->actingAs($user)->post("api/v4/wallets/123/controllers/{$jane->id}");
        $response->assertStatus(404);

        // Other user's wallet
        $response = $this->actingAs($user)->post("api/v4/wallets/{$janes_wallet->id}/controllers/{$jane->id}");
        $response->assertStatus(403);

        // Wallet owner can't make himself a controller
        $response = $this->actingAs($user)->post("api/v4/wallets/{$wallet->id}/controllers/{$user->id}");
        $response->assertStatus(403);

        // Target user is not part of the same account
        $response = $this->actingAs($user)->post("api/v4/wallets/{$wallet->id}/controllers/{$jane->id}");
        $response->assertStatus(403);

        // Valid user
        $sku = Sku::withObjectTenantContext($user)->where(['title' => 'storage'])->first();
        $jane->assignSku($sku, 1, $wallet);

        $response = $this->actingAs($user)->post("api/v4/wallets/{$wallet->id}/controllers/{$jane->id}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertSame('Account controller role set successfully.', $json['message']);
        $wallet->refresh();
        $this->assertTrue($wallet->isController($jane));
        $this->assertSame(1, $wallet->controllers()->count());

        // Controller already assigned
        $response = $this->actingAs($user)->post("api/v4/wallets/{$wallet->id}/controllers/{$jane->id}");
        $response->assertStatus(200);

        $wallet->refresh();
        $this->assertTrue($wallet->isController($jane));
        $this->assertSame(1, $wallet->controllers()->count());
    }

    /**
     * Test deleting a wallet controller
     */
    public function testControllerDelete(): void
    {
        $user = $this->getTestUser('wallets-controller@kolabnow.com');
        $jane = $this->getTestUser('jane@kolabnow.com');
        $wallet = $user->wallets()->first();
        $janes_wallet = $jane->wallets()->first();

        // Unauth access not allowed
        $response = $this->delete("api/v4/wallets/{$wallet->id}/controllers/{$jane->id}");
        $response->assertStatus(401);

        // Unknown wallet or user
        $response = $this->actingAs($user)->delete("api/v4/wallets/{$wallet->id}/controllers/123");
        $response->assertStatus(404);
        $response = $this->actingAs($user)->delete("api/v4/wallets/123/controllers/{$jane->id}");
        $response->assertStatus(404);

        // Other user's wallet
        $response = $this->actingAs($user)->delete("api/v4/wallets/{$janes_wallet->id}/controllers/{$jane->id}");
        $response->assertStatus(403);

        // Wallet owner can't remove himself
        $response = $this->actingAs($user)->delete("api/v4/wallets/{$wallet->id}/controllers/{$user->id}");
        $response->assertStatus(403);

        // Target user is not the wallet controller
        $response = $this->actingAs($user)->delete("api/v4/wallets/{$wallet->id}/controllers/{$jane->id}");
        $response->assertStatus(404);

        $wallet->addController($jane);

        $response = $this->actingAs($user)->delete("api/v4/wallets/{$wallet->id}/controllers/{$jane->id}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertSame('Account controller role removed successfully.', $json['message']);
        $this->assertFalse($wallet->fresh()->isController($jane));
    }

    /**
     * Test fetching pdf receipt
     */
    public function testReceiptDownload(): void
    {
        $user = $this->getTestUser('wallets-controller@kolabnow.com');
        $john = $this->getTestUser('john@kolab.org');
        $wallet = $user->wallets()->first();

        // Unauth access not allowed
        $response = $this->get("api/v4/wallets/{$wallet->id}/receipts/2020-05");
        $response->assertStatus(401);
        $response = $this->actingAs($john)->get("api/v4/wallets/{$wallet->id}/receipts/2020-05");
        $response->assertStatus(403);

        // Invalid receipt id (current month)
        $receiptId = date('Y-m');
        $response = $this->actingAs($user)->get("api/v4/wallets/{$wallet->id}/receipts/{$receiptId}");
        $response->assertStatus(404);

        // Invalid receipt id
        $receiptId = '1000-03';
        $response = $this->actingAs($user)->get("api/v4/wallets/{$wallet->id}/receipts/{$receiptId}");
        $response->assertStatus(404);

        // Valid receipt id
        $year = (int) date('Y') - 1;
        $receiptId = "{$year}-12";
        $filename = \config('app.name') . " Receipt for {$year}-12.pdf";

        $response = $this->actingAs($user)->get("api/v4/wallets/{$wallet->id}/receipts/{$receiptId}");

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
        $response->assertHeader('content-disposition', 'attachment; filename="' . $filename . '"');
        $response->assertHeader('content-length');

        $length = (int) $response->headers->get('content-length');
        $content = $response->content();
        $this->assertStringStartsWith("%PDF-1.", $content);
        $this->assertSame(strlen($content), $length);
    }

    /**
     * Test fetching list of receipts
     */
    public function testReceipts(): void
    {
        $user = $this->getTestUser('wallets-controller@kolabnow.com');
        $john = $this->getTestUser('john@kolab.org');
        $wallet = $user->wallets()->first();
        $wallet->payments()->delete();

        // Unauth access not allowed
        $response = $this->get("api/v4/wallets/{$wallet->id}/receipts");
        $response->assertStatus(401);
        $response = $this->actingAs($john)->get("api/v4/wallets/{$wallet->id}/receipts");
        $response->assertStatus(403);

        // Empty list expected
        $response = $this->actingAs($user)->get("api/v4/wallets/{$wallet->id}/receipts");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(5, $json);
        $this->assertSame('success', $json['status']);
        $this->assertSame([], $json['list']);
        $this->assertSame(1, $json['page']);
        $this->assertSame(0, $json['count']);
        $this->assertFalse($json['hasMore']);

        // Insert a payment to the database
        $date = Carbon::create((int) date('Y') - 1, 4, 30);
        $payment = Payment::create([
            'id' => 'AAA1',
            'status' => Payment::STATUS_PAID,
            'type' => Payment::TYPE_ONEOFF,
            'description' => 'Paid in April',
            'wallet_id' => $wallet->id,
            'provider' => 'stripe',
            'amount' => 1111,
            'credit_amount' => 1111,
            'currency' => 'CHF',
            'currency_amount' => 1111,
        ]);
        $payment->updated_at = $date;
        $payment->save();

        $response = $this->actingAs($user)->get("api/v4/wallets/{$wallet->id}/receipts");
        $response->assertStatus(200);

        $json = $response->json();

        $expected = ['period' => $date->format('Y-m'), 'amount' => '1111', 'currency' => 'CHF'];
        $this->assertCount(5, $json);
        $this->assertSame('success', $json['status']);
        $this->assertSame($expected, $json['list'][0]);
        $this->assertSame(1, $json['page']);
        $this->assertSame(1, $json['count']);
        $this->assertFalse($json['hasMore']);
    }

    /**
     * Test fetching list of referral programs (GET /api/v4/wallets/<id>/referral-programs)
     */
    public function testReferralPrograms(): void
    {
        $user = $this->getTestUser('wallets-controller@kolabnow.com');
        $john = $this->getTestUser('john@kolab.org');
        $wallet = $user->wallets()->first();

        // Unauth access not allowed
        $this->get("api/v4/wallets/{$wallet->id}/referral-programs")->assertStatus(401);
        $this->actingAs($john)->get("api/v4/wallets/{$wallet->id}/referral-programs")->assertStatus(403);

        // Empty list expected
        $response = $this->actingAs($user)->get("api/v4/wallets/{$wallet->id}/referral-programs");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(5, $json);
        $this->assertSame('success', $json['status']);
        $this->assertSame([], $json['list']);
        $this->assertSame(1, $json['page']);
        $this->assertSame(0, $json['count']);
        $this->assertFalse($json['hasMore']);

        // Insert a test program
        $program = ReferralProgram::create([
            'name' => "Test Referral",
            'description' => "Test Referral Description",
            'active' => false,
        ]);

        // Empty list expected, no active program
        $this->actingAs($user)->get("api/v4/wallets/{$wallet->id}/referral-programs")
            ->assertStatus(200)
            ->assertJsonFragment(['list' => []]);

        // Activate the program
        $program->active = true;
        $program->save();

        $response = $this->actingAs($user)->get("api/v4/wallets/{$wallet->id}/referral-programs");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(5, $json);
        $this->assertSame('success', $json['status']);
        $this->assertSame(1, $json['page']);
        $this->assertSame(1, $json['count']);
        $this->assertFalse($json['hasMore']);
        $this->assertCount(1, $json['list']);
        $this->assertSame($program->id, $json['list'][0]['id']);
        $this->assertSame($program->name, $json['list'][0]['name']);
        $this->assertSame($program->description, $json['list'][0]['description']);
        $this->assertCount(1, $program->codes);
        $code = $program->codes->first();
        $this->assertStringContainsString("/signup/referral/{$code->code}", $json['list'][0]['url']);
        $this->assertSame(0, $json['list'][0]['refcount']);

        // Add some referrals
        $john = $this->getTestUser('john@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');
        $code->referrals()->createMany([
            ['user_id' => $john->id],
            ['user_id' => $jack->id],
        ]);

        $response = $this->actingAs($user)->get("api/v4/wallets/{$wallet->id}/referral-programs");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(5, $json);
        $this->assertSame('success', $json['status']);
        $this->assertSame(1, $json['page']);
        $this->assertSame(1, $json['count']);
        $this->assertFalse($json['hasMore']);
        $this->assertCount(1, $json['list']);
        $this->assertSame($program->id, $json['list'][0]['id']);
        $this->assertCount(1, $program->codes->fresh());
        $this->assertStringContainsString("/signup/referral/{$code->code}", $json['list'][0]['url']);
        $this->assertSame(2, $json['list'][0]['refcount']);
    }

    /**
     * Test fetching a wallet (GET /api/v4/wallets/:id)
     */
    public function testShow(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');
        $wallet = $john->wallets()->first();
        $wallet->balance = -100;
        $wallet->save();

        // Accessing a wallet of someone else
        $response = $this->actingAs($jack)->get("api/v4/wallets/{$wallet->id}");
        $response->assertStatus(403);

        // Accessing non-existing wallet
        $response = $this->actingAs($jack)->get("api/v4/wallets/aaa");
        $response->assertStatus(404);

        // Wallet owner
        $response = $this->actingAs($john)->get("api/v4/wallets/{$wallet->id}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame($wallet->id, $json['id']);
        $this->assertSame('CHF', $json['currency']);
        $this->assertSame($wallet->balance, $json['balance']);
        $this->assertTrue(empty($json['description']));
        $this->assertTrue(!empty($json['notice']));
    }

    /**
     * Test fetching wallet transactions
     */
    public function testTransactions(): void
    {
        $package_kolab = Package::where('title', 'kolab')->first();
        $user = $this->getTestUser('wallets-controller@kolabnow.com');
        $user->assignPackage($package_kolab);
        $john = $this->getTestUser('john@kolab.org');
        $wallet = $user->wallets()->first();

        // Unauth access not allowed
        $response = $this->get("api/v4/wallets/{$wallet->id}/transactions");
        $response->assertStatus(401);
        $response = $this->actingAs($john)->get("api/v4/wallets/{$wallet->id}/transactions");
        $response->assertStatus(403);

        // Expect empty list
        $response = $this->actingAs($user)->get("api/v4/wallets/{$wallet->id}/transactions");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(5, $json);
        $this->assertSame('success', $json['status']);
        $this->assertSame([], $json['list']);
        $this->assertSame(1, $json['page']);
        $this->assertSame(0, $json['count']);
        $this->assertFalse($json['hasMore']);

        // Create some sample transactions
        $transactions = $this->createTestTransactions($wallet);
        $transactions = array_reverse($transactions);
        $pages = array_chunk($transactions, 10 /* page size */);

        // Get the first page
        $response = $this->actingAs($user)->get("api/v4/wallets/{$wallet->id}/transactions");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(5, $json);
        $this->assertSame('success', $json['status']);
        $this->assertSame(1, $json['page']);
        $this->assertSame(10, $json['count']);
        $this->assertTrue($json['hasMore']);
        $this->assertCount(10, $json['list']);
        foreach ($pages[0] as $idx => $transaction) {
            $this->assertSame($transaction->id, $json['list'][$idx]['id']);
            $this->assertSame($transaction->type, $json['list'][$idx]['type']);
            $this->assertSame(\config('app.currency'), $json['list'][$idx]['currency']);
            $this->assertSame($transaction->shortDescription(), $json['list'][$idx]['description']);
            $this->assertFalse($json['list'][$idx]['hasDetails']);
            $this->assertFalse(array_key_exists('user', $json['list'][$idx]));
        }

        $search = null;

        // Get the second page
        $response = $this->actingAs($user)->get("api/v4/wallets/{$wallet->id}/transactions?page=2");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(5, $json);
        $this->assertSame('success', $json['status']);
        $this->assertSame(2, $json['page']);
        $this->assertSame(2, $json['count']);
        $this->assertFalse($json['hasMore']);
        $this->assertCount(2, $json['list']);
        foreach ($pages[1] as $idx => $transaction) {
            $this->assertSame($transaction->id, $json['list'][$idx]['id']);
            $this->assertSame($transaction->type, $json['list'][$idx]['type']);
            $this->assertSame($transaction->shortDescription(), $json['list'][$idx]['description']);
            $this->assertSame(
                $transaction->type == Transaction::WALLET_DEBIT,
                $json['list'][$idx]['hasDetails']
            );
            $this->assertFalse(array_key_exists('user', $json['list'][$idx]));

            if ($transaction->type == Transaction::WALLET_DEBIT) {
                $search = $transaction->id;
            }
        }

        // Get a non-existing page
        $response = $this->actingAs($user)->get("api/v4/wallets/{$wallet->id}/transactions?page=3");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(5, $json);
        $this->assertSame('success', $json['status']);
        $this->assertSame(3, $json['page']);
        $this->assertSame(0, $json['count']);
        $this->assertFalse($json['hasMore']);
        $this->assertCount(0, $json['list']);

        // Sub-transaction searching
        $response = $this->actingAs($user)->get("api/v4/wallets/{$wallet->id}/transactions?transaction=123");
        $response->assertStatus(404);

        $response = $this->actingAs($user)->get("api/v4/wallets/{$wallet->id}/transactions?transaction={$search}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(5, $json);
        $this->assertSame('success', $json['status']);
        $this->assertSame(1, $json['page']);
        $this->assertSame(2, $json['count']);
        $this->assertFalse($json['hasMore']);
        $this->assertCount(2, $json['list']);
        $this->assertSame(Transaction::ENTITLEMENT_BILLED, $json['list'][0]['type']);
        $this->assertSame(Transaction::ENTITLEMENT_BILLED, $json['list'][1]['type']);

        // Test that John gets 404 if he tries to access
        // someone else's transaction ID on his wallet's endpoint
        $wallet = $john->wallets()->first();
        $response = $this->actingAs($john)->get("api/v4/wallets/{$wallet->id}/transactions?transaction={$search}");
        $response->assertStatus(404);
    }
}
