<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Unique, monotonic task numbers shared by task-pack sync and manual tasks.
 * Reservations are transactionally serialized on one small database row;
 * a reservation in a parent transaction is rolled back with that transaction.
 */
class TaskNumberSequenceService
{
    /** @return list<string> */
    public function reserve(int $count): array
    {
        if ($count < 0) {
            throw new \InvalidArgumentException('Cannot reserve a negative number of tasks.');
        }
        if ($count === 0) {
            return [];
        }

        return DB::transaction(function () use ($count): array {
            $counter = DB::table('task_number_sequences')
                ->where('scope', 'tasks')
                ->lockForUpdate()
                ->first(['next_value']);

            if (! $counter) {
                throw new RuntimeException('Task number sequence is missing. Run php artisan migrate.');
            }

            $first = (int) $counter->next_value;
            $next = $first + $count;
            DB::table('task_number_sequences')
                ->where('scope', 'tasks')
                ->update(['next_value' => $next]);

            $numbers = [];
            for ($number = $first; $number < $next; $number++) {
                $numbers[] = 'TSK-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT);
            }

            return $numbers;
        }, 3);
    }
}
