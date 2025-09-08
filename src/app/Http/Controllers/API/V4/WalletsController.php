<?php

namespace App\Http\Controllers\API\V4;

use App\Documents\Receipt;
use App\Http\Controllers\ResourceController;
use App\Http\Resources\WalletResource;
use App\Payment;
use App\ReferralCode;
use App\ReferralProgram;
use App\Transaction;
use App\Wallet;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as ResponseDefinition;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * API\WalletsController
 */
class WalletsController extends ResourceController
{
    /**
     * Wallet information.
     *
     * @param string $id Wallet identifier
     */
    public function show($id): JsonResponse
    {
        $wallet = Wallet::find($id);

        if (empty($wallet) || !$this->checkTenant($wallet->owner)) {
            return $this->errorResponse(404);
        }

        // Only owner (or admin) has access to the wallet
        if (!$this->guard()->user()->canRead($wallet)) {
            return $this->errorResponse(403);
        }

        return (new WalletResource($wallet))->response();
    }

    /**
     * Download a receipt.
     *
     * @param string $id      Wallet identifier
     * @param string $receipt Receipt identifier (YYYY-MM)
     */
    #[ResponseDefinition(status: 200, description: 'PDF file content', mediaType: 'application/pdf')]
    public function receiptDownload($id, $receipt): Response
    {
        $wallet = Wallet::find($id);

        if (empty($wallet) || !$this->checkTenant($wallet->owner)) {
            abort(404);
        }

        // Only owner (or admin) has access to the wallet
        if (!$this->guard()->user()->canRead($wallet)) {
            abort(403);
        }

        [$year, $month] = explode('-', $receipt);

        if (empty($year) || empty($month) || $year < 2000 || $month < 1 || $month > 12) {
            abort(404);
        }

        if ($receipt >= date('Y-m')) {
            abort(404);
        }

        $params = [
            'id' => sprintf('%04d-%02d', $year, $month),
            'site' => \config('app.name'),
        ];

        $filename = self::trans('documents.receipt-filename', $params) . '.pdf';

        $receipt = new Receipt($wallet, (int) $year, (int) $month);

        $content = $receipt->pdfOutput();

        return response($content)
            ->withHeaders([
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Content-Length' => strlen($content),
            ]);
    }

    /**
     * List receipts.
     *
     * @param string $id Wallet identifier
     *
     * @return JsonResponse
     */
    #[QueryParameter('page', description: 'List page', type: 'int')]
    public function receipts($id)
    {
        $wallet = Wallet::find($id);

        if (empty($wallet) || !$this->checkTenant($wallet->owner)) {
            return $this->errorResponse(404);
        }

        // Only owner (or admin) has access to the wallet
        if (!$this->guard()->user()->canRead($wallet)) {
            return $this->errorResponse(403);
        }

        $pageSize = 10;
        $page = (int) (request()->input('page')) ?: 1;
        $hasMore = false;

        $result = $wallet->payments()
            ->selectRaw('date_format(updated_at, "%Y-%m") as ident, sum(amount) as total')
            ->where('status', Payment::STATUS_PAID)
            ->where('amount', '<>', 0)
            ->orderBy('ident', 'desc')
            ->groupBy('ident')
            ->havingRaw('ident <> ?', [date('Y-m')]) // exclude current month
            ->limit($pageSize + 1)
            ->offset($pageSize * ($page - 1))
            ->get();

        if (count($result) > $pageSize) {
            $result->pop();
            $hasMore = true;
        }

        // @phpstan-ignore argument.unresolvableType
        $result = $result->map(static function ($item) use ($wallet) {
            $entry = [
                'period' => $item->ident, // @phpstan-ignore-line
                'amount' => $item->total, // @phpstan-ignore-line
                'currency' => $wallet->currency,
            ];
            return $entry;
        });

        return response()->json([
            'status' => 'success',
            // @var array{'period': string, 'amount': int, 'currency': string} List of receipts
            'list' => $result,
            // @var int Number of entries in the list
            'count' => count($result),
            // @var bool Indicates that there are more entries available
            'hasMore' => $hasMore,
            // @var int Current page
            'page' => $page,
        ]);
    }

