<?php

namespace App\Http\Resources;

use App\User;
use App\VerificationCode;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Full user information response
 *
 * @mixin User
 */
class UserInfoExtendedResource extends UserInfoResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $code = $this->resource->verificationCodes()
            ->where('active', true)
            ->where('mode', VerificationCode::MODE_PASSWORD)
            ->where('expires_at', '>', Carbon::now())
            ->first();

        return [
            $this->merge(parent::toArray($request)),

            // @var array User configuration
            'config' => $this->resource->getConfig(true),
            // @var array<string> Email address aliases
            'aliases' => $this->resource->aliases()->pluck('alias')->all(),
            // @var string Password reset link code
            'passwordLinkCode' => $this->when(isset($code), $code ? ($code->short_code . '-' . $code->code) : null),
            // @var bool Authenticated user permission to delete the user
            'canDelete' => Auth::guard()->user()->canDelete($this->resource),
        ];
    }
}
