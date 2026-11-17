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
        $driver = Schema::getConnection()->getDriverName();

        Schema::create('filoquent_uploads', function (Blueprint $table) use ($driver): void {
            $table->uuid('id')->primary();
            $table->string('owner_type');
            $owner = $table->string('owner_table', 128);

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $owner->charset('utf8mb4')->collation('utf8mb4_bin');
            } elseif ($driver === 'sqlsrv') {
                $owner->collation('Latin1_General_100_BIN2');
            }
            $table->string('owner_key_name', 64);
            $table->string('owner_key')->nullable();
            $table->string('attribute', 64);
            $table->json('manifest');
            $table->string('state', 10);
            $table->dateTime('checked_at', 6)->useCurrent();
            $table->dateTime('orphaned_at', 6)->nullable();
            $table->string('journal_owner_table', 128)->nullable();
            $table->index(['state', 'id']);
            $table->index(['state', 'checked_at', 'id']);
            $table->index(['owner_table', 'state', 'checked_at', 'id'], 'filoquent_owner_checked');
            $table->index(['owner_table', 'state', 'id'], 'filoquent_owner_uploads');
        });

        Schema::create('filoquent_upload_namespace', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->uuid('namespace');
            $table->string('gc_after_table', 128)->nullable();
        });

        DB::table('filoquent_upload_namespace')->insert(['id' => 1, 'namespace' => (string) Str::uuid()]);

        Schema::create('filoquent_owner_tables', function (Blueprint $table) use ($driver): void {
            $owner = $table->string('owner_table', 128)->primary();

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $owner->charset('utf8mb4')->collation('utf8mb4_bin');
            } elseif ($driver === 'sqlsrv') {
                $owner->collation('Latin1_General_100_BIN2');
            }
            $table->string('state', 10)->default('ready');
            $table->uuid('issue_id')->nullable();
            $table->dateTime('detected_at', 6)->nullable();
            $table->dateTime('last_reported_at', 6)->nullable();
            $table->unsignedBigInteger('affected_uploads')->default(0);
            $table->dateTime('resolved_at', 6)->nullable();
            $table->string('resolution', 16)->nullable();
            $table->boolean('requires_qualified_rename')->default(false);
            $table->string('resolved_to', 128)->nullable();
            $table->index(['state', 'last_reported_at', 'owner_table'], 'filoquent_owner_reports');
            $table->index(['state', 'owner_table'], 'filoquent_owner_pending');
        });
    }

    public function down(): void
    {
        throw_if(DB::table('filoquent_owner_tables')->useWritePdo()->where('state', 'pending')->exists()
            || DB::table('filoquent_uploads')->useWritePdo()->whereNotNull('journal_owner_table')->exists(), FiloquentException::class, 'Resolve pending owner table issues and preserve all recovery aliases before rolling back owner table coordination.');

        if (config('filoquent.redis.database') !== null
            || DB::table('filoquent_uploads')->useWritePdo()->whereNull('owner_key')->orWhereIn('state', ['pending', 'writing'])->exists()) {
            throw new FiloquentException('Stop Filoquent writers, clean up pending uploads, and disable the Redis journal before rolling back the Filoquent migration.');
        }

        Schema::dropIfExists('filoquent_owner_tables');
        Schema::dropIfExists('filoquent_upload_namespace');
        Schema::dropIfExists('filoquent_uploads');
    }
};
