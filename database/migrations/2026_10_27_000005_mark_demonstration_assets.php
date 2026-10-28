<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_assets', fn (Blueprint $table) => $table->boolean('is_demo')->default(false)->index());
        // Only backfill the explicit marker used by the opt-in demo seeder.
        DB::table('network_assets')->where('notes', 'like', '%[NETPRIOR_DEMO_V1]%')->update(['is_demo' => true]);
    }

    public function down(): void
    {
        Schema::table('network_assets', function (Blueprint $table) {
            $table->dropIndex(['is_demo']);
            $table->dropColumn('is_demo');
        });
    }
};
