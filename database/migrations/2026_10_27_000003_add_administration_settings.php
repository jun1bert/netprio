<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->boolean('is_active')->default(true)->index());
        Schema::table('roles', fn (Blueprint $t) => $t->json('permissions')->nullable());
        Schema::create('system_settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->json('value');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
        Schema::table('roles', fn (Blueprint $t) => $t->dropColumn('permissions'));
        Schema::table('users', function (Blueprint $t) {
            $t->dropIndex(['is_active']);
            $t->dropColumn('is_active');
        });
    }
};
