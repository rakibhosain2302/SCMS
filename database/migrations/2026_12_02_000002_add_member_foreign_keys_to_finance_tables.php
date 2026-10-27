<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * scms-finance adds member_id foreign keys only when the members table already exists at its
 * own migration time; it ran first here, so the keys are added now.
 */
return new class extends Migration
{
    private const TABLES = ['finance_savings_accounts', 'finance_loans', 'finance_journal_lines'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            if (Schema::hasTable($name) && Schema::hasColumn($name, 'member_id')) {
                Schema::table($name, fn (Blueprint $table) => $table->foreign('member_id')->references('id')->on('members')->restrictOnDelete());
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            if (Schema::hasTable($name)) {
                Schema::table($name, fn (Blueprint $table) => $table->dropForeign(['member_id']));
            }
        }
    }
};
