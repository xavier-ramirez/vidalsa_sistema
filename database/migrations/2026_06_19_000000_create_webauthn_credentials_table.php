<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webauthn_credentials', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ID_USUARIO');
            $table->string('credential_id', 512)->unique();
            $table->text('public_key_pem');
            $table->string('nombre_dispositivo', 150)->nullable();
            $table->unsignedInteger('counter')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->foreign('ID_USUARIO')
                  ->references('ID_USUARIO')->on('usuarios')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webauthn_credentials');
    }
};
