<?php

namespace App\Console\Commands\Scalpel\SharedFolder;

use App\Console\ObjectReadCommand;
use App\SharedFolder;

class ReadCommand extends ObjectReadCommand
{
    protected $hidden = true;

    protected $commandPrefix = 'scalpel';
    protected $objectClass = SharedFolder::class;
    protected $objectName = 'shared-folder';
    protected $objectTitle = 'email';
}
