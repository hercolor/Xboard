<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_user', 'phone')) {
                $table->string('phone', 32)->nullable()->unique('phone')->after('email')->comment('手机号');
            }
        });
    }

    public function down(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            if (Schema::hasColumn('v2_user', 'phone')) {
                $table->dropUnique('phone');
                $table->dropColumn('phone');
            }
        });
    }
};
