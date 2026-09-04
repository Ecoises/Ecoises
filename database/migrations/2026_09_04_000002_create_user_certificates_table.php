<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_certificates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('verification_code')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('content_id')->constrained('educational_content')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained('user_content_enrollments')->cascadeOnDelete();
            $table->decimal('final_score', 5, 2)->nullable();
            $table->timestamp('issued_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'content_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_certificates');
    }
};
