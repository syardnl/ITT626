<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each time a Manager confirms or changes the Category and Priority. Append-only.
        Schema::create('complaint_classification_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->constrained()->restrictOnDelete();
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('from_category_id')->nullable()->constrained('complaint_categories')->restrictOnDelete();
            $table->foreignId('to_category_id')->constrained('complaint_categories')->restrictOnDelete();
            $table->string('from_priority', 20);
            $table->string('to_priority', 20);
            // Required when to_priority differs from the Complaint's computed initial_priority.
            $table->text('reason')->nullable();
            $table->timestamp('created_at');

            $table->index(['complaint_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaint_classification_changes');
    }
};
