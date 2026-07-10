<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('cell_species');
        Schema::dropIfExists('map_cells');
    }

    public function down(): void
    {
        // No restoration needed - cell caching was a dead end
    }
};
