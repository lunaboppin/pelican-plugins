<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vscode_files_preferences', function (Blueprint $table) {
            $table->unsignedInteger('user_id')->primary();
            $table->string('mode', 16)->default('list');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vscode_files_preferences');
    }
};
