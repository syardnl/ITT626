<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Photos attached to a Complaint. Never deleted (design §12): there is no delete endpoint.
        Schema::create('evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->constrained('complaints')->restrictOnDelete();
            $table->unsignedBigInteger('maintenance_record_id')->nullable();   // Phase 3: work-completion photos
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('type', 30);
            $table->string('path', 100)->unique();
            $table->string('mime', 50);
            $table->unsignedInteger('size_bytes');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->timestamp('created_at')->nullable();
            $table->index(['complaint_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence');
    }
};
