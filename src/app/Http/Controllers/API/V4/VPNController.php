<?php

namespace App\Http\Controllers\API\V4;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa;
use Lcobucci\JWT\Token\Builder;

class VPNController extends Controller
{
    /**
     * Token request from the vpn module
     */
    public function token(Request $request): JsonResponse
    {
        $signingKey = \config("app.vpn.token_signing_key");
        if (empty($signingKey)) {
            throw new \Exception("app.vpn.token_signing_key is not set");
        }

        $tokenBuilder = (new Builder(new JoseEncoder(), ChainedFormatter::default()));
        $token = $tokenBuilder
            ->issuedAt(Carbon::now()->toImmutable())
            // The entitlement is hardcoded for now to default.
            // Can be extended in the future based on user entitlements.
            ->withClaim('entitlement', "default")
            ->getToken(new Rsa\Sha256(), InMemory::plainText($signingKey));

        return response()->json(['status' => 'ok', 'token' => $token->toString()]);
    }
}
