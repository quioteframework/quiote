<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Cassette\CassetteProjector;
use Quiote\Replay\Cassette\Effect;
use Quiote\Replay\Cassette\EffectKind;

final class CassetteProjectorTest extends TestCase
{
    /**
     * @param array<string, mixed> $response
     * @param list<Effect> $effects
     */
    private function cassette(array $response, array $effects = []): Cassette
    {
        return new Cassette(
            schemaVersion: CassetteCodec::CURRENT_SCHEMA_VERSION,
            meta: ['id' => 'AAA'],
            request: ['method' => 'GET', 'uri' => '/'],
            resolved: ['route' => 'orders.update'],
            session: null,
            user: null,
            effects: $effects,
            response: $response,
            exception: null,
            log: null,
        );
    }

    public function testExcerptsResponseBodyByDefault(): void
    {
        $cassette = $this->cassette(['status' => 200, 'body' => ['encoding' => 'utf8', 'content' => 'hello world', 'truncated' => false]]);

        $projection = CassetteProjector::project($cassette, includeBodies: false);

        $response = $projection['response'];
        $this->assertIsArray($response);
        $body = $response['body'];
        $this->assertIsArray($body);
        $this->assertArrayNotHasKey('content', $body);
        $this->assertSame(11, $body['length']);
        $this->assertSame(hash('sha256', 'hello world'), $body['sha256']);
    }

    public function testIncludeBodiesReturnsFullResponseContent(): void
    {
        $cassette = $this->cassette(['status' => 200, 'body' => ['encoding' => 'utf8', 'content' => 'hello world', 'truncated' => false]]);

        $projection = CassetteProjector::project($cassette, includeBodies: true);

        $response = $projection['response'];
        $this->assertIsArray($response);
        $body = $response['body'];
        $this->assertIsArray($body);
        $this->assertSame('hello world', $body['content']);
    }

    public function testExcerptsEffectRowsByDefault(): void
    {
        $effect = new Effect(1, EffectKind::Db, 'fp', ['sql' => 'SELECT 1'], ['rows' => [['id' => 1], ['id' => 2]]], 100);
        $cassette = $this->cassette(['status' => 200], [$effect]);

        $projection = CassetteProjector::project($cassette, includeBodies: false);

        $effects = $projection['effects'];
        $this->assertIsArray($effects);
        $effect = $effects[0];
        $this->assertIsArray($effect);
        $result = $effect['result'];
        $this->assertIsArray($result);
        $this->assertSame(['excerpted' => true, 'captured_row_count' => 2], $result['rows']);
    }

    public function testIncludeBodiesReturnsFullEffectRows(): void
    {
        $effect = new Effect(1, EffectKind::Db, 'fp', ['sql' => 'SELECT 1'], ['rows' => [['id' => 1]]], 100);
        $cassette = $this->cassette(['status' => 200], [$effect]);

        $projection = CassetteProjector::project($cassette, includeBodies: true);

        $effects = $projection['effects'];
        $this->assertIsArray($effects);
        $effect = $effects[0];
        $this->assertIsArray($effect);
        $result = $effect['result'];
        $this->assertIsArray($result);
        $this->assertSame([['id' => 1]], $result['rows']);
    }

    public function testSectionsWithNoBodyPassThroughUnchanged(): void
    {
        $cassette = $this->cassette(['status' => 204]);

        $projection = CassetteProjector::project($cassette, includeBodies: false);

        $this->assertSame(['status' => 204], $projection['response']);
        $this->assertSame(['route' => 'orders.update'], $projection['resolved']);
    }
}
