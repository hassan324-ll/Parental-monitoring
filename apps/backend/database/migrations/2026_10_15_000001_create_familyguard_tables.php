<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('families', function (Blueprint $t) {
            $t->id();
            $t->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('family_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('family_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->enum('role', ['owner', 'parent', 'viewer']);
            $t->unique(['family_id', 'user_id']);
            $t->timestamps();
        });
        Schema::create('children', function (Blueprint $t) {
            $t->id();
            $t->foreignId('family_id')->constrained()->cascadeOnDelete();
            $t->string('display_name');
            $t->unique(['id', 'family_id']);
            $t->timestamps();
        });
        Schema::create('devices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('family_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('child_id');
            $t->foreign(['child_id', 'family_id'])->references(['id', 'family_id'])->on('children')->cascadeOnDelete();
            $t->uuid('public_id')->unique();
            $t->string('name');
            $t->timestamp('revoked_at')->nullable();
            $t->unique(['id', 'family_id']);
            $t->timestamps();
        });
        Schema::create('device_pairings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('family_id')->constrained()->cascadeOnDelete();
            $t->string('token_hash', 64)->unique();
            $t->timestamp('expires_at')->index();
            $t->timestamp('consumed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('permission_grants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('device_id')->constrained()->cascadeOnDelete();
            $t->string('permission');
            $t->timestamp('granted_at');
            $t->timestamp('revoked_at')->nullable();
            $t->index(['device_id', 'permission']);
            $t->timestamps();
        });
        Schema::create('app_usage_daily', function (Blueprint $t) {
            $t->id();
            $t->foreignId('device_id')->constrained()->cascadeOnDelete();
            $t->date('day');
            $t->string('package_name');
            $t->unsignedInteger('duration_seconds');
            $t->unique(['device_id', 'day', 'package_name']);
            $t->timestamps();
        });
        Schema::create('location_updates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('device_id')->constrained()->cascadeOnDelete();
            $t->decimal('latitude', 10, 7);
            $t->decimal('longitude', 10, 7);
            $t->unsignedInteger('accuracy_meters');
            $t->timestamp('recorded_at');
            $t->index(['device_id', 'recorded_at']);
            $t->timestamps();
        });
        Schema::create('screen_share_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('family_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('device_id');
            $t->foreign(['device_id', 'family_id'])->references(['id', 'family_id'])->on('devices')->cascadeOnDelete();
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->enum('status', ['requested', 'approved', 'active', 'ended', 'denied', 'expired']);
            $t->timestamp('expires_at');
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('ended_at')->nullable();
            $t->timestamps();
        });
        Schema::create('alerts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('family_id')->constrained()->cascadeOnDelete();
            $t->string('type');
            $t->json('payload');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('family_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action');
            $t->uuid('subject_id')->nullable();
            $t->index(['family_id', 'created_at']);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('screen_share_sessions');
        Schema::dropIfExists('location_updates');
        Schema::dropIfExists('app_usage_daily');
        Schema::dropIfExists('permission_grants');
        Schema::dropIfExists('device_pairings');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('children');
        Schema::dropIfExists('family_members');
        Schema::dropIfExists('families');
    }
};
