<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateRevisionsTableForUserTypeAttribute extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('revisions', function (Blueprint $table) {
            $table->string('user_type')
                ->nullable()
                ->after('revisionable_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('revisions', 'user_type')) {
            Schema::table('revisions', function (Blueprint $table) {
                $table->dropColumn('user_type');
            });
        }
    }
}
