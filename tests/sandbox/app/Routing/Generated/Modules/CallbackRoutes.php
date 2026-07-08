<?php
declare(strict_types=1);

namespace Sandbox\App\Routing\Generated\Modules;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Routes for module Callback (6 routes; built 2025-08-18T17:53:05+00:00)
 */
final class CallbackRoutes {
    /**
     * @param array<string, array{gen_path: string, cut: bool, path: string, opt?: array{parent: string|null, action: mixed}, pattern?: string, match_full?: string, match_partial?: string}> $meta
     */
    public static function addRoutes(RouteCollection $routes, array &$meta): void {
        // DEBUG: name=callbacks raw_path=/callbacks gen=/callbacks module=Callback action=
        $routes->add('callbacks', new Route('/callbacks', [
    '_module' => 'Callback',
], []));
        $meta['callbacks'] = [
    'gen_path' => '/callbacks',
    'cut' => false,
    'path' => '/callbacks',
];
        // DEBUG: name=callbacks.matching_callback raw_path=/callbacks/matching_callback gen=/callbacks/matching_callback module=Callback action=Matching
        $routes->add('callbacks.matching_callback', new Route('/callbacks/matching_callback', [
    '_module' => 'Callback',
    '_action' => 'Matching',
], []));
        $meta['callbacks.matching_callback'] = [
    'gen_path' => '/callbacks/matching_callback',
    'cut' => false,
    'path' => '/callbacks/matching_callback',
];
        // DEBUG: name=callbacks.nonmatching_callback raw_path=/callbacks/nonmatching_callback gen=/callbacks/nonmatching_callback module=Callback action=NonMatching
        $routes->add('callbacks.nonmatching_callback', new Route('/callbacks/nonmatching_callback', [
    '_module' => 'Callback',
    '_action' => 'NonMatching',
], []));
        $meta['callbacks.nonmatching_callback'] = [
    'gen_path' => '/callbacks/nonmatching_callback',
    'cut' => false,
    'path' => '/callbacks/nonmatching_callback',
];
        // DEBUG: name=callbacks.on_not_matched.callback raw_path=/callbacks/on_not_matched/callback gen=/callbacks/on_not_matched/callback module=Callback action=NonMatching
        $routes->add('callbacks.on_not_matched.callback', new Route('/callbacks/on_not_matched/callback', [
    '_module' => 'Callback',
    '_action' => 'NonMatching',
], []));
        $meta['callbacks.on_not_matched.callback'] = [
    'gen_path' => '/callbacks/on_not_matched/callback',
    'cut' => false,
    'path' => '/callbacks/on_not_matched/callback',
];
        // DEBUG: name=callbacks.on_not_matched.callback_stopper raw_path=/callbacks/on_not_matched/callback_never_matches gen=/callbacks/on_not_matched/callback_never_matches module=Callback action=Stopper
        $routes->add('callbacks.on_not_matched.callback_stopper', new Route('/callbacks/on_not_matched/callback_never_matches', [
    '_module' => 'Callback',
    '_action' => 'Stopper',
], []));
        $meta['callbacks.on_not_matched.callback_stopper'] = [
    'gen_path' => '/callbacks/on_not_matched/callback_never_matches',
    'cut' => false,
    'path' => '/callbacks/on_not_matched/callback_never_matches',
];
        // DEBUG: name=callbacks.on_not_matched_stopper raw_path=/callbacks/stopper gen=/callbacks/stopper module=Callback action=Stopper
        $routes->add('callbacks.on_not_matched_stopper', new Route('/callbacks/stopper', [
    '_module' => 'Callback',
    '_action' => 'Stopper',
], []));
        $meta['callbacks.on_not_matched_stopper'] = [
    'gen_path' => '/callbacks/stopper',
    'cut' => false,
    'path' => '/callbacks/stopper',
];
    }
}
