<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            // Running number inside the branch; member_no is shown and searched.
            $table->unsignedInteger('serial');
            $table->string('member_no', 30)->unique();
            $table->string('name');
            $table->string('name_bn')->nullable();
            $table->string('father_name')->nullable();
            $table->string('mother_name')->nullable();
            $table->string('spouse_name')->nullable();
            $table->string('gender', 10)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('nid', 20)->nullable()->unique();
            $table->string('phone', 20);
            $table->string('email')->nullable();
            $table->string('occupation')->nullable();
            $table->text('present_address');
            $table->text('permanent_address')->nullable();
            $table->date('admission_date');
            $table->string('status', 20)->default('active');
            $table->date('closed_on')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'serial']);
            $table->index(['branch_id', 'status']);
            $table->index('name');
            $table->index('phone');
        });

        Schema::create('member_nominees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('name');
            $table->string('relation', 50);
            $table->string('nid', 20)->nullable();
            $table->string('phone', 20)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->decimal('share', 5, 2)->default(100);
            $table->string('address')->nullable();
            $table->timestamps();
        });

        // Kept on the private disk and served through MemberController (NID copies must not be public).
        Schema::create('member_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('title')->nullable();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100)->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_documents');
        Schema::dropIfExists('member_nominees');
        Schema::dropIfExists('members');
    }
};
