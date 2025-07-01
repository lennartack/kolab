<?php

namespace App\Console;

use App\Utils;

/**
 * This abstract class provides a means to treat objects in our model using CRUD.
 */
abstract class ObjectUpdateCommand extends ObjectCommand
{
    /** @var ?array Object properties */
    protected $properties;

    public function __construct()
    {
        $this->description = "Update a {$this->objectName}";
        $this->signature = sprintf(
            "%s%s:update {%s}",
            $this->commandPrefix ? $this->commandPrefix . ":" : "",
            $this->objectName,
            $this->objectName
        );

        // This constructor is called for every ObjectUpdateCommand command,
        // no matter which command is being executed. We should not use database
        // access from here. And it should be as fast as possible.

        $class = new $this->objectClass();

        foreach ($this->getClassProperties() as $property) {
            if ($property == 'id') {
                continue;
            }

            $this->signature .= " {--{$property}=}";
        }

        if (Utils::isSoftDeletable($class)) {
            $this->signature .= " {--with-deleted : Include deleted {$this->objectName}s}";
        }

        parent::__construct();
    }

    /**
     * Get all properties (sql table columns) of the model class
     */
    protected function getClassProperties(): array
    {
        // We are not using table information schema, because it makes
        // all artisan commands slow. We depend on the @property definitions
        // in the class documentation comment.

        $reflector = new \ReflectionClass($this->objectClass);
        $list = [];

        if (preg_match_all('/@property\s+([^$\s]+)\s+\$([a-z_]+)/', $reflector->getDocComment(), $matches)) {
            foreach ($matches[1] as $key => $type) {
                $type = preg_replace('/[\?]/', '', $type);
                if (preg_match('/^(int|string|float|bool|\Carbon\Carbon)$/', $type)) {
                    $list[] = $matches[2][$key];
                }
            }
        }

        // Add created_at, updated_at, deleted_at where applicable
        if ($this->commandPrefix == 'scalpel') {
            $class = new $this->objectClass();

            if ($class->timestamps && !in_array('created_at', $list)) {
                $list[] = 'created_at';
            }
            if ($class->timestamps && !in_array('updated_at', $list)) {
                $list[] = 'updated_at';
            }
            if (Utils::isSoftDeletable($class) && !in_array('deleted_at', $list)) {
                $list[] = 'deleted_at';
            }
        }

        return $list;
    }

    /**
     * Get object properties from the input
     */
    public function getProperties(): array
    {
        if (is_array($this->properties)) {
            return $this->properties;
        }

        $this->properties = [];

        foreach ($this->getClassProperties() as $property) {
            if ($property == 'id') {
                continue;
            }

            if (($value = $this->option($property)) !== null) {
                $this->properties[$property] = $value;
            }
        }

        return $this->properties;
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $argument = $this->argument($this->objectName);

        $object = $this->getObject($this->objectClass, $argument, $this->objectTitle);

        if (!$object) {
            $this->error("No such {$this->objectName} {$argument}");
            return 1;
        }

        foreach ($this->getProperties() as $property => $value) {
            if ($property == 'deleted_at' && $value === 'null') {
                $value = null;
            }

            $object->{$property} = $value;
        }

        if ($this->commandPrefix == 'scalpel') {
            $object->saveQuietly();
        } else {
            $object->save();
        }
    }
}
