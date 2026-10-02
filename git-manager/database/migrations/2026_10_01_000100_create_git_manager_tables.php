<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('git_manager_credentials', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id');
            $table->string('host');
            $table->string('username');
            $table->text('token');
            $table->timestamps();

            $table->unique(['server_id', 'host']);
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
        });

        Schema::create('git_manager_webhooks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('server_id');
            $table->string('path');
            $table->string('branch')->nullable();
            $table->text('secret');
            $table->timestamp('last_triggered_at')->nullable();
            $table->string('last_status', 32)->nullable();
            $table->text('last_output')->nullable();
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('git_manager_webhooks');
        Schema::dropIfExists('git_manager_credentials');
    }
};
