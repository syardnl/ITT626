<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Source ERD: TECHNICIAN. One profile per Technician account; the ERD's
        // home_location_id is named for what it must hold, a Block.
        Schema::create('technicians', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->string('company_name', 150)->nullable();
            $table->string('availability', 20);
            $table->unsignedSmallInteger('max_tasks');
            $table->foreignId('home_block_id')->constrained('locations')->restrictOnDelete();
            $table->timestamps();

            // The team filters, and later the recommendation ranking (design §6.2), select by these.
            $table->index(['availability', 'type']);
        });

        Schema::create('skill_technician', function (Blueprint $table) {
            $table->foreignId('technician_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->restrictOnDelete();
            $table->primary(['technician_id', 'skill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_technician');
        Schema::dropIfExists('technicians');
    }
};
