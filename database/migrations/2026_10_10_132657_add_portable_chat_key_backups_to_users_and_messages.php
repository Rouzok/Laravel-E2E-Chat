<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('public_key_version')->default(0);
            $table->text('password_key_backup')->nullable();
            $table->text('recovery_key_backup')->nullable();
            $table->string('recovery_key_verifier', 64)->nullable();
        });

        Schema::create('user_public_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('public_key');
            $table->timestamps();
            $table->unique(['user_id', 'version']);
        });

        DB::table('users')->whereNotNull('public_key')->orderBy('id')->each(function (object $user): void {
            DB::table('users')->where('id', $user->id)->update(['public_key_version' => 1]);
            DB::table('user_public_keys')->insert([
                'user_id' => $user->id,
                'version' => 1,
                'public_key' => $user->public_key,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->unsignedInteger('sender_key_version')->nullable();
            $table->unsignedInteger('receiver_key_version')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['sender_key_version', 'receiver_key_version']);
        });

        Schema::dropIfExists('user_public_keys');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'public_key_version',
                'password_key_backup',
                'recovery_key_backup',
                'recovery_key_verifier',
            ]);
        });
    }
};
