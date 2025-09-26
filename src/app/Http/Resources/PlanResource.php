<?php

namespace App\Http\Resources;

use App\Http\Controllers\Controller;
use App\Plan;
use Illuminate\Http\Request;

/**
 * Plan response
 *
 * @mixin Plan
 */
class PlanResource extends ApiResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $button = Controller::trans("app.planbutton-{$this->resource->title}");
        if (str_contains($button, 'app.planbutton')) {
            $button = Controller::trans('app.planbutton', ['plan' => $this->resource->name]);
        }

        return [
            // @var string Plan title (identifier)
            'title' => $this->resource->title,
            // @var string Plan name
            'name' => $this->resource->name,
            // @var string Plan description
            'description' => $this->resource->description,
            // @var string Button label
            'button' => $button,
            // @var string Plan mode (email, token, mandate, etc.)
            'mode' => $this->resource->mode ?: Plan::MODE_EMAIL,
            // @var bool Is the plan viable for a custom domain?
            'isDomain' => $this->resource->hasDomain(),
            // @var int Minimum number of months this plan is for
            'months' => $this->resource->months,
            // @var int Cumulative cost (in cents)
            'cost' => $this->resource->cost(),
            // Cost currency
            'currency' => \config('app.currency'),
        ];
    }
}
