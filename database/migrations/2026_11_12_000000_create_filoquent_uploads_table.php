<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('filoquent_uploads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('owner_type');
            $table->string('owner_table', 128);
            $table->string('owner_key_name', 64);
            $table->string('owner_key');
            $table->string('attribute', 64);
            $table->json('manifest');
            $table->string('state', 10);
            $table->dateTime('checked_at', 6)->useCurrent();
            $table->dateTime('orphaned_at', 6)->nullable();
            $table->index(['state', 'id']);
            $table->index(['state', 'checked_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('filoquent_uploads');
    }
};
