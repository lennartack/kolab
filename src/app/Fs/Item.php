<?php

namespace App\Fs;

use App\Backends\Storage;
use App\Traits\BelongsToUserTrait;
use App\Traits\UuidStrKeyTrait;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The eloquent definition of a filesystem item.
 *
 * @property string $id      Item identifier
 * @property int    $type    Item type
 * @property string $path    Item path (readonly)
 * @property int    $user_id Item owner
 */
class Item extends Model
{
    use BelongsToUserTrait;
    use SoftDeletes;
    use UuidStrKeyTrait;

    public const TYPE_FILE = 1;
    public const TYPE_COLLECTION = 2;
    public const TYPE_INCOMPLETE = 4;

    /** @var list<string> The attributes that are mass assignable */
    protected $fillable = ['user_id', 'type'];

    /** @var array<string, string> The attributes that should be cast */
    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    /** @var string Database table name */
    protected $table = 'fs_items';

    /**
     * Content chunks of this item (file).
     *
     * @return HasMany<Chunk, $this>
     */
    public function chunks()
    {
        return $this->hasMany(Chunk::class);
    }

    /**
     * Copy the item to another location
     *
     * @param ?self   $target Target folder
     * @param ?string $name   Optional name (for rename)
     *
     * @return self Created copy item
     */
    public function copy(?self $target, ?string $name = null): self
    {
        // Create the new item and copy its properties
        $copy = new self();
        $copy->type = $this->type;
        $copy->user_id = $this->user_id;
        $copy->updated_at = $this->updated_at;
        $copy->save();

        $props = $this->properties()->get()->mapWithKeys(function ($property) {
            return [$property->key => new Property(['key' => $property->key, 'value' => $property->value])];
        });

        if (is_string($name) && strlen($name)) {
            $props['name']->value = $name;
        }

        $copy->properties()->saveMany($props);

        // Assign to the target folder
        if ($target) {
            $this->parents()->attach($target);
        }

        // FIXME: What can we do if copying content fails for any reason?

        // Copy the file/folder contents
        if ($this->isFile()) {
            Storage::fileCopy($this, $copy);
        } else {
            $this->children()->get()->each(function ($item) use ($copy) {
                $item->copy($copy);
            });
        }

        return $copy;
    }

    /**
     * Getter for the file path (without the filename) in the storage.
     */
    protected function path(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if (empty($this->id)) {
                    throw new \Exception("Cannot get path for an item without ID");
                }

                $id = substr($this->id, 0, 6);

                return implode('/', str_split($id, 2));
            }
        );
    }

    /**
     * Any (additional) properties of this item.
     *
     * @return HasMany<Property, $this>
     */
    public function properties()
    {
        return $this->hasMany(Property::class);
    }

    /**
     * Obtain the value for an item property
     *
     * @param string $key     Property name
     * @param mixed  $default Default value, to be used if not found
     *
     * @return string|null Property value
     */
    public function getProperty(string $key, $default = null)
    {
        $attr = $this->properties()->where('key', $key)->first();

        return $attr ? $attr->value : $default;
    }

    /**
     * Obtain the values for many properties in one go (for better performance).
     *
     * @param array $keys Property names
     *
     * @return array Property key=value hash, includes also requested but non-existing properties
     */
    public function getProperties(array $keys): array
    {
        $props = array_fill_keys($keys, null);

        $this->properties()->whereIn('key', $keys)->get()
            ->each(static function ($prop) use (&$props) {
                $props[$prop->key] = $prop->value;
            });

        return $props;
    }

    /**
     * Check if the item is a collection (folder)
     */
    public function isCollection(): bool
    {
        return (bool) ($this->type & self::TYPE_COLLECTION);
    }

    /**
     * Check if the item is a file
     */
    public function isFile(): bool
    {
        return (bool) ($this->type & self::TYPE_FILE);
    }

    /**
     * Check if the item is incomplete
     */
    public function isIncomplete(): bool
    {
        return (bool) ($this->type & self::TYPE_INCOMPLETE);
    }

    /**
     * Move the item to another location
     *
     * @param ?self   $target Target folder
     * @param ?string $name   Optional name (for rename)
     */
    public function move(?self $target, ?string $name = null): void
    {
        if ($target) {
            // move to another folder
            $this->parents()->sync([$target]);
        } else {
            // move to the root
            $this->parents()->sync([]);
        }

        if (is_string($name) && strlen($name)) {
            $this->setProperty('name', $name);
        }
    }

    /**
     * Remove a property
     *
     * @param string $key Property name
     */
    public function removeProperty(string $key): void
    {
        $this->setProperty($key, null);
    }

    /**
     * Create or update a property.
     *
     * @param string      $key   Property name
     * @param string|null $value The new value for the property
     */
    public function setProperty(string $key, $value): void
    {
        $this->storeProperty($key, $value);
    }

    /**
     * Create or update multiple properties in one fell swoop.
     *
     * @param array $data an associative array of key value pairs
     */
    public function setProperties(array $data = []): void
    {
        foreach ($data as $key => $value) {
            $this->storeProperty($key, $value);
        }
    }

    /**
     * Create or update a property.
     *
     * @param string      $key   Property name
     * @param string|null $value The new value for the property
     */
    private function storeProperty(string $key, $value): void
    {
        if ($value === null || $value === '') {
            $this->properties()->where('key', $key)->delete();
        } else {
            // Note: updateOrCreate() uses two queries, but upsert() uses one
            $this->properties()->upsert(
                // Note: Setting 'item_id' here should not be needed after we migrate to Laravel v11
                [['key' => $key, 'value' => $value, 'item_id' => $this->id]],
                ['item_id', 'key'],
                ['value']
            );
        }
    }

    /**
     * All relations for this item
     *
     * @return HasMany<Relation, $this>
     */
    public function relations()
    {
        return $this->hasMany(Relation::class);
    }

    /**
     * All locks for this item
     *
     * @return HasMany<Lock, $this>
     */
    public function locks()
    {
        return $this->hasMany(Lock::class);
    }

    /**
     * Child relations for this item
     *
     * Used to retrieve all items in a collection.
     *
     * @return BelongsToMany<Item, $this>
     */
    public function children()
    {
        return $this->belongsToMany(self::class, 'fs_relations', 'item_id', 'related_id');
    }

    /**
     * Parent relations for this item
     *
     * Used to retrieve all collections of an item.
     *
     * @return BelongsToMany<Item, $this>
     */
    public function parents()
    {
        return $this->belongsToMany(self::class, 'fs_relations', 'related_id', 'item_id');
    }
}
