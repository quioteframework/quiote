<?php

use PHPUnit\Framework\TestCase;
use Quiote\I18n\DateTimeFacade;

class DateTimeFacadeTest extends TestCase
{
    public function testParseAndFormatBasicPattern(): void
    {
        $dt = DateTimeFacade::parse('2008-11-19 23:00:00', 'yyyy-MM-dd HH:mm:ss', 'Europe/Berlin');
        $this->assertEquals('2008-11-19 23:00:00', DateTimeFacade::format($dt, 'yyyy-MM-dd HH:mm:ss'));
        $this->assertEquals('Europe/Berlin', $dt->getTimezone()->getName());
    }

    public function testParseUTCAndFormatDifferentTimezone(): void
    {
        $dt = DateTimeFacade::parse('2025-10-01 12:30:15', 'yyyy-MM-dd HH:mm:ss', 'UTC');
        $berlin = $dt->setTimezone(new DateTimeZone('Europe/Berlin'));
        $this->assertEquals('2025-10-01 12:30:15', DateTimeFacade::format($dt, 'yyyy-MM-dd HH:mm:ss'));
        $this->assertEquals('2025-10-01 14:30:15', DateTimeFacade::format($berlin, 'yyyy-MM-dd HH:mm:ss'));
    }

    public function testRoundTripFallbackPattern(): void
    {
        $pattern = 'yyyy-MM-dd HH:mm:ss';
        $original = '2030-02-05 07:08:09';
        $dt = DateTimeFacade::parse($original, $pattern, 'UTC');
        $this->assertSame($original, DateTimeFacade::format($dt, $pattern));
    }

    public function testUnsupportedTokenThrows(): void
    {
        $this->expectException(RuntimeException::class);
        DateTimeFacade::format(new DateTimeImmutable('now', new DateTimeZone('UTC')), 'yyyy-MM-dd XXX');
    }

    public function testFormatterCacheReusesInstanceForSameLocaleTimezoneAndPattern(): void
    {
        if (!class_exists(\IntlDateFormatter::class)) {
            $this->markTestSkipped('ext/intl not available');
        }

        $cacheProp = new ReflectionProperty(DateTimeFacade::class, 'formatterCache');
        $cacheProp->setValue(null, []);

        $dt1 = new DateTimeImmutable('2020-01-01 00:00:00', new DateTimeZone('UTC'));
        $dt2 = new DateTimeImmutable('2021-06-15 12:00:00', new DateTimeZone('UTC'));
        DateTimeFacade::format($dt1, 'yyyy-MM-dd', 'en_US');
        DateTimeFacade::format($dt2, 'yyyy-MM-dd', 'en_US');

        /** @var array<string, \IntlDateFormatter> $cache */
        $cache = $cacheProp->getValue();
        $this->assertCount(1, $cache, 'same locale/tz/pattern must reuse a single cached IntlDateFormatter');
    }

    public function testFormatterCacheIsKeyedByLocaleTimezoneAndPatternIndependently(): void
    {
        if (!class_exists(\IntlDateFormatter::class)) {
            $this->markTestSkipped('ext/intl not available');
        }

        $cacheProp = new ReflectionProperty(DateTimeFacade::class, 'formatterCache');
        $cacheProp->setValue(null, []);

        $dt = new DateTimeImmutable('2020-01-01 00:00:00', new DateTimeZone('UTC'));
        DateTimeFacade::format($dt, 'yyyy-MM-dd', 'en_US');
        DateTimeFacade::format($dt, 'dd-MM-yyyy', 'en_US'); // different pattern
        DateTimeFacade::parse('2020-01-01 00:00:00', 'yyyy-MM-dd HH:mm:ss', 'Europe/Berlin', 'en_US'); // different tz+pattern

        /** @var array<string, \IntlDateFormatter> $cache */
        $cache = $cacheProp->getValue();
        $this->assertCount(3, $cache, 'distinct (locale, tz, pattern) combinations must not collide in the cache');
    }

    public function testFormatAndParseShareTheCacheForTheSameKey(): void
    {
        if (!class_exists(\IntlDateFormatter::class)) {
            $this->markTestSkipped('ext/intl not available');
        }

        $cacheProp = new ReflectionProperty(DateTimeFacade::class, 'formatterCache');
        $cacheProp->setValue(null, []);

        $pattern = 'yyyy-MM-dd HH:mm:ss';
        $dt = DateTimeFacade::parse('2022-03-04 05:06:07', $pattern, 'UTC', 'en_US');
        DateTimeFacade::format($dt, $pattern, 'en_US');

        /** @var array<string, \IntlDateFormatter> $cache */
        $cache = $cacheProp->getValue();
        $this->assertCount(1, $cache, 'parse() and format() for the same (locale, tz, pattern) must share one cached formatter');
    }
}
