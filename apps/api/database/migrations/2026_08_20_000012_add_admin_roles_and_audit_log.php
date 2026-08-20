<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin roles and the mutation audit log (MASTER BUILD PROMPT sections 11, 18).
 *
 * Roles are a single column rather than a permissions package: there are five
 * of them, they are fixed, and they map to job functions rather than to
 * arbitrary permission sets. A full RBAC package would add a dependency and a
 * migration surface to express what an enum already expresses.
 *
 * Authorisation is enforced by policies, never by hiding UI (section 11).
 */
return new class extends Migration
{
    protected $connection = 'pgsql_direct';

    public function up(): void
    {
        Schema::connection($this->connection)->table('users', function (Blueprint $table): void {
            $table->string('role', 32)->default('viewer')->index();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();

            // Filament v5 app-based two-factor authentication (section 18).
            // Encrypted at rest by the cast on the model, and hidden from
            // serialisation — a leaked secret is a bypassed second factor.
            $table->text('app_authentication_secret')->nullable();
            $table->text('app_authentication_recovery_codes')->nullable();
        });

        DB::connection($this->connection)->statement("
            ALTER TABLE users ADD CONSTRAINT users_role_valid
            CHECK (role IN ('super_admin','catalogue_manager','sales','content_editor','viewer'))
        ");

        /*
         * Audit log — who, what, when, before and after (section 18).
         *
         * Append-only by intent. Records the trace_id so an admin action can be
         * correlated with the request that caused it and with the render routes
         * it invalidated.
         */
        Schema::connection($this->connection)->create('audit_log', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();

            $table->unsignedBigInteger('user_id')->nullable();
            // Retained even if the user is deleted: an audit trail that loses
            // its actor is not an audit trail.
            $table->string('user_label')->nullable();

            $table->string('event', 32);            // created | updated | deleted | restored
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');
            $table->string('auditable_label')->nullable();

            // Only the attributes that actually changed. Storing whole rows
            // makes the log enormous and the diff invisible.
            $table->jsonb('before')->default(DB::raw("'{}'::jsonb"));
            $table->jsonb('after')->default(DB::raw("'{}'::jsonb"));

            $table->string('ip', 45)->nullable();
            $table->uuid('trace_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('audit_log');

        DB::connection($this->connection)->statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_valid');

        Schema::connection($this->connection)->table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'role', 'is_active', 'last_login_at', 'last_login_ip',
                'app_authentication_secret', 'app_authentication_recovery_codes',
            ]);
        });
    }
};