    /**
     * List active referral programs.
     *
     * @param string $id Wallet identifier
     *
     * @return JsonResponse
     */
    public function referralPrograms($id)
    {
        $wallet = Wallet::find($id);

        if (empty($wallet) || !$this->checkTenant($wallet->owner)) {
            return $this->errorResponse(404);
        }

        // Only owner (or admin) has access to the wallet
        if (!$this->guard()->user()->canRead($wallet)) {
            return $this->errorResponse(403);
        }

        $raw_count = DB::raw('(select count(*) from referrals where referrals.code = code) as refcount');
        $codes = ReferralCode::where('user_id', $wallet->user_id)->select('code', 'program_id', $raw_count);

        $result = ReferralProgram::withObjectTenantContext($wallet->owner)
            ->where('active', true)
            ->leftJoinSub($codes, 'codes', static function (JoinClause $join) {
                $join->on('referral_programs.id', '=', 'codes.program_id');
            })
            ->select('id', 'name', 'description', 'tenant_id', 'codes.code', 'codes.refcount')
            ->get()
            ->map(static function ($program) use ($wallet) {
                if (empty($program->code)) {
                    // Register/Generate a code for the user if it does not exist yet
                    $code = $program->codes()->create(['user_id' => $wallet->user_id]);

                    $program->code = $code->code;
                }

                $code = new ReferralCode();
                $code->code = $program->code;
                $code->program = $program; // @phpstan-ignore-line

                $entry = [
                    'id' => $program->id,
                    'name' => $program->name,
                    'description' => $program->description,
                    'refcount' => $program->refcount ?? 0,
                    'url' => $code->signupUrl(),
                    'qrCode' => $code->qrCode(true),
                ];
                return $entry;
            });

        return response()->json([
            'status' => 'success',
            'list' => $result,
            // @var int Number of list entries
            'count' => count($result),
            'hasMore' => false,
            'page' => 1,
        ]);
    }

    /**
     * List transactions.
     *
     * @param string $id Wallet identifier
     *
     * @return JsonResponse
     */
    #[QueryParameter('page', description: 'List page', type: 'int')]
    #[QueryParameter('transaction', description: 'Parent  transaction', type: 'string')]
    public function transactions($id)
    {
        $wallet = Wallet::find($id);

        if (empty($wallet) || !$this->checkTenant($wallet->owner)) {
            return $this->errorResponse(404);
        }

        // Only owner (or admin) has access to the wallet
        if (!$this->guard()->user()->canRead($wallet)) {
            return $this->errorResponse(403);
        }

        $pageSize = 10;
        $page = (int) (request()->input('page')) ?: 1;
        $hasMore = false;
        $isAdmin = $this instanceof Admin\WalletsController;

        if ($transaction = request()->input('transaction')) {
            // Get sub-transactions for the specified transaction ID, first
            // check access rights to the transaction's wallet

            /** @var ?Transaction $transaction */
            $transaction = $wallet->transactions()->where('id', $transaction)->first();

            if (!$transaction) {
                return $this->errorResponse(404);
            }

            $result = Transaction::where('transaction_id', $transaction->id)->get();
        } else {
            // Get main transactions (paged)
            $result = $wallet->transactions()
                // FIXME: Do we know which (type of) transaction has sub-transactions
                //        without the sub-query?
                ->selectRaw("*, (SELECT count(*) FROM transactions sub "
                    . "WHERE sub.transaction_id = transactions.id) AS cnt")
                ->whereNull('transaction_id')
                ->latest()
                ->limit($pageSize + 1)
                ->offset($pageSize * ($page - 1))
                ->get();

            if (count($result) > $pageSize) {
                $result->pop();
                $hasMore = true;
            }
        }

        $result = $result->map(static function ($item) use ($isAdmin, $wallet) {
            $entry = [
                'id' => $item->id,
                'createdAt' => $item->created_at->format('Y-m-d H:i'),
                'type' => $item->type,
                'description' => $item->shortDescription(),
                'amount' => $item->amount,
                'currency' => $wallet->currency,
                'hasDetails' => !empty($item->cnt),
            ];

            if ($isAdmin && $item->user_email) {
                $entry['user'] = $item->user_email;
            }

            return $entry;
        });

        return response()->json([
            'status' => 'success',
            // @var array<array> List of transactions (properties: id, createdAt, type, description, amount, currency, hasDetails, user)
            'list' => $result,
            // @var int Number of entries in the list
            'count' => count($result),
            // @var bool Indicates that there are more entries available
            'hasMore' => $hasMore,
            // @var int Current page
            'page' => $page,
        ]);
    }
}
