<?php

namespace Tests\Feature;

use App\Models\PhoneCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UnusableTranscriptRollbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_prepared_sql_is_count_checked_preserves_data_and_is_idempotent(): void
    {
        foreach (['unusable', 'unusable', 'completed', 'failed', 'pending'] as $status) {
            PhoneCall::forceCreate([
                'call_uuid' => (string) \Illuminate\Support\Str::uuid(),
                'direction' => 'inbound', 'status' => 'voicemail',
                'from_number' => '+15555550101', 'to_number' => '+15555550102',
                'transcription_status' => $status, 'transcription' => 'Synthetic preserved text',
                'transcription_error' => 'Synthetic preserved marker',
            ]);
        }
        $before = DB::table('phone_calls')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $expected = DB::table('phone_calls')->where('transcription_status', 'unusable')->count();
        $this->assertSame(2, $expected);
        $sql = file_get_contents(base_path('docs/rollback-unusable-transcripts.sql'));
        $affected = DB::affectingStatement($sql);
        $this->assertSame($expected, $affected);
        $this->assertSame(0, DB::table('phone_calls')->where('transcription_status', 'unusable')->count());
        foreach ($before as &$row) {
            if ($row['transcription_status'] === 'unusable') {
                $row['transcription_status'] = 'completed';
            }
        }
        unset($row);
        $this->assertSame($before, DB::table('phone_calls')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame(0, DB::affectingStatement($sql));
    }
}
