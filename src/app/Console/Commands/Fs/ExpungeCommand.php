<?php

namespace App\Console\Commands\Fs;

use App\Console\Command;
use App\Fs\Chunk;
use App\Fs\Item;
use App\Support\Facades\Storage;

class ExpungeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fs:expunge';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove deleted files and file chunks';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        // We remove deleted files as soon as they were deleted
        // It should be done before dealing with orpahned chunks below
        $files = Item::withTrashed()
            ->where('type', '&', Item::TYPE_FILE)
            ->whereNotNull('deleted_at')
            ->orderBy('deleted_at')
            ->cursor();

        foreach ($files as $file) {
            Storage::fileDelete($file);
        }

        // Remove orphaned files
        $files = Item::withTrashed()
            ->where('type', '&', Item::TYPE_FILE)
            ->whereNull('user_id')
            ->orderBy('deleted_at')
            ->cursor();

        foreach ($files as $file) {
            Storage::fileDelete($file);
        }

        // Remove orphaned items (of any type)
        Item::withTrashed()->whereNull('user_id')->forceDelete();

        // We remove orphaned chunks after upload ttl (while marked as deleted some chunks may still be
        // in the process of uploading a file).
        $chunks = Chunk::withTrashed()
            ->where('deleted_at', '<', now()->subSeconds(\App\Backends\Storage::UPLOAD_TTL))
            ->orderBy('deleted_at')
            ->cursor();

        foreach ($chunks as $chunk) {
            Storage::fileChunkDelete($chunk);
        }

        // FIXME: What about soft-deleted collection items, should we remove them (after some time)?
    }
}
