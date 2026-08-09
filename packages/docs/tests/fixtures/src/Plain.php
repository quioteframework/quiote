<?php
namespace Quiote\Fixture\Api;

use Fixture\Other\Collaborator;
use Fixture\Other\Renamed as Alias;
use Fixture\Group\{First, Second as Deux};
use function Fixture\Fn\helper;
use const Fixture\C\LIMIT;

class Plain
{
    use SomeTrait;

    public function run(): void
    {
        $f = function () use ($x) { return $x; };
        $c = Collaborator::class;
        $anon = new class { public function inner(): void {} };
    }
}
