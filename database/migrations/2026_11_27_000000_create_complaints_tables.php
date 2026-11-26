<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Source ERD: ISSUE, renamed Complaint (ADR 0002).
        Schema::create('complaints', function (Blueprint $table) {
            $table->id();
            $table->string('reference_no', 20)->unique();
            $table->foreignId('reporter_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('complaint_categories')->restrictOnDelete();
            $table->string('title', 150);
            $table->text('description');

            // The Resident's answers, the Priority Score inputs (design §6.1).
            $table->string('urgency', 20);
            $table->boolean('safety_risk');
            $table->string('affected_units', 20);
            $table->boolean('essential_service_down');

            // The Priority Score as calculated at submission, with each input's points.
            $table->unsignedTinyInteger('priority_score');
            $table->json('priority_breakdown');
            $table->unsignedSmallInteger('priority_rule_version');
            $table->string('initial_priority', 20);
            $table->string('priority', 20);

            $table->string('status', 30);
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['location_id', 'category_id', 'created_at']);
            $table->index(['status', 'priority']);
            $table->index(['reporter_id', 'created_at']);
        });

        // Source ERD: ISSUE_STATUS_HISTORY. Append-only; a null actor is the system.
        Schema::create('complaint_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->constrained()->restrictOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->text('reason')->nullable();
            $table->timestamp('created_at');

            $table->index(['complaint_id', 'created_at']);
        });

        // One row per year: the last reference sequence number handed out, taken under a row lock.
        Schema::create('complaint_reference_counters', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaint_reference_counters');
        Schema::dropIfExists('complaint_status_history');
        Schema::dropIfExists('complaints');
    }
};
