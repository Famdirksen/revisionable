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
        Schema::table('revisions', function (Blueprint $table) {
            $table->string('api_token_id')
                ->nullable()
                ->after('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('revisions', 'api_token_id')) {
            Schema::table('revisions', function (Blueprint $table) {
                $table->dropColumn('api_token_id');
            });
        }
    }
};
