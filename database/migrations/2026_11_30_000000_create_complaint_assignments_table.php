<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who is responsible for a Complaint's repair. An Assignment ends only at reassignment or
        // Closure, never at Resolved, so a Resident Return goes back to the same Technician.
        Schema::create('complaint_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->constrained()->restrictOnDelete();
            $table->foreignId('technician_id')->constrained()->restrictOnDelete();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            // useCurrent: never ON UPDATE CURRENT_TIMESTAMP, whatever explicit_defaults_for_timestamp says.
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 1000)->nullable();
            // Set when the Manager assigned a Technician at or above their maximum tasks.
            $table->string('override_reason', 1000)->nullable();
            $table->timestamps();

            // At most one active Assignment per Complaint, held by the database too.
            $table->unsignedBigInteger('active_complaint_id')->nullable()->storedAs('IF(ended_at IS NULL, complaint_id, NULL)')->unique();
            // Active Tasks are counted per Technician over their unended Assignments.
            $table->index(['technician_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaint_assignments');
    }
};
