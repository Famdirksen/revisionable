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
            $table->json('context')
                ->nullable()
                ->after('new_value');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('revisions', 'context')) {
            Schema::table('revisions', function (Blueprint $table) {
                $table->dropColumn('context');
            });
        }
    }
};
