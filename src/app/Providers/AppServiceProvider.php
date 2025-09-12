<?php

namespace App\Providers;

use App\Backends\DAV;
use App\Backends\IMAP;
use App\Backends\LDAP;
use App\Backends\OpenExchangeRates;
use App\Backends\PGP;
use App\Backends\Roundcube;
use App\Backends\Storage;
use App\Delegation;
use App\Domain;
use App\Entitlement;
use App\EventLog;
use App\Group;
use App\GroupSetting;
use App\Meet\Room;
use App\Observers\DelegationObserver;
use App\Observers\DomainObserver;
use App\Observers\EntitlementObserver;
use App\Observers\EventLogObserver;
use App\Observers\GroupObserver;
use App\Observers\GroupSettingObserver;
use App\Observers\Meet\RoomObserver;
use App\Observers\PackageSkuObserver;
use App\Observers\PlanPackageObserver;
use App\Observers\PowerDNS\DomainObserver as DNSDomainObserver;
use App\Observers\PowerDNS\RecordObserver as DNSRecordObserver;
use App\Observers\ReferralCodeObserver;
use App\Observers\ResourceObserver;
use App\Observers\ResourceSettingObserver;
use App\Observers\SharedFolderAliasObserver;
use App\Observers\SharedFolderObserver;
use App\Observers\SharedFolderSettingObserver;
use App\Observers\SignupCodeObserver;
use App\Observers\SignupInvitationObserver;
use App\Observers\SignupTokenObserver;
use App\Observers\TransactionObserver;
use App\Observers\UserAliasObserver;
use App\Observers\UserObserver;
use App\Observers\UserSettingObserver;
use App\Observers\VerificationCodeObserver;
use App\Observers\WalletObserver;
use App\PackageSku;
use App\PlanPackage;
use App\PowerDNS\Domain as DNSDomain;
use App\PowerDNS\Record as DNSRecord;
use App\ReferralCode;
use App\Resource;
use App\ResourceSetting;
use App\SharedFolder;
use App\SharedFolderAlias;
use App\SharedFolderSetting;
use App\SignupCode;
use App\SignupInvitation;
use App\SignupToken;
use App\Transaction;
use App\User;
use App\UserAlias;
use App\UserSetting;
use App\VerificationCode;
use App\Wallet;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use GuzzleHttp\TransferStats;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // This must be here, not in PassportServiceProvider
        Passport::ignoreRoutes();

        $this->app->bind('imap', static function () {
            return new IMAP();
        });
        $this->app->bind('ldap', static function () {
            return new LDAP();
        });
        $this->app->bind('dav', static function () {
            return new DAV();
        });
        $this->app->bind('roundcube', static function () {
            return new Roundcube();
        });
        $this->app->bind('pgp', static function () {
            return new PGP();
        });
        $this->app->bind('filestorage', static function () {
            return new Storage();
        });
        $this->app->bind('openexchangerates', static function () {
            return new OpenExchangeRates();
        });
    }

    /**
     * Load the override config and apply it
     *
     * Create a config/override.php file with content like this:
     * return [
     *   'services.imap.uri' => 'overrideValue1',
     *   'queue.connections.database.table' => 'overrideValue2',
     * ];
     */
    private function applyOverrideConfig(): void
    {
        $overrideConfig = (array) \config('override');
        foreach (array_keys($overrideConfig) as $key) {
            \config([$key => $overrideConfig[$key]]);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Delegation::observe(DelegationObserver::class);
        DNSDomain::observe(DNSDomainObserver::class);
        DNSRecord::observe(DNSRecordObserver::class);
        Domain::observe(DomainObserver::class);
        Entitlement::observe(EntitlementObserver::class);
        EventLog::observe(EventLogObserver::class);
        Group::observe(GroupObserver::class);
        GroupSetting::observe(GroupSettingObserver::class);
        Room::observe(RoomObserver::class);
        PackageSku::observe(PackageSkuObserver::class);
        PlanPackage::observe(PlanPackageObserver::class);
        ReferralCode::observe(ReferralCodeObserver::class);
        Resource::observe(ResourceObserver::class);
        ResourceSetting::observe(ResourceSettingObserver::class);
        SharedFolder::observe(SharedFolderObserver::class);
        SharedFolderAlias::observe(SharedFolderAliasObserver::class);
        SharedFolderSetting::observe(SharedFolderSettingObserver::class);
        SignupCode::observe(SignupCodeObserver::class);
        SignupInvitation::observe(SignupInvitationObserver::class);
        SignupToken::observe(SignupTokenObserver::class);
        Transaction::observe(TransactionObserver::class);
        User::observe(UserObserver::class);
        UserAlias::observe(UserAliasObserver::class);
        UserSetting::observe(UserSettingObserver::class);
        VerificationCode::observe(VerificationCodeObserver::class);
        Wallet::observe(WalletObserver::class);

        Schema::defaultStringLength(191);

        Blade::precompiler(static function ($str) {
            // For Scramble (API documentation) we replace external js/css resources with locally stored
            if (str_starts_with(\request()->path(), 'docs/api')) {
                if (preg_match_all('~(src|href)="(https://unpkg.com/[^"]+)~', $str, $matches)) {
                    if (!file_exists(\public_path('vendor'))) {
                        mkdir(\public_path('vendor'));
                    }
                    if (!file_exists(\public_path('vendor/scramble'))) {
                        mkdir(\public_path('vendor/scramble'));
                    }

                    foreach ($matches[2] as $href) {
                        $file = 'vendor/scramble/' . md5($href) . '.' . pathinfo($href, \PATHINFO_BASENAME);
                        if (!file_exists(\public_path($file))) {
                            $content = \file_get_contents($href);
                            if (!$content) {
                                break;
                            }

                            \file_put_contents(\public_path($file), $content);
                        }

                        $str = str_replace($href, "/{$file}", $str);
                    }
                }
            }

            return $str;
        });

        // Register some template helpers
        Blade::directive(
            'theme_asset',
            static function ($path) {
                $path = trim($path, '/\'"');
                return "<?php echo secure_asset('themes/' . \$env['app.theme'] . '/' . '{$path}'); ?>";
            }
        );

        Builder::macro(
            'withEnvTenantContext',
            function (?string $table = null) {
                $tenantId = \config('app.tenant_id');

                if ($tenantId) {
                    // @var Builder $this
                    return $this->where(($table ? "{$table}." : "") . "tenant_id", $tenantId);
                }

                // @var Builder $this
                return $this->whereNull(($table ? "{$table}." : "") . "tenant_id");
            }
        );

        Builder::macro(
            'withObjectTenantContext',
            function (object $object, ?string $table = null) {
                $tenantId = $object->tenant_id;

                if ($tenantId) {
                    // @var Builder $this
                    return $this->where(($table ? "{$table}." : "") . "tenant_id", $tenantId);
                }

                // @var Builder $this
                return $this->whereNull(($table ? "{$table}." : "") . "tenant_id");
            }
        );

        Builder::macro(
            'withSubjectTenantContext',
            function (?string $table = null) {
                if ($user = auth()->user()) {
                    $tenantId = $user->tenant_id;
                } else {
                    $tenantId = \config('app.tenant_id');
                }

                if ($tenantId) {
                    // @var Builder $this
                    return $this->where(($table ? "{$table}." : "") . "tenant_id", $tenantId);
                }

                // @var Builder $this
                return $this->whereNull(($table ? "{$table}." : "") . "tenant_id");
            }
        );

        Http::macro('withSlowLog', function () {
            return Http::withOptions([
                'on_stats' => static function (TransferStats $stats) {
                    $threshold = \config('logging.slow_log');
                    if ($threshold && ($sec = $stats->getTransferTime()) > $threshold) {
                        $url = $stats->getEffectiveUri();
                        $method = $stats->getRequest()->getMethod();
                        \Log::warning(sprintf("[STATS] %s %s: %.4f sec.", $method, $url, $sec));
                    }
                },
            ]);
        });

        Http::macro('fakeClear', function () {
            $reflection = new \ReflectionObject(Http::getFacadeRoot());
            $property = $reflection->getProperty('stubCallbacks');
            $property->setAccessible(true);
            $property->setValue(Http::getFacadeRoot(), collect());

            return Http::getFacadeRoot();
        });

        JsonResource::withoutWrapping();

        Scramble::configure()
            ->routes(static function (Route $route) {
                // Exclude admin/reseller/webhooks groups for now
                // TODO: Find a way to separate or describe access
                return empty($route->action['domain'])
                    && str_starts_with($route->uri, 'api/')
                    && $route->action['prefix'] != 'api/webhooks';
            })
            ->withDocumentTransformers(static function (OpenApi $openApi) {
                $openApi->secure(
                    SecurityScheme::http('bearer')
                );
            });

        // Use strict RFC compliant redirects which maintain the HTTP Method.
        // Otherwise the redirect will always use a GET request.
        Http::globalOptions([
            'allow_redirects' => ['strict' => true],
        ]);

        $this->applyOverrideConfig();
    }
}
