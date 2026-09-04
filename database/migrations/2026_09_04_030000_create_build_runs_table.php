<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('build_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('build_iteration_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('validation');
            $table->string('status')->default('running');
            $table->json('checks')->nullable();
            $table->text('output')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('build_runs');
    }
};
