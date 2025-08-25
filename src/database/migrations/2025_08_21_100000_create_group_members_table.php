<?php

use App\Group;
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
            'group_members',
            static function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->bigInteger('group_id');
                $table->string('email')->index();
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['group_id', 'email']);

                $table->foreign('group_id')->references('id')->on('groups')
                    ->onDelete('cascade')->onUpdate('cascade');
            }
        );

        Group::select('id', 'members')->get()->each(function ($group) {
            $members = explode(',', $group->members);
            $group->setAddresses($members);
        });

        Schema::table(
            'groups',
            static function (Blueprint $table) {
                $table->dropColumn('members');
            }
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table(
            'groups',
            static function (Blueprint $table) {
                $table->text('members')->nullable();
            }
        );

        Group::all()->each(function ($group) {
            $members = $group->getAddresses();
            DB::table('groups')->where('id', $group->id)->update(['members' => implode(',', $members)]);
        });

        Schema::dropIfExists('group_members');
    }
};
