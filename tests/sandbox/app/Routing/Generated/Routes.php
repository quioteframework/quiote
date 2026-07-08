<?php
declare(strict_types=1);

namespace Sandbox\App\Routing\Generated;
use Symfony\Component\Routing\RouteCollection;
use Sandbox\App\Routing\Generated\Modules\DefaultRoutes;
use Sandbox\App\Routing\Generated\Modules\TestWithParamRoutes;
use Sandbox\App\Routing\Generated\Modules\TestPrefixPostfixRoutes;
use Sandbox\App\Routing\Generated\Modules\CallbackRoutes;
use Sandbox\App\Routing\Generated\Modules\PortalRoutes;
use Sandbox\App\Routing\Generated\Modules\BlogRoutes;
use Sandbox\App\Routing\Generated\Modules\CoreRoutes;
use Sandbox\App\Routing\Generated\Modules\AuthRoutes;
use Sandbox\App\Routing\Generated\Modules\UserRoutes;
use Sandbox\App\Routing\Generated\Modules\ItemRoutes;
use Sandbox\App\Routing\Generated\Modules\ProjectRoutes;
use Sandbox\App\Routing\Generated\Modules\TagRoutes;
use Sandbox\App\Routing\Generated\Modules\AdminRoutes;
use Sandbox\App\Routing\Generated\Modules\FileRoutes;
use Sandbox\App\Routing\Generated\Modules\IntegrationRoutes;
use Sandbox\App\Routing\Generated\Modules\ReportRoutes;
use Sandbox\App\Routing\Generated\Modules\ActionsloginmoduleRoutes;

/**
 * Symfony routes aggregate split per module (62 total; built 2025-08-18T17:53:05+00:00)
 * Source: /home/markus/Projects/quiote/tests/sandbox/app/Config/routing.xml
 */
final class Routes {
    /**
     * @return array{RouteCollection, array<string, array{gen_path: string, cut: bool, path: string, opt?: array{parent: string|null, action: mixed}, pattern?: string, match_full?: string, match_partial?: string}>}
     */
    public static function build(): array {
        $routes = new RouteCollection();
        $meta = [];
        DefaultRoutes::addRoutes($routes, $meta);
        TestWithParamRoutes::addRoutes($routes, $meta);
        TestPrefixPostfixRoutes::addRoutes($routes, $meta);
        CallbackRoutes::addRoutes($routes, $meta);
        PortalRoutes::addRoutes($routes, $meta);
        BlogRoutes::addRoutes($routes, $meta);
        CoreRoutes::addRoutes($routes, $meta);
        AuthRoutes::addRoutes($routes, $meta);
        UserRoutes::addRoutes($routes, $meta);
        ItemRoutes::addRoutes($routes, $meta);
        ProjectRoutes::addRoutes($routes, $meta);
        TagRoutes::addRoutes($routes, $meta);
        AdminRoutes::addRoutes($routes, $meta);
        FileRoutes::addRoutes($routes, $meta);
        IntegrationRoutes::addRoutes($routes, $meta);
        ReportRoutes::addRoutes($routes, $meta);
        ActionsloginmoduleRoutes::addRoutes($routes, $meta);
        return [$routes, $meta];
    }
}
