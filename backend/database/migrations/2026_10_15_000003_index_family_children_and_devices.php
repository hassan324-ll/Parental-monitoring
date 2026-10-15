<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('children', fn (Blueprint $table) => $table->index('family_id'));
        Schema::table('devices', fn (Blueprint $table) => $table->index('family_id'));
    }

    public function down(): void
    {
        Schema::table('devices', fn (Blueprint $table) => $table->dropIndex(['family_id']));
        Schema::table('children', fn (Blueprint $table) => $table->dropIndex(['family_id']));
    }
};
