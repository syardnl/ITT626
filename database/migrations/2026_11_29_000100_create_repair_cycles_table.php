<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One attempt to resolve a Complaint (ADR 0005): cycle 1 at submission, another at each
        // Resident Return. Phase 4 adds each cycle's SLA deadlines and breaches.
        Schema::create('repair_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('number');
            $table->timestamp('started_at');
            $table->timestamps();

            $table->unique(['complaint_id', 'number']);
        });

        // Complaints submitted before Repair Cycles existed start cycle 1 when they were reported.
        DB::table('repair_cycles')->insertUsing(
            ['complaint_id', 'number', 'started_at', 'created_at', 'updated_at'],
            DB::table('complaints')->select('id', DB::raw('1'), 'created_at', DB::raw('CURRENT_TIMESTAMP'), DB::raw('CURRENT_TIMESTAMP')),
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_cycles');
    }
};
