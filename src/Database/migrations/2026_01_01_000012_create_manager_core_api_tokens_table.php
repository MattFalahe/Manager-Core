<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateManagerCoreApiTokensTable extends Migration
{
    public function up(): void
    {
        Schema::create('manager_core_api_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('name', 255);
            $table->string('token', 80)->unique();
            // Hash algorithm version (folded from 000014). Lets new tokens use
            // a peppered SHA-256 while old tokens continue to validate via the
            // unsalted v1 hash. Bumped when the pepper rolls.
            $table->unsignedTinyInteger('hash_version')->default(1);
            $table->string('token_prefix', 10);
            $table->json('scopes')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('rate_limit')->default(60);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('token', 'mc_api_token');
            $table->index('user_id', 'mc_api_user');
            $table->index('is_active', 'mc_api_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manager_core_api_tokens');
    }
}
