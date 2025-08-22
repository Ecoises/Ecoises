<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserFollow extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'follower_id',
        'following_id',
    ];

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'user_follows';
    
    // Relaciones
    
    public function follower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'follower_id');
    }
    
    public function following(): BelongsTo
    {
        return $this->belongsTo(User::class, 'following_id');
    }
}