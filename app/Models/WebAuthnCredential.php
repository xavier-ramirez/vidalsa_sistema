<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebAuthnCredential extends Model
{
    public $timestamps = false;

    protected $table = 'webauthn_credentials';

    protected $fillable = [
        'ID_USUARIO',
        'credential_id',
        'public_key_pem',
        'nombre_dispositivo',
        'counter',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'ID_USUARIO', 'ID_USUARIO');
    }
}
