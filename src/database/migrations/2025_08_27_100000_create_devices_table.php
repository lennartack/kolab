<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create(
            'devices',
            static function (Blueprint $table) {
                $table->bigInteger('id')->unsigned()->primary();
                $table->string('hash', 191)->unique();
                $table->bigInteger('tenant_id')->unsigned()->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('tenant_id')->references('id')->on('tenants')
                    ->onDelete('set null')->onUpdate('cascade');
            }
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::dropIfExists('devices');
    }
};
