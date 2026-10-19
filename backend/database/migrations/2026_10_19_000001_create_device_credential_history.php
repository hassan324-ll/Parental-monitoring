<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_credential_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('credential_hash', 64)->unique();
            $table->timestamp('created_at');
        });
        DB::table('devices')->whereNotNull('credential_hash')->orderBy('id')
            ->chunkById(200, function ($devices): void {
                foreach ($devices as $device) {
                    DB::table('device_credential_history')->insert([
                        'device_id' => $device->id, 'credential_hash' => $device->credential_hash,
                        'created_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_credential_history');
    }
};
