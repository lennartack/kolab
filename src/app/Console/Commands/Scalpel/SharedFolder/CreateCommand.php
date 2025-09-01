<?php

namespace App\Console\Commands\Scalpel\SharedFolder;

use App\Console\ObjectCreateCommand;
use App\SharedFolder;

class CreateCommand extends ObjectCreateCommand
{
    protected $hidden = true;

    protected $commandPrefix = 'scalpel';
    protected $objectClass = SharedFolder::class;
    protected $objectName = 'shared-folder';
    protected $objectTitle = 'email';
}
