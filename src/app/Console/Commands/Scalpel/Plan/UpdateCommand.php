<?php

namespace App\Console\Commands\Scalpel\Plan;

use App\Console\ObjectUpdateCommand;
use App\Plan;

class UpdateCommand extends ObjectUpdateCommand
{
    protected $hidden = true;

    protected $commandPrefix = 'scalpel';
    protected $objectClass = Plan::class;
    protected $objectName = 'plan';
    protected $objectTitle = 'title';
}
