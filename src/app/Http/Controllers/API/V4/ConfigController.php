<?php

namespace App\Http\Controllers\API\V4;

use App\Http\Controllers\Controller;
use App\UserSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConfigController extends Controller
{
    public const DEBUG_TTL = 12; // hours

    /**
     * Get the per-user webmail configuration.
     */
    public function webmail(Request $request): JsonResponse
    {
        $user = $this->guard()->user();

        if (!$this->checkTenant($user)) {
            return $this->errorResponse(404);
        }

        $apis = [
            'delegation' => 'app.with_delegation',
            'user-search' => 'app.with_user_search',
        ];

        $config = [
            // @var array<string> Webmail configuration overlays
            'kolab-configuration-overlays' => [],
            // @var string|null Debug mode
            'debug' => null,
            // @var array<string> List of disabled APIs
            'kolab-disabled-apis' => array_keys(array_filter($apis, fn ($v) => !\config($v))),
        ];

        /** @var array<string, UserSetting> $settings */
        $settings = $user->settings()->whereIn('key', ['kolabobjects_storage', 'debug'])->get()->keyBy('key')->all();

        $skus = $user->skuTitles();

        if (isset($settings['kolabobjects_storage']) || \config('app.kolabobjects_storage')) {
            $config['kolab-configuration-overlays'][] = 'kolabobjects';
        } else {
            $config['kolab-configuration-overlays'][] = 'kolab4';
        }

        if (in_array('activesync', $skus)) {
            $config['kolab-configuration-overlays'][] = 'activesync';
        }

        if (in_array('2fa', $skus)) {
            $config['kolab-configuration-overlays'][] = '2fa';
        }

        if (in_array('groupware', $skus)) {
            if (isset($settings['kolabobjects_storage']) || \config('app.kolabobjects_storage')) {
                $config['kolab-configuration-overlays'][] = 'groupware-kolabobjects';
            } else {
                $config['kolab-configuration-overlays'][] = 'groupware';
            }
        }

        // TODO: Per-domain configuration, e.g. skin/logo
        // $config['skin'] = 'apostrophy';
        // $config['skin_logo'] = 'data:image/svg+xml;base64,'
        //    . base64_encode(file_get_contents(storage_path('logo.svg')));

        if (!empty($settings['debug'])) {
            // Delete expired debug setting
            if ($settings['debug']->updated_at->isBefore(now()->subHours(self::DEBUG_TTL))) {
                $settings['debug']->delete();
            } else {
                $config['debug'] = $settings['debug']->value;
            }
        }

        return response()->json($config);
    }
}
