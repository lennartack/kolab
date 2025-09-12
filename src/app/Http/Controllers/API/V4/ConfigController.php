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

        $config = [
            // @var array<string> Webmail configuration overlays
            'kolab-configuration-overlays' => [],
            // @var string|null Debug mode
            'debug' => null,
        ];

        $skus = $user->skuTitles();

        if ((bool) $user->getSetting('kolabobjects_storage')) {
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
            if ((bool) $user->getSetting('kolabobjects_storage')) {
                $config['kolab-configuration-overlays'][] = 'groupware-kolabobjects';
            } else {
                $config['kolab-configuration-overlays'][] = 'groupware';
            }
        }

        // TODO: Per-domain configuration, e.g. skin/logo
        // $config['skin'] = 'apostrophy';
        // $config['skin_logo'] = 'data:image/svg+xml;base64,'
        //    . base64_encode(file_get_contents(storage_path('logo.svg')));

        if ($debug_setting = $user->settings()->where('key', 'debug')->first()) {
            /** @var UserSetting $debug_setting */
            // Make sure the setting didn't expire
            if ($debug_setting->updated_at->isBefore(now()->subHours(self::DEBUG_TTL))) {
                $debug_setting->delete();
            } else {
                $config['debug'] = $debug_setting->value;
            }
        }

        return response()->json($config);
    }
}
