<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The admission fee taken at registration and the journal entry that booked it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->decimal('admission_fee', 15, 2)->nullable()->after('admission_date');
            $table->foreignId('admission_fee_entry_id')->nullable()->after('admission_fee')->constrained('finance_journal_entries')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('members', fn (Blueprint $table) => $table->dropConstrainedForeignId('admission_fee_entry_id'));
        Schema::table('members', fn (Blueprint $table) => $table->dropColumn('admission_fee'));
    }
};
