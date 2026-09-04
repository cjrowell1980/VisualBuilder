<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('build_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('build_iteration_id')->constrained()->cascadeOnDelete();
            $table->string('format');
            $table->string('path');
            $table->string('checksum', 64);
            $table->unsignedBigInteger('bytes');
            $table->timestamp('packaged_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('build_packages');
    }
};
