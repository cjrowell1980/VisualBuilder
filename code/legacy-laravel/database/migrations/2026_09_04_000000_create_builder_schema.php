<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('builder_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('database_driver')->default('pgsql');
            $table->string('github_repository')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'slug']);
        });

        Schema::create('build_iterations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('builder_project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('name');
            $table->string('status')->default('draft');
            $table->json('configuration')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
            $table->unique(['builder_project_id', 'number']);
        });

        Schema::create('model_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('build_iteration_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('table_name');
            $table->boolean('soft_deletes')->default(false);
            $table->boolean('timestamps')->default(true);
            $table->timestamps();
            $table->unique(['build_iteration_id', 'name']);
        });

        Schema::create('model_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('model_definition_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type');
            $table->boolean('nullable')->default(false);
            $table->boolean('indexed')->default(false);
            $table->string('default_value')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['model_definition_id', 'name']);
        });

        Schema::create('plugin_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('build_iteration_id')->constrained()->cascadeOnDelete();
            $table->string('package');
            $table->string('constraint')->default('*');
            $table->string('type')->default('composer');
            $table->boolean('approved')->default(false);
            $table->timestamps();
            $table->unique(['build_iteration_id', 'type', 'package']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_requirements');
        Schema::dropIfExists('model_fields');
        Schema::dropIfExists('model_definitions');
        Schema::dropIfExists('build_iterations');
        Schema::dropIfExists('builder_projects');
    }
};
