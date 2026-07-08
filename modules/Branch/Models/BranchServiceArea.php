<?php

namespace Modules\Branch\Models;

use Illuminate\Database\Eloquent\Model;

class BranchServiceArea extends Model
{
    protected $guarded = [];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
