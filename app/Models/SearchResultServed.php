<?php

namespace App\Models;

use Database\Factories\SearchResultServedFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One row per artifact `SearchService::search()` returned (spec 16 /
 * RUB-314). Matched to a later view by team, artifact and time — never by
 * identity — to record a `retrieved_then_opened` usage event even when the
 * search and the open happen in unrelated sessions.
 *
 * @property int $id
 * @property int $team_id
 * @property string $artifact_id
 * @property Carbon $served_at
 * @property Carbon|null $opened_at set when a view has claimed this served
 *                                  row as its "retrieved, then opened"
 *                                  correlation — null means unclaimed
 */
#[Fillable(['team_id', 'artifact_id', 'served_at', 'opened_at'])]
class SearchResultServed extends Model
{
    /** @use HasFactory<SearchResultServedFactory> */
    use HasFactory;

    protected $table = 'search_results_served';

    public $timestamps = false;

    protected $casts = [
        'served_at' => 'datetime',
        'opened_at' => 'datetime',
    ];
}
