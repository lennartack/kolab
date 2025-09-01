<?php

namespace App\Console\Commands\Scalpel\SharedFolder;

use App\Console\ObjectUpdateCommand;
use App\SharedFolder;

class UpdateCommand extends ObjectUpdateCommand
{
    protected $hidden = true;

    protected $commandPrefix = 'scalpel';
    protected $objectClass = SharedFolder::class;
    protected $objectName = 'shared-folder';
    protected $objectTitle = 'email';
}
