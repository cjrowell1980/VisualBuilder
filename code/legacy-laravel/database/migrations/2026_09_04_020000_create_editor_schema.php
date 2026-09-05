<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('model_fields', function (Blueprint $table) {
            $table->string('label')->nullable()->after('name');
            $table->json('validation_rules')->nullable()->after('default_value');
            $table->boolean('unique')->default(false)->after('indexed');
        });

        Schema::create('model_relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_model_id')->constrained('model_definitions')->cascadeOnDelete();
            $table->foreignId('target_model_id')->constrained('model_definitions')->cascadeOnDelete();
            $table->string('name');
            $table->string('type');
            $table->string('foreign_key')->nullable();
            $table->timestamps();
            $table->unique(['source_model_id', 'name']);
        });

        Schema::create('page_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('build_iteration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('model_definition_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('page_type')->default('custom');
            $table->string('layout')->default('app');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['build_iteration_id', 'slug']);
        });

        Schema::create('control_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_definition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('model_field_id')->nullable()->constrained()->nullOnDelete();
            $table->string('control_type');
            $table->string('label')->nullable();
            $table->string('width')->default('full');
            $table->json('configuration')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('control_definitions');
        Schema::dropIfExists('page_definitions');
        Schema::dropIfExists('model_relationships');

        Schema::table('model_fields', function (Blueprint $table) {
            $table->dropColumn(['label', 'validation_rules', 'unique']);
        });
    }
};
