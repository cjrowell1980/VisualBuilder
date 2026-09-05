<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('builder_projects', function (Blueprint $table) {
            $table->string('template')->default('application')->after('description');
            $table->boolean('docker_enabled')->default(false)->after('database_driver');
            $table->string('output_path')->nullable()->after('docker_enabled');
            $table->string('status')->default('draft')->after('output_path');
        });
    }

    public function down(): void
    {
        Schema::table('builder_projects', function (Blueprint $table) {
            $table->dropColumn(['template', 'docker_enabled', 'output_path', 'status']);
        });
    }
};
