<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Source ERD: ISSUE_CATEGORY (ADR 0002). Ten fixed rows, seeded; `key` is the
        // seeder's stable handle, so an Administrator rename never causes a duplicate.
        Schema::create('complaint_categories', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->string('name', 100)->unique();
            $table->string('default_priority', 20);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // Exactly one Skill per Category, named with it.
        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->unique()->constrained('complaint_categories')->restrictOnDelete();
            $table->string('name', 100)->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // One SLA Target per Priority; `version` goes up on every change so Repair
        // Cycles can snapshot the rule they started with.
        Schema::create('sla_rules', function (Blueprint $table) {
            $table->id();
            $table->string('priority', 20)->unique();
            $table->unsignedInteger('response_minutes');
            $table->unsignedInteger('resolution_minutes');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_rules');
        Schema::dropIfExists('skills');
        Schema::dropIfExists('complaint_categories');
    }
};
