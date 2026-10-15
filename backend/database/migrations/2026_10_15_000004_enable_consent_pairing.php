<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_pairings', function (Blueprint $table): void {
            $table->unsignedBigInteger('child_id')->nullable();
            $table->foreign(['child_id', 'family_id'])
                ->references(['id', 'family_id'])->on('children')->cascadeOnDelete();
        });

        Schema::table('devices', function (Blueprint $table): void {
            $table->string('credential_hash', 64)->nullable()->unique();
            $table->string('android_version', 40)->nullable();
            $table->timestamp('last_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropUnique(['credential_hash']);
            $table->dropColumn(['credential_hash', 'android_version', 'last_seen_at']);
        });
        Schema::table('device_pairings', function (Blueprint $table): void {
            $table->dropForeign(['child_id', 'family_id']);
            $table->dropColumn('child_id');
        });
    }
};
