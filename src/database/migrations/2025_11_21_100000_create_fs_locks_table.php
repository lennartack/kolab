<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create(
            'fs_locks',
            static function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('item_id', 36);
                $table->string('owner', 512);
                $table->binary('token', 100); // VARBINARY
                $table->integer('timeout');
                $table->tinyInteger('scope');
                $table->tinyInteger('depth');
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['item_id', 'token']);
                $table->index(['created_at', 'timeout']);

                $table->foreign('item_id')->references('id')->on('fs_items')
                    ->onDelete('cascade')->onUpdate('cascade');
            }
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fs_locks');
    }
};
