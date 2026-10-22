<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * When forms run by themselves. code is a keyword from KEYWORDS or a cron expression
 * ("0 6 * * 1" is Mondays at 06:00), in the app's timezone. Its meta_schedules say which
 * form runs over which datagrid's rows.
 */
class Schedule extends Model
{
    /** Keywords a code may be instead of a cron expression. */
    public const KEYWORDS = [
        'every_minute' => '* * * * *',
        'every_five_minutes' => '*/5 * * * *',
        'hourly' => '0 * * * *',
        'daily' => '0 0 * * *',
        'weekly' => '0 0 * * 1',
        'monthly' => '0 0 1 * *',
        'yearly' => '0 0 1 1 *',
    ];

    protected $fillable = ['code', 'name'];

    protected $appends = ['expression', 'next_run_at'];

    public function forms(): HasMany
    {
        return $this->hasMany(MetaSchedule::class);
    }

    /** The cron expression a code stands for, or null when it is neither a keyword nor valid cron. */
    public static function expressionFor(string $code): ?string
    {
        $code = trim($code);
        $expression = self::KEYWORDS[strtolower($code)] ?? $code;

        return CronExpression::isValidExpression($expression) ? $expression : null;
    }

    public function getExpressionAttribute(): ?string
    {
        return self::expressionFor((string) $this->code);
    }

    public function getNextRunAtAttribute(): ?string
    {
        $expression = $this->expression;

        return $expression ? Carbon::instance((new CronExpression($expression))->getNextRunDate(now()))->toIso8601String() : null;
    }

    /** Whether the schedule runs in this minute. */
    public function isDue(?CarbonInterface $at = null): bool
    {
        $expression = $this->expression;

        return $expression !== null && (new CronExpression($expression))->isDue(($at ?? now())->toDateTimeString());
    }
}
