<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('cmf_mcp_tokens', 'issued_by')) {
            return;
        }

        $userModel = config('cmf-users.model');
        $user = new $userModel;

        Schema::table('cmf_mcp_tokens', function (Blueprint $table) use ($user): void {
            $table->foreignIdFor($user, 'issued_by')
                ->nullable()
                ->after('user_id')
                ->constrained($user->getTable(), $user->getKeyName())
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('cmf_mcp_tokens', 'issued_by')) {
            return;
        }

        Schema::table('cmf_mcp_tokens', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('issued_by');
        });
    }
};
