<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Recurring Problems (design §6.4): one current flag per exact Location and Category
        // (superseded_at null), with earlier flags and their decisions kept as history.
        Schema::create('recurring_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->constrained('complaint_categories')->restrictOnDelete();
            $table->string('status', 20);            // open | acknowledged | dismissed
            $table->boolean('active')->default(true); // the group still has three or more in 90 days
            $table->json('complaint_ids');
            $table->unsignedInteger('complaint_count');
            $table->timestamp('first_reported_at');
            $table->timestamp('last_reported_at');
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->index(['location_id', 'category_id', 'superseded_at']);
            $table->index(['status', 'superseded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_flags');
    }
};
