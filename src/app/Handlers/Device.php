<?php

namespace App\Handlers;

class Device extends Base
{
    /**
     * The entitleable class for this handler.
     */
    public static function entitleableClass(): string
    {
        return \App\Device::class;
    }
}
