<?php

namespace App\Models;

use App\Support\HasUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['company_id', 'key', 'value'])]
class Setting extends Model
{
    use HasUlid;
}
