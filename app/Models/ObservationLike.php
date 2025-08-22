<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ObservationLike extends Model
{
    use HasFactory;
    
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'observation_id',
    ];

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'observation_likes';
    
    // Relaciones
    
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    
    public function observation(): BelongsTo
    {
        return $this->belongsTo(Observation::class);
    }
}