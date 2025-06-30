<?php

namespace App\Http\Controllers\API\V4\Admin;

use App\Http\Controllers\Controller;
use App\Payment;
use App\User;
use App\Utils;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    public const COLOR_GREEN = '#48d368';       // '#28a745'
    public const COLOR_GREEN_DARK = '#19692c';
    public const COLOR_RED = '#e77681';         // '#dc3545'
    public const COLOR_RED_DARK = '#a71d2a';
    public const COLOR_BLUE = '#4da3ff';        // '#007bff'
    public const COLOR_BLUE_DARK = '#0056b3';
    public const COLOR_ORANGE = '#f1a539';

    public const TYPE_PAYERS = 1;

    /** @var array List of enabled charts */
    protected $charts = [
        'discounts',
        'income',
        'payers',
        'users',
        'users-all',
        'vouchers',
        'users-per-country',
    ];

    /**
     * Fetch chart data
     *
     * @param string $chart Name of the chart
     *
     * @return JsonResponse
     */
    public function chart($chart)
    {
        if (!preg_match('/^[a-z-]+$/', $chart)) {
            return $this->errorResponse(404);
        }

        $method = 'chart' . implode('', array_map('ucfirst', explode('-', $chart)));

        if (!in_array($chart, $this->charts) || !method_exists($this, $method)) {
            return $this->errorResponse(404);
        }

        $result = $this->{$method}();

        return response()->json($result);
    }

    /**
     * Get discounts chart
     */
    protected function chartDiscounts(): array
    {
        $discounts = DB::table('wallets')
            ->selectRaw("discount, count(discount_id) as cnt")
            ->join('discounts', 'discounts.id', '=', 'wallets.discount_id')
            ->join('users', 'users.id', '=', 'wallets.user_id')
            ->where('discount', '>', 0)
            ->whereNull('users.deleted_at')
            ->groupBy('discounts.discount');

        $addTenantScope = static function ($builder, $tenantId) {
            return $builder->where('users.tenant_id', $tenantId);
        };

        $discounts = $this->applyTenantScope($discounts, $addTenantScope)
            ->pluck('cnt', 'discount')->all();

        $labels = array_keys($discounts);
        $discounts = array_values($discounts);

        // $labels = [10, 25, 30, 100];
        // $discounts = [100, 120, 30, 50];

        $labels = array_map(static function ($item) {
            return $item . '%';
        }, $labels);

        return $this->donutChart(self::trans('app.chart-discounts'), $labels, $discounts);
    }

    /**
     * Get income chart
     */
    protected function chartIncome(): array
    {
        $weeks = 8;
        $start = Carbon::now();
        $labels = [];

        while ($weeks > 0) {
            $labels[] = $start->format('Y-W');
            $weeks--;
            if ($weeks) {
                $start->subWeeks(1);
            }
        }

        $labels = array_reverse($labels);
        $start->startOfWeek(Carbon::MONDAY);

        // FIXME: We're using wallets.currency instead of payments.currency and payments.currency_amount
        //       as I believe this way we have more precise amounts for this use-case (and default currency)

        $query = DB::table('payments')
            ->selectRaw("date_format(updated_at, '%x-%v') as period, sum(credit_amount) as amount, wallets.currency")
            ->join('wallets', 'wallets.id', '=', 'wallet_id')
            ->where('updated_at', '>=', $start->toDateString())
            ->where('status', Payment::STATUS_PAID)
            ->whereIn('type', [Payment::TYPE_ONEOFF, Payment::TYPE_RECURRING])
            ->groupByRaw('period, wallets.currency');

        $addTenantScope = static function ($builder, $tenantId) {
            $where = sprintf(
                '`wallets`.`user_id` IN (select `id` from `users` where `tenant_id` = %d)',
                $tenantId
            );

            return $builder->whereRaw($where);
        };

        $currency = $this->currency();
        $payments = [];

        $this->applyTenantScope($query, $addTenantScope)
            ->get()
            ->each(static function ($record) use (&$payments, $currency) {
                $amount = $record->amount;
                if ($record->currency != $currency) {
                    $amount = (int) round($amount * Utils::exchangeRate($record->currency, $currency));
                }

                if (isset($payments[$record->period])) {
                    $payments[$record->period] += $amount / 100;
                } else {
                    $payments[$record->period] = $amount / 100;
                }
            });

        // TODO: exclude refunds/chargebacks

        $empty = array_fill_keys($labels, 0);
        $payments = array_values(array_merge($empty, $payments));

        // $payments = [1000, 1200.25, 3000, 1897.50, 2000, 1900, 2134, 3330];

        $avg = collect($payments)->slice(0, count($labels) - 1)->avg();

        // See https://frappe.io/charts/docs for format/options description

        return [
            'title' => self::trans('app.chart-income', ['currency' => $currency]),
            'type' => 'bar',
            'colors' => [self::COLOR_BLUE],
            'axisOptions' => [
                'xIsSeries' => true,
            ],
            'data' => [
                'labels' => $labels,
                'datasets' => [
                    [
                        // 'name' => 'Payments',
                        'values' => $payments,
                    ],
                ],
                'yMarkers' => [
                    [
                        'label' => sprintf('average = %.2F', $avg),
                        'value' => $avg,
                        'options' => ['labelPos' => 'left'], // default: 'right'
                    ],
                ],
            ],
        ];
    }

    /**
     * Get payers chart
     */
    protected function chartPayers(): array
    {
        [$labels, $stats] = $this->getCollectedStats(self::TYPE_PAYERS, 54, static fn ($v) => (int) $v);

        // See https://frappe.io/charts/docs for format/options description

        return [
            'title' => self::trans('app.chart-payers'),
            'type' => 'line',
            'colors' => [self::COLOR_GREEN],
            'axisOptions' => [
                'xIsSeries' => true,
                'xAxisMode' => 'tick',
            ],
            'lineOptions' => [
                'hideDots' => true,
                'regionFill' => true,
            ],
            'data' => [
                'labels' => $labels,
                'datasets' => [
                    [
                        // 'name' => 'Existing',
                        'values' => $stats,
                    ],
                ],
            ],
        ];
    }

    /**
     * Get created/deleted users chart
     */
    protected function chartUsers(): array
    {
        $weeks = 8;
        $start = Carbon::now();
        $labels = [];

        while ($weeks > 0) {
            $labels[] = $start->format('Y-W');
            $weeks--;
            if ($weeks) {
                $start->subWeeks(1);
            }
        }

        $labels = array_reverse($labels);
        $start->startOfWeek(Carbon::MONDAY);

        $created = DB::table('users')
            ->selectRaw("date_format(created_at, '%x-%v') as period, count(*) as cnt")
            ->where('created_at', '>=', $start->toDateString())
            ->groupByRaw('1');

        $deleted = DB::table('users')
            ->selectRaw("date_format(deleted_at, '%x-%v') as period, count(*) as cnt")
            ->where('deleted_at', '>=', $start->toDateString())
            ->groupByRaw('1');

        $created = $this->applyTenantScope($created)->get();
        $deleted = $this->applyTenantScope($deleted)->get();

        $empty = array_fill_keys($labels, 0);
        $created = array_values(array_merge($empty, $created->pluck('cnt', 'period')->all()));
        $deleted = array_values(array_merge($empty, $deleted->pluck('cnt', 'period')->all()));

        // $created = [5, 2, 4, 2, 0, 5, 2, 4];
        // $deleted = [1, 2, 3, 1, 2, 1, 2, 3];

        $avg = collect($created)->slice(0, count($labels) - 1)->avg();

        // See https://frappe.io/charts/docs for format/options description

        return [
            'title' => self::trans('app.chart-users'),
            'type' => 'bar', // Required to fix https://github.com/frappe/charts/issues/294
            'colors' => [self::COLOR_GREEN, self::COLOR_RED],
            'axisOptions' => [
                'xIsSeries' => true,
            ],
            'data' => [
                'labels' => $labels,
                'datasets' => [
                    [
                        'name' => self::trans('app.chart-created'),
                        'chartType' => 'bar',
                        'values' => $created,
                    ],
                    [
                        'name' => self::trans('app.chart-deleted'),
                        'chartType' => 'line',
                        'values' => $deleted,
                    ],
                ],
                'yMarkers' => [
                    [
                        'label' => sprintf('%s = %.1f', self::trans('app.chart-average'), $avg),
                        'value' => collect($created)->avg(),
                        'options' => ['labelPos' => 'left'], // default: 'right'
                    ],
                ],
            ],
        ];
    }

    /**
     * Get all users chart
     */
    protected function chartUsersAll(): array
    {
        $weeks = 54;
        $start = Carbon::now();
        $labels = [];

        while ($weeks > 0) {
            $labels[] = $start->format('Y-W');
            $weeks--;
            if ($weeks) {
                $start->subWeeks(1);
            }
        }

        $start->startOfWeek(Carbon::MONDAY);

        $created = DB::table('users')
            ->selectRaw("date_format(created_at, '%x-%v') as period, count(*) as cnt")
            ->where('created_at', '>=', $start->toDateString())
            ->groupByRaw('1');

        $deleted = DB::table('users')
            ->selectRaw("date_format(deleted_at, '%x-%v') as period, count(*) as cnt")
            ->where('deleted_at', '>=', $start->toDateString())
            ->groupByRaw('1');

        $sus_created = DB::table('users')
            ->selectRaw("date_format(created_at, '%x-%v') as period, count(*) as cnt")
            ->where('status', '&', User::STATUS_SUSPENDED)
            ->where('created_at', '>=', $start->toDateString())
            ->groupByRaw('1');

        $sus_deleted = DB::table('users')
            ->selectRaw("date_format(deleted_at, '%x-%v') as period, count(*) as cnt")
            ->where('status', '&', User::STATUS_SUSPENDED)
            ->where('deleted_at', '>=', $start->toDateString())
            ->groupByRaw('1');

        $created = $this->applyTenantScope($created)->get();
        $deleted = $this->applyTenantScope($deleted)->get();
        $sus_created = $this->applyTenantScope($sus_created)->get();
        $sus_deleted = $this->applyTenantScope($sus_deleted)->get();
        $count = $this->applyTenantScope(DB::table('users')->whereNull('deleted_at'))->count();
        $sus_count = $this->applyTenantScope(DB::table('users')->whereNull('deleted_at')
            ->where('status', '&', User::STATUS_SUSPENDED))->count();

        $empty = array_fill_keys(array_reverse($labels), 0);
        $created = array_merge($empty, $created->pluck('cnt', 'period')->all());
        $deleted = array_merge($empty, $deleted->pluck('cnt', 'period')->all());
        $sus_created = array_merge($empty, $sus_created->pluck('cnt', 'period')->all());
        $sus_deleted = array_merge($empty, $sus_deleted->pluck('cnt', 'period')->all());
        $all = [];
        $suspended = [];

        foreach ($labels as $label) {
            $all[] = $count;
            $suspended[] = $sus_count;
            $count -= $created[$label] - $deleted[$label];
            $sus_count -= $sus_created[$label] - $sus_deleted[$label];
        }

        $all = array_reverse($all);
        $suspended = array_reverse($suspended);
        $labels = array_reverse($labels);

        // $start = 3000;
        // for ($i = 0; $i < count($labels); $i++) {
        //     $all[$i] = $start + $i * 15;
        // }

        // See https://frappe.io/charts/docs for format/options description

        return [
            'title' => self::trans('app.chart-allusers'),
            'type' => 'line',
            'colors' => [self::COLOR_GREEN, self::COLOR_ORANGE],
            'axisOptions' => [
                'xIsSeries' => true,
                'xAxisMode' => 'tick',
            ],
            'lineOptions' => [
                'hideDots' => true,
                'regionFill' => true,
            ],
            'data' => [
                'labels' => $labels,
                'datasets' => [
                    [
                        'name' => self::trans('app.chart-all'),
                        'values' => $all,
                    ],
                    [
                        'name' => self::trans('app.chart-suspended'),
                        'values' => $suspended,
                    ],
                ],
            ],
        ];
    }

    /**
     * Get users-per-country chart
     */
    protected function chartUsersPerCountry(): array
    {
        $counts = DB::table('users')
            ->selectRaw("user_settings.value as country, count(users.id) as cnt")
            ->leftJoin('user_settings', function (JoinClause $join) {
                $join->on('users.id', '=', 'user_settings.user_id')
                    ->where('key', 'country');
            })
            ->whereNull('users.deleted_at')
            ->whereNull('users.role')
            ->whereNot('users.status', '&', User::STATUS_DEGRADED)
            ->whereNot('users.status', '&', User::STATUS_SUSPENDED)
            ->groupByRaw('1')
            ->orderBy('cnt', 'desc');

        $addTenantScope = static function ($builder, $tenantId) {
            return $builder->where('users.tenant_id', $tenantId);
        };

        $result = [];
        $other = 0;

        // We get 7 countries with the biggest count, the rest is aggregated in 'Other' item
        $this->applyTenantScope($counts, $addTenantScope)
            ->pluck('cnt', 'country')
            ->each(function (int $count, string $country) use (&$result, &$other) {
                if (empty($country) || count($result) >= 7) {
                    $other += $count;
                } else {
                    $result[$country] = $count;
                }
            });

        if ($other) {
            $result[self::trans('app.other')] = $other;
        }

        arsort($result, \SORT_NUMERIC);

        $labels = self::countryLabels(array_keys($result));
        $result = array_values($result);

        // $labels = ['Other', 'Germany', 'Poland', 'Switzerland'];
        // $result = [200, 120, 30, 50];

        return $this->donutChart(self::trans('app.chart-users-per-country'), $labels, $result);
    }

    /**
     * Get vouchers chart
     */
    protected function chartVouchers(): array
    {
        $vouchers = DB::table('wallets')
            ->selectRaw("count(discount_id) as cnt, code")
            ->join('discounts', 'discounts.id', '=', 'wallets.discount_id')
            ->join('users', 'users.id', '=', 'wallets.user_id')
            ->where('discount', '>', 0)
            ->whereNotNull('code')
            ->whereNull('users.deleted_at')
            ->groupBy('discounts.code')
            ->havingRaw("count(discount_id) > 0")
            ->orderByRaw('1');

        $addTenantScope = static function ($builder, $tenantId) {
            return $builder->where('users.tenant_id', $tenantId);
        };

        $vouchers = $this->applyTenantScope($vouchers, $addTenantScope)
            ->pluck('cnt', 'code')->all();

        $labels = array_keys($vouchers);
        $vouchers = array_values($vouchers);

        // $labels = ["TEST", "NEW", "OTHER", "US"];
        // $vouchers = [100, 120, 30, 50];

        return $this->donutChart(self::trans('app.chart-vouchers'), $labels, $vouchers);
    }

    /**
     * Convert country codes into country names
     */
    protected static function countryLabels(array $labels): array
    {
        $countries = include resource_path('countries.php');

        return array_map(
            fn (string $code) => $countries[$code][1] ?? $code,
            $labels
        );
    }

    protected static function donutChart($title, $labels, $data): array
    {
        // See https://frappe.io/charts/docs for format/options description

        return [
            'title' => $title,
            'type' => 'donut',
            'colors' => [
                self::COLOR_BLUE,
                self::COLOR_BLUE_DARK,
                self::COLOR_GREEN,
                self::COLOR_GREEN_DARK,
                self::COLOR_ORANGE,
                self::COLOR_RED,
                self::COLOR_RED_DARK,
            ],
            'maxSlices' => 8,
            'tooltipOptions' => [], // does not work without it (https://github.com/frappe/charts/issues/314)
            'data' => [
                'labels' => $labels,
                'datasets' => [
                    [
                        'values' => $data,
                    ],
                ],
            ],
        ];
    }

    /**
     * Add tenant scope to the queries when needed
     *
     * @param Builder  $query    The query
     * @param callable $addQuery Additional tenant-scope query-modifier
     *
     * @return Builder
     */
    protected function applyTenantScope($query, $addQuery = null)
    {
        // TODO: Per-tenant stats for admins

        return $query;
    }

    /**
     * Get the currency for stats
     *
     * @return string Currency code
     */
    protected function currency()
    {
        $user = $this->guard()->user();

        // For resellers return their wallet currency
        if ($user->role == User::ROLE_RESELLER) {
            $currency = $user->wallet()->currency;
        }

        // System currency for others
        return \config('app.currency');
    }

    /**
     * Get collected stats for a specific type/period
     *
     * @param int       $type         Chart
     * @param int       $weeks        Number of weeks back from now
     * @param ?callable $itemCallback A callback to execute on every stat item
     *
     * @return array [ labels, stats ]
     */
    protected function getCollectedStats(int $type, int $weeks, $itemCallback = null): array
    {
        $start = Carbon::now();
        $labels = [];

        while ($weeks > 0) {
            $labels[] = $start->format('Y-W');
            $weeks--;
            if ($weeks) {
                $start->subWeeks(1);
            }
        }

        $labels = array_reverse($labels);
        $start->startOfWeek(Carbon::MONDAY);

        // Get the stats grouped by tenant and week
        $stats = DB::table('stats')
            ->selectRaw("tenant_id, date_format(created_at, '%x-%v') as period, avg(value) as cnt")
            ->where('type', $type)
            ->where('created_at', '>=', $start->toDateString())
            ->groupByRaw('1,2');

        // Get the query result and sum up per-tenant stats
        $result = [];
        $this->applyTenantScope($stats)->get()
            ->each(static function ($item) use (&$result) {
                $result[$item->period] = ($result[$item->period] ?? 0) + $item->cnt;
            });

        // Process the result, e.g. convert values to int
        if ($itemCallback) {
            $result = array_map($itemCallback, $result);
        }

        // Fill the missing weeks with zeros
        $result = array_values(array_merge(array_fill_keys($labels, 0), $result));

        return [$labels, $result];
    }
}
