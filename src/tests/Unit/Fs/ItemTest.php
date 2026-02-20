<?php

namespace Tests\Unit\Fs;

use App\Fs\Item;
use Tests\TestCase;

class ItemTest extends TestCase
{
    /**
     * Test type mutator
     */
    public function testSetTypeAttribute(): void
    {
        $item = new Item();

        $this->expectException(\Exception::class);
        $item->type = -1;

        $this->expectException(\Exception::class);
        $item->type = 'abc'; // @phpstan-ignore-line

        $item->type = Item::TYPE_INCOMPLETE;
        $this->assertSame(Item::TYPE_INCOMPLETE, $item->type);

        $item->type |= Item::TYPE_FILE;
        $this->assertSame(Item::TYPE_INCOMPLETE | Item::TYPE_FILE, $item->type);

        $this->expectException(\Exception::class);
        $item->type |= Item::TYPE_COLLECTION;

        $this->expectException(\Exception::class);
        $item->type |= Item::TYPE_NOTEBOOK;
    }

    /**
     * Test is*() methods
     */
    public function testIsMethods(): void
    {
        $item = new Item();

        $this->assertFalse($item->isFile());
        $this->assertFalse($item->isIncomplete());
        $this->assertFalse($item->isCollection());
        $this->assertFalse($item->isNotebook());

        $item->type = Item::TYPE_INCOMPLETE;
        $this->assertFalse($item->isFile());
        $this->assertTrue($item->isIncomplete());
        $this->assertFalse($item->isCollection());
        $this->assertFalse($item->isNotebook());

        $item->type = Item::TYPE_FILE;
        $this->assertTrue($item->isFile());
        $this->assertFalse($item->isIncomplete());
        $this->assertFalse($item->isCollection());
        $this->assertFalse($item->isNotebook());

        $item->type = Item::TYPE_COLLECTION;
        $this->assertFalse($item->isFile());
        $this->assertFalse($item->isIncomplete());
        $this->assertTrue($item->isCollection());
        $this->assertFalse($item->isNotebook());

        $item->type = Item::TYPE_COLLECTION | Item::TYPE_NOTEBOOK;
        $this->assertFalse($item->isFile());
        $this->assertFalse($item->isIncomplete());
        $this->assertTrue($item->isCollection());
        $this->assertTrue($item->isNotebook());
    }
}
