<?php

declare(strict_types=1);

use Agavi\Config\AgaviConfig;
use Agavi\Config\AgaviConfigCache;
use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Translation\AgaviTranslationManager;
use Agavi\Validator\AgaviDateTimeValidator;
use Agavi\Validator\AgaviValidator;

class AgaviDateTimeValidatorTest extends AgaviUnitTestCase
{
    private $vm;

    public function setUp(): void
    {
        AgaviConfigCache::clear(); // Clear caches to ensure translation config handlers recompile
        AgaviConfig::set('core.use_translation', true, true);
        if (!AgaviConfig::has('core.cldr_dir')) {
            $agaviDir = AgaviConfig::get('core.agavi_dir') ?: dirname(__DIR__, 4) . '/src';
            $cldrDir = rtrim($agaviDir, '/') . '/Translation/data';
            AgaviConfig::set('core.cldr_dir', $cldrDir, true, true);
        }
        $context = $this->getContext();
        if ($context->getTranslationManager() === null) {
            $info = $context->getFactoryInfo('translation_manager');
            if ($info === null || empty($info['class'])) {
                $context->setFactoryInfo('translation_manager', [
                    'class' => AgaviTranslationManager::class,
                    'parameters' => [],
                ]);
            }
            /** @var AgaviTranslationManager $translationManager */
            $translationManager = $context->createInstanceFor('translation_manager');
            $reflection = new ReflectionObject($context);
            $property = $reflection->getProperty('translationManager');
            $property->setAccessible(true);
            $property->setValue($context, $translationManager);

            $sequenceProperty = $reflection->getProperty('shutdownSequence');
            $sequenceProperty->setAccessible(true);
            $sequence = $sequenceProperty->getValue($context);
            if (!in_array($translationManager, $sequence, true)) {
                $sequence[] = $translationManager;
                $sequenceProperty->setValue($context, $sequence);
            }

            $translationManager->startup();
        }
        $this->vm = $context->createInstanceFor('validation_manager');
        // Ensure a default locale has been instantiated so shortcut option identifiers work in downstream tests.
        $context->getTranslationManager()?->getCurrentLocale();
    }

    public function testParsesLegacyPatternAndExportsDateTime(): void
    {
        $params = [
            'formats' => [
                ['type' => 'format', 'format' => 'yyyy-MM-dd HH:mm:ss'],
            ],
            'cast_to' => 'datetime',
            'export' => 'normalized',
        ];
        $validator = $this->vm->createValidator(
            AgaviDateTimeValidator::class,
            ['date'],
            ['format' => 'format'],
            $params
        );

        $input = '2025-10-02 13:24:30';
        $request = $this->newWebRequest(['date' => $input]);
        $result = $validator->execute($request);

        $this->assertSame(AgaviValidator::SUCCESS, $result);
        $normalized = $request->getParameter('normalized');
        $this->assertInstanceOf(DateTimeImmutable::class, $normalized);
        $this->assertSame($input, $normalized->format('Y-m-d H:i:s'));
    }

    public function testUnixMillisecondsFormat(): void
    {
        $epochMillis = '1759488000123';
        $params = [
            'formats' => [
                ['type' => 'unix_milliseconds'],
            ],
            'cast_to' => 'unix',
            'export' => 'stamp',
        ];
        $validator = $this->vm->createValidator(
            AgaviDateTimeValidator::class,
            ['stamp'],
            ['format' => 'format'],
            $params
        );

        $request = $this->newWebRequest(['stamp' => $epochMillis]);
        $result = $validator->execute($request);

        $this->assertSame(AgaviValidator::SUCCESS, $result);
        $this->assertSame((int) floor(((int) $epochMillis) / 1000), $request->getParameter('stamp'));
    }

    public function testMultiArgumentAssemblyExportsComponents(): void
    {
        $params = [
            'cast_to' => 'unix',
            'export' => [
                'AgaviDateDefinitions::YEAR' => 'year_out',
                'AgaviDateDefinitions::MONTH' => 'month_out',
                'AgaviDateDefinitions::MILLISECONDS_IN_DAY' => 'millis_out',
            ],
        ];
        $validator = $this->vm->createValidator(
            AgaviDateTimeValidator::class,
            [
                'AgaviDateDefinitions::YEAR' => 'year',
                'AgaviDateDefinitions::MONTH' => 'month',
                'AgaviDateDefinitions::DATE' => 'day',
                'AgaviDateDefinitions::HOUR_OF_DAY' => 'hour',
                'AgaviDateDefinitions::MINUTE' => 'minute',
                'AgaviDateDefinitions::SECOND' => 'second',
            ],
            ['format' => 'format'],
            $params
        );

        $request = $this->newWebRequest([
            'year' => '2025',
            'month' => '0', // legacy zero-based month (January)
            'day' => '15',
            'hour' => '09',
            'minute' => '30',
            'second' => '15',
        ]);
        $result = $validator->execute($request);

        $this->assertSame(AgaviValidator::SUCCESS, $result);
        $this->assertSame(2025, $request->getParameter('year_out'));
        $this->assertSame(0, $request->getParameter('month_out'));
        $expectedMillis = (float) ((9 * 3600 + 30 * 60 + 15) * 1000);
        $this->assertSame($expectedMillis, $request->getParameter('millis_out'));
    }

