<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_ingestion_snapshots', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('device_id')->constrained()->cascadeOnDelete();
            $t->date('day');
            $t->unsignedBigInteger('consent_version');
            $t->timestamp('recorded_until');
            $t->timestamps();
            $t->unique(['device_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_ingestion_snapshots');
    }
};
