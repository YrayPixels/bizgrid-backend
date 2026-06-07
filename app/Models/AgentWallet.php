<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class AgentWallet extends Model
{
    protected $table = 'agent_wallets';

    protected $fillable = ['agent_id', 'public_key', 'encrypted_secret'];

    protected $hidden = ['encrypted_secret'];

    public function getDecryptedSecret(): string
    {
        return Crypt::decryptString($this->attributes['encrypted_secret']);
    }
}