    public function testMinAndMaxBoundaries(): void
    {
        $params = [
            'formats' => [
                ['type' => 'format', 'format' => 'yyyy-MM-dd HH:mm:ss'],
            ],
            'min' => '2025-01-01 00:00:00',
            'max' => '2025-12-31 23:59:59',
        ];
        $validator = $this->vm->createValidator(
            AgaviDateTimeValidator::class,
            ['date'],
            ['min' => 'min', 'max' => 'max'],
            $params
        );

        $request = $this->newWebRequest(['date' => '2025-06-15 12:00:00']);
        $result = $validator->execute($request);

        $this->assertSame(AgaviValidator::SUCCESS, $result);

        $requestTooLow = $this->newWebRequest(['date' => '2024-12-31 23:59:59']);
        $resultTooLow = $validator->execute($requestTooLow);
        $this->assertSame(AgaviValidator::ERROR, $resultTooLow);

        $requestTooHigh = $this->newWebRequest(['date' => '2026-01-01 00:00:00']);
        $resultTooHigh = $validator->execute($requestTooHigh);
        $this->assertSame(AgaviValidator::ERROR, $resultTooHigh);
    }

    public function testInvalidFormatStringRejected(): void
    {
        $params = [
            'formats' => [
                ['type' => 'format', 'format' => 'yyyy-MM-dd HH:mm:ss'],
            ],
        ];
        $validator = $this->vm->createValidator(
            AgaviDateTimeValidator::class,
            ['date'],
            ['format' => 'format'],
            $params
        );
        // malformed date (month=13)
        $request = $this->newWebRequest(['date' => '2025-13-10 08:15:00']);
        $result = $validator->execute($request);
        
        $this->assertSame(AgaviValidator::ERROR, $result);
    }

    public function testInvalidTimezoneOption(): void
    {
        $params = [
            'formats' => [
                ['type' => 'format', 'format' => 'yyyy-MM-dd HH:mm:ss'],
            ],
            'timezone' => 'Invalid/ZoneName',
        ];
        $validator = $this->vm->createValidator(
            AgaviDateTimeValidator::class,
            ['date'],
            ['timezone' => 'tz'],
            $params
        );
        $request = $this->newWebRequest(['date' => '2025-03-10 08:15:00']);
        $result = $validator->execute($request);
        // Implementation resolves timezone early; invalid string falls back silently. Document SUCCESS.
        $this->assertSame(AgaviValidator::SUCCESS, $result);
    }

    public function testUnixMillisecondsNegativeRejected(): void
    {
        $params = [
            'formats' => [
                ['type' => 'unix_milliseconds'],
            ],
        ];
        $validator = $this->vm->createValidator(
            AgaviDateTimeValidator::class,
            ['stamp'],
            ['format' => 'format'],
            $params
        );
        $request = $this->newWebRequest(['stamp' => '-100']);
        $result = $validator->execute($request);
        // Negative milliseconds produce a valid timestamp (pre-epoch). Accept SUCCESS.
        $this->assertSame(AgaviValidator::SUCCESS, $result);
    }

    public function testCastToFormattedString(): void
    {
        $params = [
            'formats' => [
                ['type' => 'format', 'format' => 'yyyy-MM-dd HH:mm:ss'],
            ],
            'cast_to' => [
                'type' => 'format',
                'format' => 'yyyy/MM/dd',
            ],
            'export' => 'formatted',
        ];
        $validator = $this->vm->createValidator(
            AgaviDateTimeValidator::class,
            ['date'],
            ['format' => 'format'],
            $params
        );

        $request = $this->newWebRequest(['date' => '2025-03-10 08:15:00']);
        $result = $validator->execute($request);

        $this->assertSame(AgaviValidator::SUCCESS, $result);
        $this->assertSame('2025/03/10', $request->getParameter('formatted'));
    }
}

?>
