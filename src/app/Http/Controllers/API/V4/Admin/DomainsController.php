<?php

namespace App\Http\Controllers\API\V4\Admin;

use App\Domain;
use App\EventLog;
use App\Http\Resources\DomainResource;
use App\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DomainsController extends \App\Http\Controllers\API\V4\DomainsController
{
    /**
     * Remove the specified domain.
     *
     * @param string $id Domain identifier
     */
    public function destroy($id): JsonResponse
    {
        return $this->errorResponse(404);
    }

    /**
     * Search for domains
     */
    public function index(): JsonResponse
    {
        $search = trim(request()->input('search'));
        $owner = trim(request()->input('owner'));
        $result = collect([]);

        if ($owner) {
            if ($owner = User::find($owner)) {
                foreach ($owner->wallets as $wallet) {
                    $entitlements = $wallet->entitlements()->where('entitleable_type', Domain::class)->get();

                    foreach ($entitlements as $entitlement) {
                        $domain = $entitlement->entitleable;
                        $result->push($domain);
                    }
                }

                $result = $result->sortBy('namespace')->values();
            }
        } elseif (!empty($search)) {
            if ($domain = Domain::where('namespace', $search)->first()) {
                $result->push($domain);
            }
        }

        $result = [
            // List of domains
            'list' => DomainResource::collection($result),
            // @var int Number of entries in the list
            'count' => count($result),
            'message' => self::trans('app.search-foundxdomains', ['x' => count($result)]),
        ];

        return response()->json($result);
    }

    /**
     * Create a domain.
     */
    public function store(Request $request): JsonResponse
    {
        return $this->errorResponse(404);
    }

    /**
     * Suspend the domain
     *
     * @param Request $request the API request
     * @param string  $id      Domain identifier
     */
    public function suspend(Request $request, $id): JsonResponse
    {
        $domain = Domain::find($id);

        if (!$this->checkTenant($domain) || $domain->isPublic()) {
            return $this->errorResponse(404);
        }

        $v = Validator::make($request->all(), ['comment' => 'nullable|string|max:1024']);

        if ($v->fails()) {
            return response()->json(['status' => 'error', 'errors' => $v->errors()], 422);
        }

        $domain->suspend();

        EventLog::createFor($domain, EventLog::TYPE_SUSPENDED, $request->comment);

        return response()->json([
            'status' => 'success',
            'message' => self::trans('app.domain-suspend-success'),
        ]);
    }

    /**
     * Un-Suspend the domain
     *
     * @param Request $request the API request
     * @param string  $id      Domain identifier
     */
    public function unsuspend(Request $request, $id): JsonResponse
    {
        $domain = Domain::find($id);

        if (!$this->checkTenant($domain) || $domain->isPublic()) {
            return $this->errorResponse(404);
        }

        $v = Validator::make($request->all(), ['comment' => 'nullable|string|max:1024']);

        if ($v->fails()) {
            return response()->json(['status' => 'error', 'errors' => $v->errors()], 422);
        }

        $domain->unsuspend();

        EventLog::createFor($domain, EventLog::TYPE_UNSUSPENDED, $request->comment);

        return response()->json([
            'status' => 'success',
            'message' => self::trans('app.domain-unsuspend-success'),
        ]);
    }
}
