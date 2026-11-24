<?php

use App\Support\Money;

it('parses and prints exact nine-place decimals', function (string $in, int $nanos, string $out) {
    $money = Money::fromDecimal($in);

    expect($money->nanos)->toBe($nanos)
        ->and($money->toDecimal())->toBe($out);
})->with([
    ['0.046125000', 46_125_000, '0.046125000'],
    ['5', 5_000_000_000, '5.000000000'],
    ['12.50', 12_500_000_000, '12.500000000'],
    ['0.000000001', 1, '0.000000001'],
    ['-1.5', -1_500_000_000, '-1.500000000'],
]);

it('refuses amounts it would have to round', function () {
    Money::fromDecimal('0.0000000001');
})->throws(InvalidArgumentException::class);

it('adds the markup and rounds sub-nanodollar remainders up', function () {
    expect(Money::nanos(305_510_000)->withMarkup(25)->nanos)->toBe(381_887_500)
        ->and(Money::nanos(1)->withMarkup(25)->nanos)->toBe(2)   // 1.25 -> 2, never undercharge
        ->and(Money::nanos(4)->withMarkup(25)->nanos)->toBe(5)
        ->and(Money::zero()->withMarkup(25)->nanos)->toBe(0);
});
