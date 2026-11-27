<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Why the Manager returned the work (a Manager Rejection); staff only, never shown to the reporter.
        Schema::table('maintenance_records', function (Blueprint $table) {
            $table->string('verification_reason', 1000)->nullable()->after('verification_outcome');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_records', fn (Blueprint $table) => $table->dropColumn('verification_reason'));
    }
};
