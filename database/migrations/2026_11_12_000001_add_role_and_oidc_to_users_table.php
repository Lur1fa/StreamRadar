<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 16)->default('user')->index()->after('email');
            $table->string('password')->nullable()->change();
            $table->string('oidc_issuer', 255)->nullable()->after('remember_token');
            $table->string('oidc_subject', 255)->nullable()->after('oidc_issuer');
            $table->timestamp('last_login_at')->nullable()->after('oidc_subject');
            $table->timestamp('disabled_at')->nullable()->after('last_login_at');
            $table->unique(['oidc_issuer', 'oidc_subject']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['oidc_issuer', 'oidc_subject']);
            $table->dropColumn(['role', 'oidc_issuer', 'oidc_subject', 'last_login_at', 'disabled_at']);
        });
    }
};
