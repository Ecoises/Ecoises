<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Texto de importancia ecológica de un género o una familia. La ficha lo
 * muestra, marcado como del grupo, mientras la especie no tenga el suyo:
 * con pocos textos se cubre a casi todas las especies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_ecology_texts', function (Blueprint $table): void {
            $table->id();
            $table->string('rank', 20);                   // genus · family
            $table->string('name', 150);
            $table->text('text');
            $table->json('sources')->nullable();
            $table->string('model', 80)->nullable();
            $table->string('status', 20)->default('published'); // published · reviewed · rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['rank', 'name']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_ecology_texts');
    }
};
