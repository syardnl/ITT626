<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A Technician's record of one repair attempt (Complete work). Never edited by the Technician
        // after submission; the Manager's verification fills the last three columns.
        Schema::create('maintenance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->constrained()->restrictOnDelete();
            $table->foreignId('assignment_id')->constrained('complaint_assignments')->restrictOnDelete();
            $table->foreignId('technician_id')->constrained()->restrictOnDelete();
            $table->foreignId('repair_cycle_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('attempt');
            $table->text('repair_result');
            $table->text('parts_used')->nullable();
            $table->text('notes')->nullable();
            // useCurrent: never ON UPDATE CURRENT_TIMESTAMP, whatever explicit_defaults_for_timestamp says.
            $table->timestamp('submitted_at')->useCurrent();
            // The Idempotency-Key the Technician sent: a retry returns this record instead of a second one.
            $table->string('submission_key', 100)->nullable();
            $table->string('verification_outcome', 20)->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['complaint_id', 'attempt']);
            $table->unique(['technician_id', 'submission_key']);
        });

        // Source ERD: EXPENSE. `amount` is the line's total, a decimal, never a float; every amount is
        // in ringgit today, and `currency` keeps that explicit.
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maintenance_record_id')->constrained()->restrictOnDelete();
            $table->string('description', 200);
            $table->decimal('quantity', 8, 2);
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3)->default('MYR');
            $table->timestamps();
        });

        Schema::table('evidence', function (Blueprint $table) {
            $table->foreign('maintenance_record_id')->references('id')->on('maintenance_records')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('evidence', fn (Blueprint $table) => $table->dropForeign(['maintenance_record_id']));
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('maintenance_records');
    }
};
