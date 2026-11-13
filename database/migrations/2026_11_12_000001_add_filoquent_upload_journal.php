<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('filoquent_uploads', function (Blueprint $table): void {
            $table->string('owner_key')->nullable()->change();
        });

        Schema::create('filoquent_upload_namespace', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->uuid('namespace');
        });

        DB::table('filoquent_upload_namespace')->insert(['id' => 1, 'namespace' => (string) Str::uuid()]);
    }

    public function down(): void
    {
        if (config('filoquent.redis.database') !== null
            || DB::table('filoquent_uploads')->whereNull('owner_key')->orWhereIn('state', ['pending', 'writing'])->exists()) {
            throw new FiloquentException('Stop Filoquent writers, clean up pending uploads, and disable the Redis journal before rolling back its migration.');
        }

        Schema::table('filoquent_uploads', function (Blueprint $table): void {
            $table->string('owner_key')->nullable(false)->change();
        });

        Schema::dropIfExists('filoquent_upload_namespace');
    }
};
