<?php

return [
    // Calibration hypotheses, not guarantees. Keep the public criteria in the documentation.
    // 20-second ceiling accepted on 2026-11-05; reserve three seconds for the response.
    'work_seconds' => 17.0,
    'statement_seconds' => 2.0,
    'batch_size' => 200,
    'max_word_candidates' => 10000,
    'max_occurrences' => 200000,
    'max_batch_occurrences' => 10000,
    'max_memory_bytes' => 128 * 1024 * 1024,
    'max_spool_bytes' => 16 * 1024 * 1024,
];
