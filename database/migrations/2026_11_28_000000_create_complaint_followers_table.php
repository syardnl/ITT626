<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A Resident tracking someone else's open Common Area Complaint instead of submitting a duplicate.
        Schema::create('complaint_followers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->constrained('complaints')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['complaint_id', 'user_id']);
            $table->index(['user_id', 'complaint_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaint_followers');
    }
};
