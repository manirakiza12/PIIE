<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentMethods extends Model
{
    use HasFactory;
    public $timestamps=false;
    protected $table = 'payment_methods';

    protected $fillable = [ 'id','name', 'payment_keys','image','status','mode','currency','currency_position','created_at','updated_at','school_id' ];

    protected static function booted(): void
    {
        static::saving(function (self $row) {
            if ($row->name === 'pesapal') {
                $row->payment_keys = \App\Support\Payments\PesaPalCredentialStorage::protect(
                    (string) $row->payment_keys, (int) $row->school_id);
            }
        });
    }

    public function toArray(): array
    {
        $data = parent::toArray();
        if ($this->name === 'pesapal') { unset($data['payment_keys']); }
        return $data;
    }

}
