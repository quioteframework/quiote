<?php

use Quiote\Request\Attribute\MapRequest;
use Quiote\Request\RequestDtoMapper;
use Quiote\Request\WebRequest;
use Quiote\Testing\UnitTestCase;

enum MapperFixtureColor: string
{
    case Red = 'red';
    case Blue = 'blue';
}

#[MapRequest]
final readonly class MapperFixtureDto
{
    /**
     * @param array<int|string, mixed> $tags
     */
    public function __construct(
        public string $name,
        public int $age,
        public float $ratio,
        public bool $active,
        public array $tags,
        public DateTimeImmutable $when,
        public MapperFixtureColor $color,
        public ?string $nickname = null,
    ) {
    }
}

#[MapRequest]
final readonly class MapperFixtureRequiredDto
{
    public function __construct(
        public string $name,
    ) {
    }
}

class RequestDtoMapperTest extends UnitTestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function baseParams(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ada',
            'age' => 30,
            'ratio' => 0.5,
            'active' => true,
            'tags' => '["a","b"]',
            'when' => '2024-01-02 03:04:05',
            'color' => 'red',
        ], $overrides);
    }

    private function mapContactDto(WebRequest $request): MapperFixtureDto
    {
        $dto = RequestDtoMapper::map($request, MapperFixtureDto::class);
        if (!$dto instanceof MapperFixtureDto) {
            $this->fail('Expected a MapperFixtureDto instance.');
        }
        return $dto;
    }

    public function testMapsAllSupportedTypes(): void
    {
        $request = $this->newWebRequest($this->baseParams());

        $dto = $this->mapContactDto($request);

        $this->assertSame('Ada', $dto->name);
        $this->assertSame(30, $dto->age);
        $this->assertSame(0.5, $dto->ratio);
        $this->assertTrue($dto->active);
        $this->assertSame(['a', 'b'], $dto->tags);
        $this->assertEquals(new DateTimeImmutable('2024-01-02 03:04:05'), $dto->when);
        $this->assertSame(MapperFixtureColor::Red, $dto->color);
        $this->assertNull($dto->nickname);
    }

    public function testMapCastsStringNumericInputsAndArrayPassthrough(): void
    {
        $request = $this->newWebRequest($this->baseParams([
            'age' => '30',
            'ratio' => '0.5',
            'active' => '1',
            'tags' => [1, 2],
            'color' => 'blue',
        ]));

        $dto = $this->mapContactDto($request);

        $this->assertSame(30, $dto->age);
        $this->assertSame(0.5, $dto->ratio);
        $this->assertTrue($dto->active);
        $this->assertSame([1, 2], $dto->tags);
        $this->assertSame(MapperFixtureColor::Blue, $dto->color);
    }

    public function testMapUsesDefaultWhenOptionalParameterAbsent(): void
    {
        $request = $this->newWebRequest($this->baseParams());

        $dto = $this->mapContactDto($request);

        $this->assertNull($dto->nickname);
    }

    public function testMapThrowsWhenRequiredPropertyAbsentAfterWhitelist(): void
    {
        // Simulates the scenario the guard exists for: the property was
        // whitelisted (as if a validator had run) but no value was ever set.
        $request = $this->newWebRequest([], ['name']);

        $this->expectException(RuntimeException::class);
        RequestDtoMapper::map($request, MapperFixtureRequiredDto::class);
    }

    public function testMapThrowsOnInvalidJson(): void
    {
        $request = $this->newWebRequest($this->baseParams(['tags' => 'not-json']));

        $this->expectException(RuntimeException::class);
        RequestDtoMapper::map($request, MapperFixtureDto::class);
    }

    public function testMapThrowsOnUnparseableDateTime(): void
    {
        $request = $this->newWebRequest($this->baseParams(['when' => "\0invalid\0"]));

        $this->expectException(RuntimeException::class);
        RequestDtoMapper::map($request, MapperFixtureDto::class);
    }

    public function testMapThrowsOnInvalidEnumValue(): void
    {
        $request = $this->newWebRequest($this->baseParams(['color' => 'purple']));

        $this->expectException(ValueError::class);
        RequestDtoMapper::map($request, MapperFixtureDto::class);
    }
}
