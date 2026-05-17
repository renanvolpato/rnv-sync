<?php

use App\Support\Bytes;

it('formats bytes into human readable units', function () {
    expect(Bytes::human(0))->toBe('0 B')
        ->and(Bytes::human(1024))->toBe('1.0 KB')
        ->and(Bytes::human(1099511627776))->toBe('1.0 TB')
        ->and(Bytes::human(null))->toBe('—');
});
