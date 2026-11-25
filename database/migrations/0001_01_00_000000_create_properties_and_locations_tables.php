<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The single managed development (ADR 0003); source ERD: ORGANIZATION.
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('timezone')->default('Asia/Kuala_Lumpur');
            $table->timestamps();
        });

        // The Location tree: Property > Block > Floor > Unit, with Common Areas.
        // Placement rules and administration arrive with the Locations ticket.
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->string('type', 20);
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('importance', 20)->default('normal');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['parent_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
        Schema::dropIfExists('properties');
    }
};
