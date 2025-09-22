<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table(
            'signup_tokens',
            static function (Blueprint $table) {
                $table->text('plans');
            }
        );

        DB::table('signup_tokens')->update(['plans' => DB::raw("concat('[\"', `plan_id`, '\"]')")]);

        Schema::table(
            'signup_tokens',
            static function (Blueprint $table) {
                $table->dropColumn('plan_id');
            }
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table(
            'signup_tokens',
            static function (Blueprint $table) {
                $table->string('plan_id', 36);
            }
        );

        DB::table('signup_tokens')->select('id', 'plans')->get()
            ->each(static function ($token) {
                $plan_id = json_decode($token->plans, true)[0];
                DB::table('signup_tokens')->where('id', $token->id)->update(['plan_id' => $plan_id]);
            });

        Schema::table(
            'signup_tokens',
            static function (Blueprint $table) {
                $table->dropColumn('plans');
                $table->index('plan_id');
            }
        );
    }
};
