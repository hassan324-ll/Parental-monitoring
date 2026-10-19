<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_sharing_consents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('device_id')->constrained()->cascadeOnDelete();
            $t->enum('feature', ['usage', 'location']);
            $t->boolean('enabled')->default(false);
            $t->unsignedBigInteger('version')->default(0);
            $t->string('disclosure_version')->nullable();
            $t->timestamp('granted_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->unique(['device_id', 'feature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_sharing_consents');
    }
};
