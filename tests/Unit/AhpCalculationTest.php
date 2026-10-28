<?php

namespace Tests\Unit;

use App\Services\AhpService;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AhpCalculationTest extends TestCase
{
    public function test_known_consistent_matrix_produces_eigenvector_and_zero_consistency_ratio(): void
    {
        $result = (new AhpService)->calculateMatrix([[1, 2, 4], [0.5, 1, 2], [0.25, 0.5, 1]]);
        foreach ([4 / 7, 2 / 7, 1 / 7] as $i => $expected) {
            $this->assertEqualsWithDelta($expected, $result['weights'][$i], 1e-11);
        }
        $this->assertEqualsWithDelta(3, $result['lambda_max'], 1e-11);
        $this->assertEqualsWithDelta(0, $result['consistency_index'], 1e-11);
        $this->assertEqualsWithDelta(0, $result['consistency_ratio'], 1e-11);
        foreach ([0, 1, 2] as $j) {
            $this->assertEqualsWithDelta(1, array_sum(array_column($result['normalized_matrix'], $j)), 1e-11);
        }
        $this->assertTrue($result['consistent']);
    }

    public function test_one_two_and_inconsistent_three_criterion_cases(): void
    {
        $service = new AhpService;
        $this->assertSame([1.0], $service->calculateMatrix([[1]])['weights']);
        $two = $service->calculateMatrix([[1, 3], [1 / 3, 1]]);
        $this->assertEqualsWithDelta(0.75, $two['weights'][0], 1e-11);
        $this->assertSame(0.0, $two['consistency_ratio']);
        $bad = $service->calculateMatrix([[1, 9, 1 / 9], [1 / 9, 1, 9], [9, 1 / 9, 1]]);
        $this->assertFalse($bad['consistent']);
        $this->assertGreaterThan(0.10, $bad['consistency_ratio']);
    }

    public function test_empty_criteria_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        (new AhpService)->calculateMatrix([]);
    }

    public static function invalidMatrices(): array
    {
        return [
            'not square' => [[[1, 2], [0.5]]],
            'not reciprocal' => [[[1, 3], [0.5, 1]]],
            'invalid diagonal' => [[[2]]],
            'nonnumeric' => [[[1, 3], ['bad', 1]]],
            'invalid row' => [[[1, 3], null]],
            'outside scale' => [[[1, 100], [0.01, 1]]],
            'too many criteria' => [array_fill(0, 11, array_fill(0, 11, 1))],
        ];
    }

    #[DataProvider('invalidMatrices')]
    public function test_malformed_matrices_produce_validation_errors(array $matrix): void
    {
        $this->expectException(ValidationException::class);
        (new AhpService)->calculateMatrix($matrix);
    }

    public function test_ten_equal_criteria_have_equal_weights_and_zero_cr(): void
    {
        $result = (new AhpService)->calculateMatrix(array_fill(0, 10, array_fill(0, 10, 1)));
        foreach ($result['weights'] as $weight) {
            $this->assertEqualsWithDelta(0.1, $weight, 1e-12);
        }
        $this->assertEqualsWithDelta(1, array_sum($result['weights']), 1e-12);
        $this->assertEqualsWithDelta(0, $result['consistency_ratio'], 1e-12);
    }
}
