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
            $table->index('api_token_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasIndex('revisions', 'revisions_api_token_id_index')) {
            Schema::table('revisions', function (Blueprint $table) {
                $table->dropIndex(['api_token_id']);
            });
        }
    }
};
