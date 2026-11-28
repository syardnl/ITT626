<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A recorded failure to meet a Response or Resolution target in one Repair Cycle (design §6.3).
        // Once recorded it is never cleared; the unique key keeps it to one per cycle and type.
        Schema::create('sla_breaches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->constrained()->restrictOnDelete();
            $table->foreignId('repair_cycle_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);   // response | resolution
            $table->timestamp('due_at');
            $table->timestamp('detected_at');
            $table->timestamps();

            $table->unique(['repair_cycle_id', 'type']);
            $table->index('detected_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_breaches');
    }
};
