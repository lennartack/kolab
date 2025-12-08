<?php

namespace App\Console\Commands\Scalpel\Plan;

use App\Console\ObjectCreateCommand;
use App\Plan;

class CreateCommand extends ObjectCreateCommand
{
    protected $hidden = true;

    protected $commandPrefix = 'scalpel';
    protected $objectClass = Plan::class;
    protected $objectName = 'plan';
    protected $objectTitle = 'title';
}
