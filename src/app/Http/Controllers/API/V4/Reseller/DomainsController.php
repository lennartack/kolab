<?php

namespace App\Http\Controllers\API\V4\Reseller;

use App\Domain;
use App\Http\Resources\DomainResource;
use App\User;
use Illuminate\Http\JsonResponse;

class DomainsController extends \App\Http\Controllers\API\V4\Admin\DomainsController
{
    /**
     * Search for domains
     */
    public function index(): JsonResponse
    {
        $search = trim(request()->input('search'));
        $owner = trim(request()->input('owner'));
        $result = collect([]);

        if ($owner) {
            if ($owner = User::withSubjectTenantContext()->find($owner)) {
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
            if ($domain = Domain::withSubjectTenantContext()->where('namespace', $search)->first()) {
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
}
