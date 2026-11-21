<?php

declare(strict_types=1);

namespace App\Franken;

use App\Testing\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ErrataTest extends TestCase
{
    #[Test]
    public function swaps_within_the_same_category_are_offered(): void
    {
        $errata = new Errata([
            'ABILITY:star_forge' => [
                'id' => 'ABILITY:star_forge',
                'optional_swaps' => ['ABILITY:star_forge_alt'],
            ],
        ]);

        $this->assertEquals(
            ['ABILITY:star_forge_alt'],
            array_map(
                static fn (ComponentId $id) => $id->value(),
                $errata->swapsFor(ComponentId::fromString('ABILITY:star_forge')),
            ),
        );
    }

    #[Test]
    public function swaps_into_a_different_category_are_dropped(): void
    {
        $errata = new Errata([
            'HOMESYSTEM:nivyn' => [
                'id' => 'HOMESYSTEM:nivyn',
                'optional_swaps' => ['ABILITY:celestial_guides'],
            ],
        ]);

        $this->assertSame([], $errata->swapsFor(ComponentId::fromString('HOMESYSTEM:nivyn')));
    }

    #[Test]
    public function a_mix_of_same_and_cross_category_swaps_keeps_only_the_matching_ones(): void
    {
        $errata = new Errata([
            'ABILITY:glory' => [
                'id' => 'ABILITY:glory',
                'optional_swaps' => [
                    'MECH:kjalengard_mech',
                    'ABILITY:glory_alt',
                    'HERO:kjalengardhero',
                ],
            ],
        ]);

        $this->assertEquals(
            ['ABILITY:glory_alt'],
            array_map(
                static fn (ComponentId $id) => $id->value(),
                $errata->swapsFor(ComponentId::fromString('ABILITY:glory')),
            ),
        );
    }

    #[Test]
    public function the_real_errata_data_has_no_cross_category_swaps_left(): void
    {
        $errata = Errata::load();

        foreach (self::rawEntries() as $entry) {
            $id = ComponentId::tryFromString((string) $entry['id']);

            if ($id === null) {
                continue;
            }

            foreach ($errata->swapsFor($id) as $option) {
                $this->assertSame(
                    $id->category,
                    $option->category,
                    $id->value() . ' should not offer a swap into ' . $option->value(),
                );
            }
        }
    }

    /** @return array<int, array<string, mixed>> */
    private static function rawEntries(): array
    {
        return (array) json_decode((string) file_get_contents('data/franken/errata.json'), true);
    }
}
