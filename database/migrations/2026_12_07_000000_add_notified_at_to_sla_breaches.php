<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When the Managers were told: each breach notifies once, and one left unnotified by a failed run is retried.
        Schema::table('sla_breaches', function (Blueprint $table) {
            $table->timestamp('notified_at')->nullable()->after('detected_at');
            $table->index('notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('sla_breaches', function (Blueprint $table) {
            $table->dropIndex(['notified_at']);
            $table->dropColumn('notified_at');
        });
    }
};
